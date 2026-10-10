<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Couleurs, taxe colorée, X et événement SpellCast enrichi. */
final class ManaCastTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function land(string $id, string $name, string $color): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
    }

    private static function redBolt(string $id): CardInstance
    {
        return new CardInstance($id, 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, false, false, 0, 0, 0, 0, '{R}');
    }

    private static function fireball(string $id): CardInstance
    {
        return new CardInstance($id, 'Fireball', CardInstance::INSTANT, 1, 'damage', 0, false, false, 0, 0, 0, 0, '{X}{R}');
    }

    /** @param list<CardInstance> $hand @param list<CardInstance> $lands */
    private function state(array $hand, array $lands): GameState
    {
        $s = (new ScenarioBuilder())->hand(0, ...$hand)->build(self::PASS);
        $s->player(0)->battlefield = array_merge($s->player(0)->battlefield, $lands);

        return $s;
    }

    /** @return list<CastSpell> */
    private function casts(GameState $s): array
    {
        return array_values(array_filter(
            (new LegalActionGenerator())->for($s, 0),
            static fn (Action $a) => $a instanceof CastSpell,
        ));
    }

    public function testRedSpellNeedsARedSource(): void
    {
        $plains = [self::land('l1', 'Plains', 'W'), self::land('l2', 'Plains', 'W'), self::land('l3', 'Plains', 'W')];
        $this->assertSame([], $this->casts($this->state([self::redBolt('b1')], $plains)));

        $withMountain = [...$plains, self::land('l4', 'Mountain', 'R')];
        $this->assertNotSame([], $this->casts($this->state([self::redBolt('b1')], $withMountain)));
    }

    public function testCastingTapsTheRightLandAndReportsThePayment(): void
    {
        $s = $this->state([self::redBolt('b1')], [self::land('l1', 'Plains', 'W'), self::land('l2', 'Mountain', 'R')]);
        $s->controllers[0] = 'scripted:bolt';
        $result = (new GameEngine(new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')])))->advance($s);

        $cast = null;
        foreach ($result->events as $e) {
            if ($e->type === 'SpellCast') {
                $cast = $e->payload;
            }
        }
        $this->assertNotNull($cast);
        $this->assertSame(['l2'], $cast['tapped']);
        $this->assertSame('{R}', $cast['manaCost']);
        $this->assertSame(['R' => 1], $cast['paid']);
        $this->assertSame(1, $cast['cost']);
        $this->assertSame(17, $s->player(2)->life);
        $this->assertTrue($s->player(0)->pool->isEmpty());
    }

    public function testXSpellOffersOneActionPerPayableXAndDealsXDamage(): void
    {
        $lands = [self::land('l1', 'Mountain', 'R'), self::land('l2', 'Mountain', 'R'), self::land('l3', 'Mountain', 'R'), self::land('l4', 'Mountain', 'R')];
        $s = $this->state([self::fireball('f1')], $lands);

        $xs = array_values(array_unique(array_map(static fn (CastSpell $a) => $a->x, $this->casts($s))));
        $this->assertSame([0, 1, 2, 3], $xs); // {X}{R} avec 4 terrains : X de 0 à 3

        $s->controllers[0] = 'x3';
        $agent = new class () implements \App\Engine\Agent\PlayerAgent {
            private bool $done = false;

            public function decide(GameState $state, int $playerId, array $legal): Action
            {
                if (!$this->done) {
                    foreach ($legal as $a) {
                        if ($a instanceof CastSpell && $a->x === 3 && $a->targets === ['player:2']) {
                            $this->done = true;

                            return $a;
                        }
                    }
                }

                return \App\Engine\Agent\AutoPassAgent::pass($legal, $playerId);
            }
        };
        $result = (new GameEngine(new AgentRegistry(['x3' => $agent])))->advance($s);

        $this->assertSame(17, $s->player(2)->life);
        $this->assertSame(3, array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCast'))[0]->payload['x']);
        $this->assertSame(4, array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCast'))[0]->payload['cost']);
    }

    public function testCommanderTaxIsGenericAndKeepsTheColoredPart(): void
    {
        $commander = new CardInstance('cmd0', 'Test Commander', CardInstance::CREATURE, 3, null, 0, false, true, 3, 3, 0, 0, '{1}{R}{G}');
        $s = (new ScenarioBuilder())->commander(0, $commander, 1)->build(self::PASS);
        $this->assertSame('{3}{R}{G}', (string) $s->player(0)->castCost($commander));
        $this->assertSame(5, $s->player(0)->castCost($commander)->manaValue());
    }
}
