<?php

declare(strict_types=1);

namespace App\Service;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use App\Entity\GameEventRecord;
use App\Entity\Simulation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Colle entre le moteur (PHP pur) et la persistance (Doctrine).
 * Le moteur ne connaît ni Symfony ni la base : tout passe par GameState::toArray()/fromArray().
 */
class SimulationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BulkInserter $bulk,
        private readonly StateReconstructor $reconstructor,
        /** Ancre complète tous les N tours ; le reste se reconstruit par rejeu (voir SparseCheckpoints). */
        private readonly int $checkpointEveryNTurns = 1,
    ) {
    }

    /** @param array<int, string> $controllers id joueur => clé d'agent */
    public function create(int $seed, array $controllers): Simulation
    {
        $this->assertControllers($controllers);

        $sim = new Simulation($seed);
        $this->em->persist($sim);

        return $this->run($sim, GameFactory::create($seed, $controllers), null);
    }

    /** Reprend une branche suspendue avec la décision du joueur humain. */
    public function decide(Simulation $sim, string $actionId): Simulation
    {
        $pending = $sim->getPending();
        if ($sim->getStatus() !== Simulation::WAITING || $pending === null) {
            throw new \DomainException('Cette simulation n\'attend aucune décision.');
        }

        $state = GameState::fromArray($sim->getState());
        // On recalcule les actions légales côté serveur : jamais de confiance au client.
        foreach ((new LegalActionGenerator())->for($state, (int) $pending['player']) as $action) {
            if ($action->id() === $actionId) {
                // Ancre de pause : sans elle, on ne pourrait pas rejouer à travers une décision humaine.
                return $this->run($sim, $state, $action, anchorBefore: [$state->eventSeq, $sim->getState(), $actionId]);
            }
        }

        throw new \InvalidArgumentException("Action illégale : $actionId");
    }

    /**
     * Crée une nouvelle branche à partir du checkpoint le plus proche (<= $atEvent) de $parent.
     *
     * @param array<int, string> $controllerOverrides ex: [1 => 'human:auto'] = « je prends le joueur 1 »
     */
    public function fork(Simulation $parent, int $atEvent, array $controllerOverrides): Simulation
    {
        $checkpoint = $this->reconstructor->at($parent, $atEvent)
            ?? throw new \DomainException("Aucun point de bifurcation à l'événement $atEvent ou avant.");

        $state = GameState::fromArray($checkpoint['state']);
        foreach ($controllerOverrides as $playerId => $key) {
            if (!isset($state->players[(int) $playerId])) {
                throw new \InvalidArgumentException("Joueur inconnu : $playerId");
            }
            $state->controllers[(int) $playerId] = (string) $key;
        }
        $this->assertControllers($state->controllers);

        $child = new Simulation($parent->getSeed());
        $child->setParent($parent);
        $child->setForkEventSeq($state->eventSeq);
        $this->em->persist($child);

        // Ancre au point de bifurcation : état avec les `controllers` surchargés (le rejeu en dépend).
        return $this->run($child, $state, null, anchorBefore: [$state->eventSeq, $state->toArray(), null]);
    }

    /**
     * Journal d'une branche : événements des ancêtres jusqu'au point de bifurcation, puis les siens.
     *
     * @return list<array{seq:int,type:string,payload:array,verbose:bool}>
     */
    public function events(Simulation $sim, int $afterSeq = 0): array
    {
        $chain = [];
        $limit = PHP_INT_MAX;
        for ($s = $sim; $s !== null; $s = $s->getParent()) {
            $chain[] = [$s, $limit];
            $limit = $s->getForkEventSeq() ?? 0;
        }

        $events = [];
        foreach (array_reverse($chain) as [$s, $maxSeq]) {
            $rows = $this->em->createQueryBuilder()
                ->select('e')
                ->from(GameEventRecord::class, 'e')
                ->where('e.simulation = :s AND e.seq <= :max AND e.seq > :after')
                ->orderBy('e.seq', 'ASC')
                ->setParameter('s', $s)
                ->setParameter('max', $maxSeq)
                ->setParameter('after', $afterSeq)
                ->getQuery()->getResult();
            foreach ($rows as $row) {
                $events[] = $row->toArray();
            }
        }

        return $events;
    }

    /** État sérialisé du checkpoint le plus proche (<= $seq) : sert à l'endpoint GET /state?at=. */
    public function stateAt(Simulation $sim, int $seq): ?array
    {
        return $this->reconstructor->at($sim, $seq);
    }

    /**
     * @param array{0:int,1:array,2:?string}|null $anchorBefore ancre de départ [eventSeq, état, actionId] écrite avec le run (son tour est déjà ancré ; null = création, premier checkpoint ancré)
     */
    private function run(Simulation $sim, GameState $state, ?Action $decision, ?array $anchorBefore = null): Simulation
    {
        $checkpoints = new SparseCheckpoints($this->checkpointEveryNTurns, $anchorBefore !== null ? $state->turn : null);
        $result = (new GameEngine(new AgentRegistry(), $checkpoints))->advance($state, $decision);

        // Une seule transaction (tout ou rien). Les événements et checkpoints sont écrits en masse, hors
        // UnitOfWork (des milliers d'entités + un INSERT par ligne seraient très lents sur MySQL).
        $this->em->wrapInTransaction(function () use ($sim, $state, $result, $checkpoints, $anchorBefore): void {
            $this->em->persist($sim);
            $this->em->flush(); // obtient l'id de la simulation

            $this->bulk->insertEvents((int) $sim->getId(), $result->events);
            if ($anchorBefore !== null) {
                [$seq, $anchorState, $actionId] = $anchorBefore;
                $actionId === null
                    ? $this->bulk->insertCheckpoints((int) $sim->getId(), [$seq => $anchorState])
                    : $this->bulk->anchorDecision((int) $sim->getId(), $seq, $anchorState, $actionId);
            }
            $this->bulk->insertCheckpoints((int) $sim->getId(), $checkpoints->states);

            $sim->setState($state->toArray());
            $sim->setPending($result->pending?->toArray());
            $sim->setStatus($result->over ? Simulation::OVER : ($result->pending !== null ? Simulation::WAITING : Simulation::RUNNING));
            $this->em->flush();
        });

        return $sim;
    }

    /** @param array<int, string> $controllers */
    private function assertControllers(array $controllers): void
    {
        $known = (new AgentRegistry())->keys();
        foreach ($controllers as $playerId => $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException("Agent inconnu pour le joueur $playerId : $key");
            }
        }
    }
}
