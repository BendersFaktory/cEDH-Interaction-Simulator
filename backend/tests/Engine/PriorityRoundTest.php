<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Snapshot\InMemoryCheckpoints;
use PHPUnit\Framework\TestCase;

/**
 * Valident l'exigence centrale : à chaque action, TOUTE la table repasse la priorité,
 * y compris l'IA, qui peut donc contrer.
 */
final class PriorityRoundTest extends TestCase
{
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    private function registry(): AgentRegistry
    {
        return new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')]);
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function types(array $events, string ...$only): array
    {
        return array_values(array_map(
            static fn (GameEvent $e) => $e->type,
            array_filter($events, static fn (GameEvent $e) => in_array($e->type, $only, true)),
        ));
    }

    public function testBoltResolvesWhenNobodyCanCounter(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'scripted:bolt', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);

        $result = (new GameEngine($this->registry()))->advance($s);

        $this->assertSame(17, $s->player(2)->life);
        $this->assertContains('DamageDealt', $this->types($result->events, 'DamageDealt'));
    }

    public function testAiCountersTheSpellDuringTheRound(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)
            ->build([0 => 'scripted:bolt', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);

        $result = (new GameEngine($this->registry()))->advance($s);

        // Le Bolt est contré : le joueur 2 ne perd rien, les cartes finissent au cimetière.
        $this->assertSame(20, $s->player(2)->life);
        $this->assertSame(['SpellCast', 'SpellCast', 'SpellResolved', 'SpellCountered'],
            $this->types($result->events, 'SpellCast', 'SpellResolved', 'SpellCountered'));
        $this->assertSame('Lightning Bolt', $s->player(0)->graveyard[0]->name);
        $this->assertSame('Counterspell', $s->player(1)->graveyard[0]->name);
    }

    public function testPassesAreResetAndEveryoneRepassesAfterTheCounter(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)
            ->build([0 => 'scripted:bolt', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);

        $result = (new GameEngine($this->registry()))->advance($s);

        // Entre le Counterspell lancé et sa résolution : joueur 1, 2, puis 0 passent, dans l'ordre du tour.
        $seenCounterCast = false;
        $passes = [];
        foreach ($result->events as $e) {
            if ($e->type === 'SpellCast' && $e->payload['card']['name'] === 'Counterspell') {
                $seenCounterCast = true;
                continue;
            }
            if ($seenCounterCast && $e->type === 'PriorityPassed') {
                $passes[] = $e->payload['player'];
            }
            if ($seenCounterCast && $e->type === 'SpellResolved') {
                break;
            }
        }
        $this->assertSame([1, 2, 0], $passes);
    }

    public function testEveryPlayerGetsPriorityAfterEachSpell(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'scripted:bolt', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);

        $result = (new GameEngine($this->registry()))->advance($s);

        $holders = [];
        $afterCast = false;
        foreach ($result->events as $e) {
            if ($e->type === 'SpellCast') {
                $afterCast = true;
                continue;
            }
            if ($afterCast && $e->type === 'PriorityGranted') {
                $holders[] = $e->payload['holder'];
            }
            if ($afterCast && $e->type === 'SpellResolved') {
                break;
            }
        }
        $this->assertSame([0, 1, 2], $holders);
    }

    public function testAutoPassIsStillJournaled(): void
    {
        $s = (new ScenarioBuilder())
            ->build([0 => 'ai:pass', 1 => 'human:auto', 2 => 'ai:pass']);

        $result = (new GameEngine(new AgentRegistry()))->advance($s, null);

        // Tant que le joueur 1 n'a rien de jouable, il auto-passe, mais ses PriorityPassed sont bien dans le journal.
        $passedBy1 = array_filter($result->events, static fn (GameEvent $e) => $e->type === 'PriorityPassed' && $e->payload['player'] === 1);
        $this->assertNotEmpty($passedBy1);

        // Il ne s'arrête qu'au moment où il a une action utile (ici : un terrain à jouer à son propre tour).
        $this->assertNotNull($result->pending);
        $this->assertSame(1, $result->pending->player);
    }

    public function testHumanSuspendsThenResumesWithDecision(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'human', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);
        $engine = new GameEngine(new AgentRegistry());

        $first = $engine->advance($s);
        $this->assertNotNull($first->pending);
        $this->assertSame(0, $first->pending->player);

        $cast = null;
        foreach ($first->pending->legalActions as $a) {
            if ($a instanceof CastSpell && $a->targets === ['player:2']) {
                $cast = $a;
            }
        }
        $this->assertNotNull($cast);

        // On rejoue la décision : la table repasse, puis le moteur redemande une décision à l'humain.
        $second = $engine->advance($s, $cast);
        $this->assertSame(['SpellCast'], $this->types($second->events, 'SpellCast'));
        $this->assertNotNull($second->pending);

        $third = $engine->advance($s, new PassPriority(0));
        $this->assertContains('DamageDealt', $this->types($third->events, 'DamageDealt'));
        $this->assertSame(17, $s->player(2)->life);
    }

    public function testIllegalActionIsRejected(): void
    {
        $s = (new ScenarioBuilder())
            ->build([0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass']);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $this->expectException(\InvalidArgumentException::class);
        $engine->advance($s, new CastSpell(0, 'carte_inexistante', ['player:1']));
    }

    public function testSameSeedGivesIdenticalGames(): void
    {
        $run = function (): array {
            $s = GameFactory::create(7, self::FOUR_AI);
            $r = (new GameEngine(new AgentRegistry()))->advance($s);

            return array_map(static fn (GameEvent $e) => $e->toArray(), $r->events);
        };

        $this->assertSame($run(), $run());
    }

    /**
     * Cœur du fork : restaurer un snapshot (aller-retour JSON) et reprendre doit produire
     * exactement les mêmes événements que la partie d'origine.
     */
    public function testResumingFromACheckpointReplaysTheSameGame(): void
    {
        $controllers = self::FOUR_AI;

        $checkpoints = new InMemoryCheckpoints();
        $s = GameFactory::create(11, $controllers);
        $full = (new GameEngine(new AgentRegistry(), $checkpoints))->advance($s);
        $fullEvents = array_map(static fn (GameEvent $e) => $e->toArray(), $full->events);

        // Un checkpoint situé au milieu de la partie.
        $seqs = array_keys($checkpoints->states);
        $at = $seqs[intdiv(count($seqs), 2)];

        $restored = GameState::fromArray(json_decode(json_encode($checkpoints->states[$at], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($at, $restored->eventSeq);

        $resumed = (new GameEngine(new AgentRegistry()))->advance($restored);
        $resumedEvents = array_map(static fn (GameEvent $e) => $e->toArray(), $resumed->events);

        $expectedTail = array_values(array_filter($fullEvents, static fn (array $e) => $e['seq'] > $at));
        $this->assertSame($expectedTail, $resumedEvents);
        $this->assertEquals($s->toArray(), $restored->toArray());
    }

    public function testForkLetsTheHumanTakeOverAtACheckpoint(): void
    {
        $controllers = self::FOUR_AI;
        $checkpoints = new InMemoryCheckpoints();
        $s = GameFactory::create(11, $controllers);
        (new GameEngine(new AgentRegistry(), $checkpoints))->advance($s);

        $seqs = array_keys($checkpoints->states);
        $at = $seqs[intdiv(count($seqs), 3)];

        $fork = GameState::fromArray($checkpoints->states[$at]);
        $fork->controllers[1] = 'human'; // « à partir de là, je prends le joueur 1 »

        $result = (new GameEngine(new AgentRegistry()))->advance($fork);

        $this->assertNotNull($result->pending);
        $this->assertSame(1, $result->pending->player);
        $this->assertGreaterThanOrEqual($at, $result->pending->eventSeq);
    }
}
