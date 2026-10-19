<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Snapshot\InMemoryCheckpoints;
use App\Entity\Simulation;
use App\Message\RunSimulation;
use App\MessageHandler\RunSimulationHandler;
use App\Service\BulkInserter;
use App\Service\SimulationService;
use App\Service\StateReconstructor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Création asynchrone : create() met en file (QUEUED), le handler joue la partie. */
final class RunSimulationHandlerTest extends KernelTestCase
{
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    private EntityManagerInterface $em;
    private SimulationService $service;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        // Bus enregistreur (équivalent d'un transport in-memory) : rien n'est joué à la création.
        $bus = new class($this->dispatched) implements MessageBusInterface {
            public function __construct(private array &$sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };
        $this->service = new SimulationService($this->em, $c->get(BulkInserter::class), $c->get(StateReconstructor::class), $bus, 1);
    }

    public function testCreateQueuesThenHandlerPlaysTheSameGameAsADirectRun(): void
    {
        $sim = $this->service->create(42, self::FOUR_AI);

        self::assertSame(Simulation::QUEUED, $sim->getStatus());
        self::assertEquals([new RunSimulation((int) $sim->getId())], $this->dispatched);
        self::assertSame([], $this->service->events($sim));

        (new RunSimulationHandler($this->em, $this->service))($this->dispatched[0]);

        $this->em->clear();
        $sim = $this->em->find(Simulation::class, $sim->getId());
        self::assertSame(Simulation::OVER, $sim->getStatus());
        self::assertGreaterThan(1, $sim->getProgress(), 'la progression (tour courant) a été écrite');

        $direct = (new GameEngine(new AgentRegistry(), new InMemoryCheckpoints()))->advance(GameFactory::create(42, self::FOUR_AI), null);
        $hash = static fn (array $events): string => sha1(json_encode($events, JSON_THROW_ON_ERROR));
        $expected = array_map(static fn ($e) => ['type' => $e->type, 'payload' => json_decode(json_encode($e->payload), true)], $direct->events);
        $got = array_map(static fn (array $e) => ['type' => $e['type'], 'payload' => $e['payload']], $this->service->events($sim));
        self::assertSame($hash($expected), $hash($got));
    }

    public function testHandlerMarksTheSimulationFailedWithoutRetry(): void
    {
        $sim = $this->service->create(42, self::FOUR_AI);
        $sim->setControllers([0 => 'agent:inexistant']); // simule un échec dans le worker
        $this->em->flush();

        (new RunSimulationHandler($this->em, $this->service))($this->dispatched[0]);

        $this->em->clear();
        $sim = $this->em->find(Simulation::class, $sim->getId());
        self::assertSame(Simulation::FAILED, $sim->getStatus());
        self::assertNotEmpty($sim->getError());
    }
}
