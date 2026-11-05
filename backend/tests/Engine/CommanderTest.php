<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\PlayerState;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Règles propres au format Commander à 4 joueurs. */
final class CommanderTest extends TestCase
{
    private const SEATS = [0 => 'scripted:cmd', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    private function registry(): AgentRegistry
    {
        return new AgentRegistry(['scripted:cmd' => new ScriptedAgent('Test Commander', null)]);
    }

    /** @param list<GameEvent> $events */
    private function first(array $events, string $type): ?GameEvent
    {
        foreach ($events as $e) {
            if ($e->type === $type) {
                return $e;
            }
        }

        return null;
    }

    public function testGameStartsInCommanderFormat(): void
    {
        $s = GameFactory::create(3, [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);

        $this->assertSame(4, count($s->players));
        foreach ($s->players as $p) {
            $this->assertSame(40, $p->life);
            $this->assertSame(1, count($p->command));
            $this->assertSame(true, $p->command[0]->commander);
            $this->assertSame(7, count($p->hand));
        }
    }

    public function testCommanderIsCastFromCommandZoneAndPaysItsCost(): void
    {
        $s = (new ScenarioBuilder())
            ->commander(0, ScenarioBuilder::commanderCard('cmd0'))->untappedLands(0, 5)
            ->build(self::SEATS);

        $result = (new GameEngine($this->registry()))->advance($s);

        $cast = $this->first($result->events, 'SpellCast');
        $this->assertNotNull($cast);
        $this->assertSame(true, $cast->payload['fromCommand']);
        $this->assertSame(3, $cast->payload['cost']);
        $this->assertSame(0, count($s->player(0)->command));
        $this->assertSame('Test Commander', $s->player(0)->battlefield[array_key_last($s->player(0)->battlefield)]->name);
        $this->assertSame(1, $s->player(0)->commanderCasts['cmd0']);
    }

    public function testCommanderTaxAddsTwoPerPreviousCast(): void
    {
        $generator = new LegalActionGenerator();
        $canCast = static function (int $lands, int $previousCasts) use ($generator): bool {
            $s = (new ScenarioBuilder())
                ->commander(0, ScenarioBuilder::commanderCard('cmd0'), $previousCasts)->untappedLands(0, $lands)
                ->build([0 => 'ai:pass', 1 => 'ai:pass']);

            foreach ($generator->for($s, 0) as $action) {
                if ($action instanceof CastSpell && $action->cardId === 'cmd0') {
                    return true;
                }
            }

            return false;
        };

        $this->assertSame(true, $canCast(3, 0));   // coût 3
        $this->assertSame(false, $canCast(4, 1));  // coût 5 : 4 terrains ne suffisent pas
        $this->assertSame(true, $canCast(5, 1));   // coût 5
        $this->assertSame(false, $canCast(6, 2));  // coût 7
        $this->assertSame(true, $canCast(7, 2));
    }

    public function testCounteredCommanderReturnsToCommandZone(): void
    {
        $s = (new ScenarioBuilder())
            ->commander(0, ScenarioBuilder::commanderCard('cmd0'))->untappedLands(0, 3)
            ->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)
            ->build(self::SEATS);

        $result = (new GameEngine($this->registry()))->advance($s);

        $countered = $this->first($result->events, 'SpellCountered');
        $this->assertNotNull($countered);
        $this->assertSame('graveyard', $countered->payload['destination']); // CR 903.9a : cimetière, puis SBA…
        $this->assertNotNull($this->first($result->events, 'CommanderReturned'));
        $this->assertSame(1, count($s->player(0)->command));  // …retourné en zone de commandement…
        $this->assertSame([], array_filter($s->player(0)->graveyard, static fn ($c) => $c->commander)); // …et pas au cimetière (d'autres cartes y arrivent par la défausse)
        // Et la taxe s'applique bien : le lancement contré compte.
        $this->assertSame(1, $s->player(0)->commanderCasts['cmd0']);
        $this->assertSame(5, $s->player(0)->castCost($s->player(0)->command[0])->manaValue());
    }

    public function testCommanderDamageEliminatesAtTwentyOne(): void
    {
        $s = (new ScenarioBuilder())->life(40)
            ->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->player(1)->commanderDamage['0:cmd0'] = PlayerState::COMMANDER_DAMAGE_LIMIT;

        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $lost = array_values(array_filter(
            $result->events,
            static fn (GameEvent $e) => $e->type === 'PlayerLost' && $e->payload['player'] === 1,
        ));
        $this->assertSame('commander_damage', $lost[0]->payload['reason']);
        $this->assertSame(40, $s->player(1)->life); // éliminé sans avoir perdu de PV
    }

    public function testFourPlayersAllGetPriorityInTurnOrderAfterASpell(): void
    {
        $s = (new ScenarioBuilder())->life(40)
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'scripted:bolt', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $registry = new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')]);

        $result = (new GameEngine($registry))->advance($s);

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
        $this->assertSame([0, 1, 2, 3], $holders);
        $this->assertSame(37, $s->player(2)->life);
    }

    public function testYouCanSitAtTheTableFromTheStart(): void
    {
        $seats = [0 => 'human:auto', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];
        $s = GameFactory::create(5, $seats);
        $engine = new GameEngine(new AgentRegistry());

        $first = $engine->advance($s);

        // Tu es interrogé dès que tu as quelque chose à faire (ex: jouer un terrain à ton tour).
        $this->assertNotNull($first->pending);
        $this->assertSame(0, $first->pending->player);

        // Le serveur ne ferait confiance à aucune action hors de la liste légale : on prend une action proposée.
        $this->assertNotEmpty($first->pending->legalActions);
        $second = $engine->advance($s, $first->pending->legalActions[0]);
        $this->assertNotEmpty($second->events);
    }

    public function testCheckpointsOnlyAtRoundStartOrWhenStackIsNotEmpty(): void
    {
        $s = GameFactory::create(11, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
        $checkpoints = new \App\Engine\Snapshot\InMemoryCheckpoints();
        $result = (new GameEngine(new AgentRegistry(), $checkpoints))->advance($s);

        $flagged = [];
        $grants = 0;
        foreach ($result->events as $e) {
            if ($e->type === 'PriorityGranted') {
                $grants++;
                if ($e->payload['checkpoint']) {
                    $flagged[$e->seq] = true;
                }
            }
        }

        // Le drapeau du payload et les snapshots réellement pris coïncident exactement…
        $this->assertSame(array_keys($flagged), array_keys($checkpoints->states));
        // …et on en prend nettement moins qu'un par octroi de priorité.
        $this->assertGreaterThan(count($flagged), $grants);
    }
}
