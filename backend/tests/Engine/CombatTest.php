<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Combat à 4 joueurs : déclarations séquentielles, blocage, dégâts, mort des créatures, dégâts de commandant. */
final class CombatTest extends TestCase
{
    private const TABLE = [0 => 'scripted:atk', 1 => 'scripted:blk', 2 => 'ai:pass', 3 => 'ai:pass'];

    private function registry(?int $attackTarget = 1, bool $p1Blocks = false): AgentRegistry
    {
        return new AgentRegistry([
            'scripted:atk' => new CombatScriptAgent($attackTarget),
            'scripted:blk' => new CombatScriptAgent(null, $p1Blocks),
        ]);
    }

    private function startAtCombat(ScenarioBuilder $b): GameState
    {
        $s = $b->build(self::TABLE);
        $s->phase = Phase::BeginCombat;

        return $s;
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    public function testSummoningSickCreatureCannotAttack(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2, false, 1)->build(self::TABLE);
        $this->assertSame([], (new LegalActionGenerator())->attackActions($s, 0));

        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2)->build(self::TABLE);
        $this->assertCount(3, (new LegalActionGenerator())->attackActions($s, 0), 'une action par adversaire');
    }

    public function testUnblockedAttackerHitsOnlyTheChosenPlayerAndTaps(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'c1', 3, 3));
        $result = (new GameEngine($this->registry()))->advance($s);

        $hit = $this->of($result->events, 'DamageDealt')[0];
        $this->assertSame('player:1', $hit->payload['target']);
        $this->assertSame(17, $hit->payload['life']);
        $this->assertTrue($hit->payload['combat']);
        $declared = $this->of($result->events, 'AttackerDeclared')[0];
        $this->assertSame(['player' => 0, 'card' => 'c1', 'defender' => 1, 'tapped' => true], $declared->payload);
    }

    public function testDeclarationLoopLetsAPlayerDeclareEveryAttacker(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'c1', 1, 1)->creature(0, 'c2', 1, 1)->creature(0, 'c3', 1, 1));
        $result = (new GameEngine($this->registry()))->advance($s);

        $this->assertCount(3, array_slice($this->of($result->events, 'AttackerDeclared'), 0, 3));
        $firstDamageTurn = array_filter($this->of($result->events, 'DamageDealt'), static fn ($e) => $e->payload['combat']);
        $this->assertSame([19, 18, 17], array_map(static fn ($e) => $e->payload['life'], array_slice(array_values($firstDamageTurn), 0, 3)));
    }

    public function testBlockerTradesAndPlayerTakesNothing(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'atk', 3, 3)->creature(1, 'blk', 2, 2));
        $result = (new GameEngine($this->registry(1, true)))->advance($s);

        $block = $this->of($result->events, 'BlockerDeclared')[0];
        $this->assertSame(['player' => 1, 'blocker' => 'blk', 'attacker' => 'atk'], $block->payload);
        $died = $this->of($result->events, 'CreatureDied');
        $this->assertSame('blk', $died[0]->payload['card']['id']);
        $this->assertSame([], array_filter($this->of($result->events, 'DamageDealt'), static fn ($e) => $e->payload['combat'] && $e->payload['target'] === 'player:1'));
        $this->assertSame('Test Creature', $s->player(1)->graveyard[0]->name);
        $this->assertSame(0, $s->player(0)->battlefield[0]->damage, 'dégâts effacés au cleanup');
    }

    public function testOnlyThePlayerBeingAttackedMayBlockAndOnlyHisAttackers(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'atk', 2, 2)->creature(1, 'b1', 1, 1)->creature(2, 'b2', 1, 1)->build(self::TABLE);
        $s->combat->declaring = 'blockers';
        $s->combat->attackers = ['atk' => 1];
        $legal = new LegalActionGenerator();

        $this->assertSame(['block:1:b1:atk'], array_map(static fn ($a) => $a->id(), $legal->blockActions($s, 1)));
        $this->assertSame([], $legal->blockActions($s, 2));
    }

    public function testNoAttackerMeansNoCombatStepsBeyondBeginAndEnd(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'c1', 3, 3, false, 1));
        $result = (new GameEngine($this->registry()))->advance($s);

        $phases = array_map(static fn ($e) => $e->payload['phase'], array_slice($this->of($result->events, 'PhaseChanged'), 0, 3));
        $this->assertSame(['end_combat', 'main2', 'end'], $phases);
    }

    public function testEveryCombatStepOpensAFullPriorityRound(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'c1', 3, 3));
        $result = (new GameEngine($this->registry()))->advance($s);

        $phase = null;
        $granted = [];
        foreach ($result->events as $e) {
            if ($e->type === 'PhaseChanged') {
                $phase = $e->payload['phase'];
            } elseif ($e->type === 'PriorityGranted' && $e->payload['passed'] === []) {
                $granted[$phase] = ($granted[$phase] ?? 0) + 1;
            }
            if ($phase === 'end') {
                break;
            }
        }
        foreach (['declare_attackers', 'declare_blockers', 'combat_damage'] as $step) {
            $this->assertArrayHasKey($step, $granted, "$step doit ouvrir une ronde de priorité");
        }
    }

    public function testCommanderDiesInCombatAndGoesBackToTheCommandZone(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'cmd', 3, 3, true)->creature(1, 'wall', 4, 4));
        $result = (new GameEngine($this->registry(1, true)))->advance($s);

        $died = $this->of($result->events, 'CreatureDied');
        $this->assertSame('cmd', $died[0]->payload['card']['id']);
        $this->assertSame('command', $died[0]->payload['destination']);
        $this->assertSame('cmd', $s->player(0)->command[0]->id);
        $this->assertSame([], array_filter($s->player(0)->graveyard, static fn ($c) => $c->commander), 'jamais au cimetière');
    }

    public function testCommanderDamageAccumulatesAndEliminatesAtTwentyOne(): void
    {
        $s = $this->startAtCombat((new ScenarioBuilder())->creature(0, 'cmd', 3, 3, true));
        $s->player(1)->commanderDamage['0:cmd'] = 18;
        $result = (new GameEngine($this->registry()))->advance($s);

        $hit = $this->of($result->events, 'DamageDealt')[0];
        $this->assertSame(['key' => '0:cmd', 'total' => 21], $hit->payload['commanderDamage']);
        $lost = $this->of($result->events, 'PlayerLost')[0];
        $this->assertSame(['player' => 1, 'reason' => 'commander_damage'], $lost->payload);
        $this->assertSame(17, $hit->payload['life'], 'le joueur était encore en vie par ses PV');
    }

    public function testHumanIsAskedToDeclareThenResumesWithTheChoice(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2)->build([0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->phase = Phase::BeginCombat;
        $engine = new GameEngine(new AgentRegistry());

        // Priorité de BeginCombat : le joueur passe.
        $r = $engine->advance($s);
        $this->assertSame('pass', $r->pending->toArray()['actions'][0]['type']);
        $r = $engine->advance($s, new \App\Engine\Action\PassPriority(0));

        $req = $r->pending->toArray();
        $this->assertSame('attackers', $req['declaring']);
        $this->assertSame(['attack', 'attack', 'attack', 'done'], array_column($req['actions'], 'type'));
        $this->assertSame(0, $req['priority']['holder']);

        // Le serveur ne fait pas confiance au client : une attaque hors liste est refusée.
        $this->expectException(\App\Engine\Decision\IllegalActionException::class);
        $engine->advance($s, new DeclareAttacker(0, 'c1', 0));
    }

    public function testHumanDeclaresThenGetsThePriorityOfTheStep(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2)->build([0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->phase = Phase::BeginCombat;
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $engine->advance($s, new \App\Engine\Action\PassPriority(0));

        $r = $engine->advance($s, new DeclareAttacker(0, 'c1', 2));
        $this->assertSame(['AttackerDeclared'], array_values(array_unique(array_map(static fn ($e) => $e->type, $this->of($r->events, 'AttackerDeclared')))));
        $req = $r->pending->toArray();
        $this->assertNull($req['declaring']);
        $this->assertSame('declare_attackers', $req['phase']);
        $this->assertSame('pass', $req['actions'][0]['type']);
        $this->assertSame([2], [$s->combat->attackers['c1']]);
    }
}
