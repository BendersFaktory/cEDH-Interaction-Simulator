<?php

declare(strict_types=1);

namespace App\Service;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Snapshot\CheckpointListener;
use App\Entity\Checkpoint;
use App\Entity\Simulation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retrouve le checkpoint le plus proche (<= seq) d'une branche en rejouant le moteur depuis une ancre.
 * Le moteur est déterministe (RNG dans GameState, agents sans état) : le rejeu ne persiste rien.
 */
class StateReconstructor
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array{eventSeq:int,state:array}|null */
    public function at(Simulation $sim, int $seq): ?array
    {
        // On remonte les ancres une à une : une ancre de pause humaine n'est pas forcément un checkpoint,
        // son rejeu peut donc ne rien trouver <= seq (le bon checkpoint est alors avant elle).
        $limit = $seq;
        while (($anchor = $this->anchorAtOrBefore($sim, $limit)) !== null) {
            $found = $this->replay($anchor, $seq);
            if ($found !== null) {
                return $found;
            }
            $limit = $anchor->getEventSeq() - 1;
        }

        return null;
    }

    /** @return array{eventSeq:int,state:array}|null */
    private function replay(Checkpoint $anchor, int $target): ?array
    {
        $startState = $anchor->getState();
        $decision = $anchor->getDecision();
        // Une ancre de tour/fork est un checkpoint ; une ancre avec décision (pause) n'est pas garantie de l'être.
        $best = $decision === null ? ['eventSeq' => $anchor->getEventSeq(), 'state' => $startState] : null;
        if ($decision === null && $anchor->getEventSeq() === $target) {
            return $best;
        }

        $state = GameState::fromArray($startState);
        $action = $decision === null ? null : $this->findAction($state, $decision);

        $listener = new class($target) implements CheckpointListener {
            /** @var array{eventSeq:int,state:array}|null */
            public ?array $best = null;

            public function __construct(private readonly int $target)
            {
            }

            public function onCheckpoint(GameState $state): void
            {
                if ($state->eventSeq > $this->target) {
                    throw new ReplayStop();
                }
                $this->best = ['eventSeq' => $state->eventSeq, 'state' => $state->toArray()];
            }
        };

        try {
            (new GameEngine(new AgentRegistry(), $listener))->advance($state, $action);
        } catch (ReplayStop|DecisionRequired) {
            // Cible dépassée, ou branche suspendue : on garde le dernier checkpoint rencontré.
        }

        return $listener->best ?? $best;
    }

    private function findAction(GameState $state, string $actionId): Action
    {
        $pending = $state->priority->holder;
        foreach ((new LegalActionGenerator())->for($state, $pending) as $action) {
            if ($action->id() === $actionId) {
                return $action;
            }
        }
        throw new \LogicException("Ancre incohérente : l'action $actionId n'est plus légale au rejeu.");
    }

    /** Ancre la plus proche (<= seq) ; avant la bifurcation, elle vit chez l'ancêtre. */
    private function anchorAtOrBefore(Simulation $sim, int $seq): ?Checkpoint
    {
        $own = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Checkpoint::class, 'c')
            ->where('c.simulation = :s AND c.eventSeq <= :seq')
            ->orderBy('c.eventSeq', 'DESC')
            ->setMaxResults(1)
            ->setParameter('s', $sim)
            ->setParameter('seq', $seq)
            ->getQuery()->getOneOrNullResult();

        if ($own !== null || $sim->getParent() === null) {
            return $own;
        }

        return $this->anchorAtOrBefore($sim->getParent(), min($seq, $sim->getForkEventSeq() ?? 0));
    }
}
