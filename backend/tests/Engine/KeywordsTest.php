<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Mots-clés de combat : célérité, défenseur, vigilance, vol/portée, menace, initiative, double initiative, piétinement, contact mortel, lien de vie. */
final class KeywordsTest extends TestCase
{
    private const TABLE = [0 => 'scripted:atk', 1 => 'scripted:blk', 2 => 'ai:pass', 3 => 'ai:pass'];

    /** J0 attaque J1 avec tout au premier combat ; J1 déclare tous les blocs proposés, au premier combat seulement. */
    private function registry(): AgentRegistry
    {
        $blockAll = new class implements PlayerAgent {
            private ?int $turn = null;

            public function decide(GameState $state, int $playerId, array $legal): Action
            {
                foreach ($legal as $a) {
                    if ($a instanceof DeclareBlocker && ($this->turn ?? $state->turn) === $state->turn) {
                        $this->turn = $state->turn;

                        return $a;
                    }
                }

                return AutoPassAgent::pass($legal, $playerId);
            }
        };

        return new AgentRegistry(['scripted:atk' => new CombatScriptAgent(1), 'scripted:blk' => $blockAll]);
    }

    /** @return list<GameEvent> événements jusqu'à la fin du premier combat */
    private function fight(ScenarioBuilder $b): array
    {
        $s = $b->build(self::TABLE);
        $s->phase = Phase::BeginCombat;
        $events = [];
        foreach ((new GameEngine($this->registry()))->advance($s)->events as $e) {
            if ($e->type === 'PhaseChanged' && $e->payload['phase'] === 'main2') {
                break;
            }
            $events[] = $e;
        }

        return $events;
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function phases(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->payload['phase'], $this->of($events, 'PhaseChanged'));
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function died(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->payload['card']['id'], $this->of($events, 'CreatureDied'));
    }

    /** @param list<GameEvent> $events */
    private function lifeOfP1(array $events): int
    {
        $life = 20;
        foreach ($this->of($events, 'DamageDealt') as $e) {
            if ($e->payload['target'] === 'player:1') {
                $life = $e->payload['life'];
            }
        }

        return $life;
    }

    /** @param list<Action> $actions @return list<string> */
    private function ids(array $actions): array
    {
        $ids = array_map(static fn (Action $a) => $a->id(), $actions);
        sort($ids);

        return $ids;
    }

    private function blocking(ScenarioBuilder $b, array $attackers): GameState
    {
        $s = $b->build(self::TABLE);
        $s->phase = Phase::DeclareBlockers;
        $s->combat->declaring = 'blockers';
        $s->combat->attackers = $attackers;
        $s->combat->queue = [1];
        $s->priority = new PriorityState(1, [], true);

        return $s;
    }

    // --- Déclarations ---

    public function testHasteIgnoresSummoningSickness(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2, false, 1, [CardInstance::HASTE])->build(self::TABLE);
        $this->assertCount(3, (new LegalActionGenerator())->attackActions($s, 0));
    }

    public function testDefenderCannotAttack(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'wall', 0, 4, false, 0, [CardInstance::DEFENDER])->build(self::TABLE);
        $this->assertSame([], (new LegalActionGenerator())->attackActions($s, 0));
    }

    public function testVigilantAttackerStaysUntapped(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'v', 2, 2, false, 0, [CardInstance::VIGILANCE]));
        $declared = $this->of($events, 'AttackerDeclared')[0];
        $this->assertFalse($declared->payload['tapped']);
        $this->assertSame(18, $this->lifeOfP1($events));
    }

    public function testFlyerCanOnlyBeBlockedByFlyingOrReach(): void
    {
        $s = $this->blocking(
            (new ScenarioBuilder())->creature(0, 'atk', 2, 2, false, 0, [CardInstance::FLYING])
                ->creature(1, 'ground', 3, 3)
                ->creature(1, 'spider', 2, 4, false, 0, [CardInstance::REACH])
                ->creature(1, 'bird', 1, 1, false, 0, [CardInstance::FLYING]),
            ['atk' => 1],
        );
        $this->assertSame(['block:1:bird:atk', 'block:1:spider:atk'], $this->ids((new LegalActionGenerator())->blockActions($s, 1)));
    }

    public function testFlyerMayBlockAGroundAttacker(): void
    {
        $s = $this->blocking(
            (new ScenarioBuilder())->creature(0, 'atk', 2, 2)->creature(1, 'bird', 1, 1, false, 0, [CardInstance::FLYING]),
            ['atk' => 1],
        );
        $this->assertSame(['block:1:bird:atk'], $this->ids((new LegalActionGenerator())->blockActions($s, 1)));
    }

    public function testMenaceCannotBeBlockedByASingleCreature(): void
    {
        $s = $this->blocking(
            (new ScenarioBuilder())->creature(0, 'brute', 3, 2, false, 0, [CardInstance::MENACE])->creature(1, 'b1', 1, 1),
            ['brute' => 1],
        );
        $this->assertSame([], (new LegalActionGenerator())->blockActions($s, 1), 'un seul bloqueur disponible : aucun bloc proposé');
    }

    public function testHalfBlockedMenaceMustBeCompletedBeforeAnythingElse(): void
    {
        $s = $this->blocking(
            (new ScenarioBuilder())->creature(0, 'brute', 3, 2, false, 0, [CardInstance::MENACE])->creature(0, 'other', 1, 1)
                ->creature(1, 'b1', 1, 1)->creature(1, 'b2', 1, 1)->creature(1, 'b3', 1, 1),
            ['brute' => 1, 'other' => 1],
        );
        $legal = new LegalActionGenerator();
        $this->assertContains('block:1:b1:brute', $this->ids($legal->for($s, 1)));

        $s->combat->blockers['brute'] = ['b1'];
        $actions = $legal->for($s, 1);
        $this->assertSame(['block:1:b2:brute', 'block:1:b3:brute'], $this->ids($actions));
        $this->assertSame([], array_filter($actions, static fn (Action $a) => $a instanceof FinishDeclaration), 'pas de fin de déclaration avec un bloc illégal');

        $s->combat->blockers['brute'] = ['b1', 'b2'];
        $actions = $legal->for($s, 1);
        $this->assertContains('block:1:b3:other', $this->ids($actions));
        $this->assertNotSame([], array_filter($actions, static fn (Action $a) => $a instanceof FinishDeclaration));
    }

    public function testMenaceIsDoubleBlockedInAGame(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'brute', 3, 2, false, 0, [CardInstance::MENACE])
            ->creature(1, 'b1', 1, 1)->creature(1, 'b2', 1, 1));
        $this->assertCount(2, $this->of($events, 'BlockerDeclared'));
        $this->assertSame(20, $this->lifeOfP1($events));
        $this->assertContains('brute', $this->died($events));
    }

    // --- Dégâts ---

    public function testNoFirstStrikeStepWithoutFirstStrike(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 2, 2)->creature(1, 'blk', 2, 2));
        $this->assertNotContains('first_strike_damage', $this->phases($events));
    }

    public function testFirstStrikeKillsTheBlockerBeforeItStrikesBack(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 2, 2, false, 0, [CardInstance::FIRST_STRIKE])->creature(1, 'blk', 2, 2));
        $phases = $this->phases($events);
        $this->assertSame(['first_strike_damage', 'combat_damage'], array_values(array_intersect($phases, ['first_strike_damage', 'combat_damage'])));
        $this->assertSame(['blk'], $this->died($events));
        $this->assertSame([], array_filter($this->of($events, 'CreatureDamaged'), static fn (GameEvent $e) => $e->payload['card'] === 'atk'));
    }

    public function testFirstStrikeStepOpensAFullPriorityRound(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 2, 2, false, 0, [CardInstance::FIRST_STRIKE]));
        $phase = null;
        $granted = 0;
        foreach ($events as $e) {
            if ($e->type === 'PhaseChanged') {
                $phase = $e->payload['phase'];
            } elseif ($phase === 'first_strike_damage' && $e->type === 'PriorityPassed') {
                $granted++;
            }
        }
        $this->assertSame(4, $granted, 'les 4 joueurs passent pendant l\'étape d\'initiative');
        $this->assertSame(18, $this->lifeOfP1($events), 'une créature à initiative ne frappe pas une seconde fois');
    }

    public function testBlockerWithFirstStrikeKillsTheAttackerFirst(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 3, 2)->creature(1, 'blk', 2, 1, false, 0, [CardInstance::FIRST_STRIKE]));
        $this->assertSame(['atk'], $this->died($events));
    }

    public function testDoubleStrikeTrampleHitsInBothSteps(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 3, 3, false, 0, [CardInstance::DOUBLE_STRIKE, CardInstance::TRAMPLE])->creature(1, 'blk', 1, 1));
        $toPlayer = array_map(
            static fn (GameEvent $e) => $e->payload['amount'],
            array_values(array_filter($this->of($events, 'DamageDealt'), static fn (GameEvent $e) => $e->payload['target'] === 'player:1')),
        );
        // 1re étape : 1 (létal) au bloqueur, 2 au joueur ; 2e étape : bloqueur mort, tout passe (CR 702.19e).
        $this->assertSame([2, 3], $toPlayer);
        $this->assertSame(15, $this->lifeOfP1($events));
    }

    public function testDoubleStrikeWithoutTrampleDealsNothingOnceItsBlockerIsGone(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 3, 3, false, 0, [CardInstance::DOUBLE_STRIKE])->creature(1, 'blk', 1, 1));
        $this->assertSame(20, $this->lifeOfP1($events));
        $this->assertSame(['blk'], $this->died($events));
    }

    public function testTrampleAssignsTheExcessToThePlayer(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 5, 5, false, 0, [CardInstance::TRAMPLE])->creature(1, 'blk', 2, 2));
        $this->assertSame(17, $this->lifeOfP1($events));
        $this->assertSame(['blk'], $this->died($events));
    }

    public function testDeathtouchKillsAnyBlocker(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'rat', 1, 1, false, 0, [CardInstance::DEATHTOUCH])->creature(1, 'giant', 5, 5));
        $this->assertEqualsCanonicalizing(['rat', 'giant'], $this->died($events));
    }

    public function testDeathtouchTrampleNeedsOnlyOneDamagePerBlocker(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 5, 5, false, 0, [CardInstance::DEATHTOUCH, CardInstance::TRAMPLE])
            ->creature(1, 'b1', 3, 3)->creature(1, 'b2', 1, 3));
        $this->assertEqualsCanonicalizing(['b1', 'b2'], $this->died($events));
        $this->assertSame(17, $this->lifeOfP1($events));
    }

    public function testDeathtouchMarkIsClearedAtCleanup(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'atk', 1, 1)->creature(1, 'blk', 1, 4, false, 0, [CardInstance::DEATHTOUCH])->build(self::TABLE);
        $card = $s->player(0)->battlefield[0];
        $card->deathtouchDamage = true;
        $this->assertTrue(CardInstance::fromArray($card->toArray())->deathtouchDamage, 'sérialisé');
        $card->deathtouchDamage = false;
        $this->assertArrayNotHasKey('deathtouchDamage', $card->toArray(), 'omis à faux');
    }

    public function testLifelinkGainsLifeWithAbsoluteValue(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 3, 3, false, 0, [CardInstance::LIFELINK]));
        $gained = $this->of($events, 'LifeGained');
        $this->assertCount(1, $gained);
        $this->assertSame(['player' => 0, 'amount' => 3, 'life' => 23, 'source' => 'Test Creature'], $gained[0]->payload);
        $this->assertSame(17, $this->lifeOfP1($events));
    }

    public function testLifelinkOnABlockerToo(): void
    {
        $events = $this->fight((new ScenarioBuilder())->creature(0, 'atk', 2, 2)->creature(1, 'blk', 2, 2, false, 0, [CardInstance::LIFELINK]));
        $this->assertSame(22, $this->of($events, 'LifeGained')[0]->payload['life']);
    }

    public function testKeywordsAreSerialisedAndOmittedWhenEmpty(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'a', 1, 1, false, 0, [CardInstance::FLYING])->creature(0, 'b', 1, 1)->build(self::TABLE);
        [$a, $b] = $s->player(0)->battlefield;
        $this->assertSame([CardInstance::FLYING], CardInstance::fromArray($a->toArray())->keywords);
        $this->assertArrayNotHasKey('keywords', $b->toArray());
    }
}
