<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Snapshot\InMemoryCheckpoints;
use App\Entity\Checkpoint;
use App\Entity\Simulation;
use App\Service\SimulationService;
use App\Service\StateReconstructor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'acceptation des checkpoints épars : tout état reconstruit par rejeu depuis une ancre doit être
 * IDENTIQUE (toArray(), RNG compris, ordre des clés compris) à l'ancien snapshot dense d'InMemoryCheckpoints.
 */
final class StateReconstructorTest extends KernelTestCase
{
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];
    private const HUMAN_J0 = [0 => 'human:auto', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    private EntityManagerInterface $em;
    private SimulationService $simulations;
    private StateReconstructor $reconstructor;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->simulations = $c->get(SimulationService::class);
        $this->reconstructor = $c->get(StateReconstructor::class);
    }

    private static function json(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private function anchorCount(Simulation $sim): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(c) FROM ' . Checkpoint::class . ' c WHERE c.simulation = :s')
            ->setParameter('s', $sim)->getSingleScalarResult();
    }

    /** @param array<int, array> $dense eventSeq => état de référence */
    private function assertReconstructsEverything(Simulation $sim, array $dense): void
    {
        foreach ($dense as $seq => $expected) {
            $got = $this->reconstructor->at($sim, $seq);
            self::assertNotNull($got, "Checkpoint $seq introuvable");
            self::assertSame($seq, $got['eventSeq']);
            self::assertSame(self::json($expected), self::json($got['state']), "État reconstruit différent au checkpoint $seq");
        }
        self::assertNotEmpty($dense);
    }

    private function pickAction(GameState $state, int $player, int $step): Action
    {
        $legal = (new LegalActionGenerator())->for($state, $player);

        return $step % 3 === 2 ? $legal[0] : $legal[array_key_last($legal)];
    }

    private function denseFor(int $seed, array $controllers): InMemoryCheckpoints
    {
        $dense = new InMemoryCheckpoints();
        (new GameEngine(new AgentRegistry(), $dense))->advance(GameFactory::create($seed, $controllers));

        return $dense;
    }

    public function testQuatreIaChaqueCheckpointReconstruitEstIdentiqueAuSnapshotDense(): void
    {
        $dense = $this->denseFor(42, self::FOUR_AI);
        $sim = $this->simulations->create(42, self::FOUR_AI);

        $this->assertReconstructsEverything($sim, $dense->states);
        self::assertLessThan(count($dense->states) / 4, $this->anchorCount($sim), 'Beaucoup moins d\'ancres que de checkpoints');
    }

    public function testUnSeqEntreDeuxCheckpointsRenvoieLePrecedent(): void
    {
        $sim = $this->simulations->create(42, self::FOUR_AI);
        $seqs = array_keys($this->denseFor(42, self::FOUR_AI)->states);

        self::assertSame($seqs[40], $this->reconstructor->at($sim, $seqs[41] - 1)['eventSeq']);
        self::assertNull($this->reconstructor->at($sim, 0));
    }

    public function testPartieAvecDecisionsHumainesReconstruitEgalementTout(): void
    {
        $dense = new InMemoryCheckpoints();
        $engine = new GameEngine(new AgentRegistry(), $dense);
        $ref = GameFactory::create(7, self::HUMAN_J0);
        $engine->advance($ref);

        $sim = $this->simulations->create(7, self::HUMAN_J0);
        $decisions = 0;
        while ($sim->getStatus() === Simulation::WAITING && $decisions < 80) {
            $action = $this->pickAction($ref, (int) $sim->getPending()['player'], $decisions);
            $sim = $this->simulations->decide($sim, $action->id());
            $engine->advance($ref, $action);
            ++$decisions;
        }

        self::assertGreaterThan(10, $decisions, 'Plusieurs pauses humaines traversées');
        $this->assertReconstructsEverything($sim, $dense->states);
    }

    public function testForkDepuisUneBrancheDejaForkee(): void
    {
        $dense = $this->denseFor(42, self::FOUR_AI);
        $parent = $this->simulations->create(42, self::FOUR_AI);
        $at = array_keys($dense->states)[300];

        // Fork n°1 : J1 devient humain.
        $child = $this->simulations->fork($parent, $at, [1 => 'human:auto']);
        $ref = new InMemoryCheckpoints();
        $refEngine = new GameEngine(new AgentRegistry(), $ref);
        $refState = GameState::fromArray($dense->states[$at]);
        $refState->controllers[1] = 'human:auto';
        $refEngine->advance($refState);

        for ($i = 0; $i < 6 && $child->getStatus() === Simulation::WAITING; ++$i) {
            $action = $this->pickAction($refState, (int) $child->getPending()['player'], $i);
            $child = $this->simulations->decide($child, $action->id());
            $refEngine->advance($refState, $action);
        }
        $this->assertReconstructsEverything($child, $ref->states);

        // Fork n°2 depuis le fils.
        $childSeqs = array_keys($ref->states);
        $g = $childSeqs[intdiv(count($childSeqs), 2)];
        $grand = $this->simulations->fork($child, $g, [2 => 'human:auto']);
        $grandRef = new InMemoryCheckpoints();
        $grandState = GameState::fromArray($ref->states[$g]);
        $grandState->controllers[2] = 'human:auto';
        (new GameEngine(new AgentRegistry(), $grandRef))->advance($grandState);

        $this->assertReconstructsEverything($grand, $grandRef->states);
        $before = $this->reconstructor->at($grand, $g - 1);
        self::assertNotNull($before);
        self::assertLessThan($g, $before['eventSeq']);
    }
}
