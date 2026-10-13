<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Decision\DecisionRequest;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Event\GameEvent;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use PHPUnit\Framework\TestCase;

/**
 * Règle 514.3a en PARTIE RÉELLE (4 IA, pas de scénario construit) : la graine 956 voit un trigger de défausse
 * (Cendrier des Regrets) attendre au cleanup, ce qui ouvre une ronde de priorité complète.
 */
final class CleanupRoundTest extends TestCase
{
    private const SEED = 956;
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    /** @return array{GameState, list<GameEvent>} */
    private function fullRun(): array
    {
        $s = GameFactory::create(self::SEED, self::FOUR_AI);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertTrue($result->over);

        return [$s, $result->events];
    }

    /**
     * @param list<GameEvent> $events
     *
     * @return array{int, int} indices des deux PhaseChanged cleanup d'un même tour
     */
    private function doubleCleanup(array $events): array
    {
        $last = null; // [tour, index]
        foreach ($events as $i => $e) {
            if ($e->type !== 'PhaseChanged' || $e->payload['phase'] !== 'cleanup') {
                continue;
            }
            if ($last !== null && $last[0] === $e->payload['turn']) {
                return [$last[1], $i];
            }
            $last = [$e->payload['turn'], $i];
        }
        $this->fail('Aucune ronde de cleanup sur la graine ' . self::SEED);
    }

    public function testRealGameOpensAPriorityRoundDuringCleanup(): void
    {
        [, $events] = $this->fullRun();
        [$first, $second] = $this->doubleCleanup($events);
        $turn = $events[$first]->payload['turn'];

        $span = array_slice($events, $first + 1, $second - $first - 1);
        $types = array_map(static fn (GameEvent $e) => $e->type, $span);

        // Un trigger de défausse a été mis sur la pile pendant le cleanup, puis résolu dans la ronde.
        $this->assertContains('CardDiscarded', $types);
        $this->assertContains('AbilityResolved', $types);
        $checkpoints = array_filter(
            $span,
            static fn (GameEvent $e) => $e->type === 'PriorityGranted' && $e->payload['checkpoint'] === true,
        );
        $this->assertNotEmpty($checkpoints, 'La ronde de cleanup doit offrir un checkpoint (pile non vide).');
        // Aucune priorité sautée : chaque octroi est suivi d'un passage.
        $granted = array_filter($span, static fn (GameEvent $e) => $e->type === 'PriorityGranted');
        $passed = array_filter($span, static fn (GameEvent $e) => $e->type === 'PriorityPassed');
        $this->assertSame(count($granted), count($passed));
        $this->assertGreaterThanOrEqual(8, count($granted));

        // Second cleanup du même tour : la défausse n'est pas rejouée, puis le tour suivant commence.
        $after = array_slice($events, $second + 1, 3);
        $this->assertSame('PhaseChanged', $after[0]->type);
        $this->assertSame('untap', $after[0]->payload['phase']);
        $this->assertSame($turn + 1, $after[0]->payload['turn']);
        $this->assertNotContains('CardDiscarded', array_map(static fn (GameEvent $e) => $e->type, $after));
    }

    public function testResumeInsideCleanupRoundEqualsContinuousRun(): void
    {
        [$expected, $expectedEvents] = $this->fullRun();

        // Même partie, mais le siège 'ai:heuristic' suspend le moteur à la première priorité d'une ronde de cleanup.
        $pauser = new class () implements PlayerAgent {
            public bool $paused = false;
            private readonly HeuristicAgent $inner;

            public function __construct()
            {
                $this->inner = new HeuristicAgent();
            }

            public function decide(GameState $state, int $playerId, array $legal): Action
            {
                if (!$this->paused && $state->cleanupRound) {
                    $this->paused = true;
                    throw new DecisionRequired(DecisionRequest::from($state, $playerId, $legal));
                }

                return $this->inner->decide($state, $playerId, $legal);
            }
        };
        $s = GameFactory::create(self::SEED, self::FOUR_AI);
        $first = (new GameEngine(new AgentRegistry(['ai:heuristic' => $pauser])))->advance($s);

        $this->assertTrue($pauser->paused);
        $this->assertFalse($first->over);
        $this->assertNotNull($first->pending);
        $this->assertTrue($s->cleanupRound);

        // Reprise depuis l'état sérialisé, avec un moteur neuf.
        $resumed = GameState::fromArray(json_decode(json_encode($s->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertTrue($resumed->cleanupRound);
        $second = (new GameEngine(new AgentRegistry()))->advance($resumed);
        $this->assertTrue($second->over);

        $this->assertSame(
            json_encode($expected->toArray()),
            json_encode($resumed->toArray()),
            'La reprise après pause en plein cleanup doit donner le même état final qu\'un run continu.',
        );
        $ser = static fn (array $events) => json_encode(array_map(static fn (GameEvent $e) => [$e->seq, $e->type, $e->payload], $events));
        $this->assertSame($ser($expectedEvents), $ser([...$first->events, ...$second->events]));
    }
}
