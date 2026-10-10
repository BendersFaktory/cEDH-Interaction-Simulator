<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HumanAgent;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Capacités activées : mana (résolue sur-le-champ) et non-mana (pile), réserve, rituel, auto-pass humain. */
final class AbilityTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function solRing(string $id = 'sol'): CardInstance
    {
        return new CardInstance($id, 'Sol Ring', CardInstance::ARTIFACT, 1, null, 0, false, false, 0, 0, 0, 0, '{1}', [], [ActivatedAbility::mana('{C}{C}')]);
    }

    private static function pinger(string $id = 'pg'): CardInstance
    {
        return new CardInstance($id, 'Pinger', CardInstance::ARTIFACT, 1, null, 0, false, false, 0, 0, 0, 0, '{1}', [], [ActivatedAbility::pinger(1)]);
    }

    private static function ritual(string $id = 'rit'): CardInstance
    {
        return new CardInstance($id, 'Rite of Flame', CardInstance::INSTANT, 1, 'mana', 0, false, false, 0, 0, 0, 0, '{R}', [], [], '{R}{R}');
    }

    private static function mountain(string $id): CardInstance
    {
        return new CardInstance($id, 'Mountain', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['R']);
    }

    /** @return list<Action> */
    private function abilities(GameState $s, int $player = 0): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, $player), static fn (Action $a) => $a instanceof ActivateAbility));
    }

    public function testManaAbilityResolvesImmediatelyWithoutTouchingStackOrPasses(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::solRing())->build(self::PASS);
        $s->priority->passed = [1, 2];
        $s->priority->announced = true;

        AbilityManager::activate($s, $this->abilities($s)[0]);

        $this->assertSame([], $s->stack);
        $this->assertSame([1, 2], $s->priority->passed);
        $this->assertTrue($s->priority->announced, 'Le joueur garde la priorité, pas de nouvel octroi');
        $this->assertSame(['C' => 2], $s->player(0)->pool->toArray());
        $this->assertTrue($s->player(0)->findOnBattlefield('sol')->tapped);
    }

    public function testManaAbilityIsNotOfferedOnceTappedAndNeverOnTheStack(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::solRing())->build(self::PASS);
        $this->assertCount(1, $this->abilities($s));
        $this->assertTrue($this->abilities($s)[0]->mana);
        AbilityManager::activate($s, $this->abilities($s)[0]);
        $this->assertSame([], $this->abilities($s));
    }

    public function testManualManaIsSpentBeforeUntappedLands(): void
    {
        // Deux Montagnes : on en engage une à la main, puis Bolt est payé par la réserve, l'autre reste libre.
        $s = (new ScenarioBuilder())->hand(0, ScenarioBuilder::bolt('b1'))->build(self::PASS);
        $s->player(0)->battlefield = [self::mountain('m1'), self::mountain('m2')];
        $s->player(0)->hand = [new CardInstance('b1', 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, false, false, 0, 0, 0, 0, '{R}')];

        AbilityManager::activate($s, $this->abilities($s)[0]);
        $cast = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof CastSpell && $a->targets === ['player:1']))[0];
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s, $cast);

        $spell = array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCast'))[0];
        $this->assertSame([], $spell->payload['tapped'], 'Rien d\'autre n\'est engagé');
        $this->assertSame(['R' => 1], $spell->payload['fromPool']);
        $this->assertFalse($s->player(0)->findOnBattlefield('m2')->tapped);
        $this->assertTrue($s->player(0)->pool->isEmpty());
    }

    public function testNonManaAbilityGoesOnTheStackAndResetsPasses(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::pinger())->build(self::PASS);
        $s->priority->passed = [1];
        $actions = $this->abilities($s);
        $this->assertCount(4, $actions, 'Une action par joueur en vie');
        $this->assertFalse($actions[0]->mana);

        $target = array_values(array_filter($actions, static fn (ActivateAbility $a) => $a->targets === ['player:2']))[0];
        AbilityManager::activate($s, $target);

        $this->assertCount(1, $s->stack);
        $this->assertTrue($s->stack[0]->isAbility());
        $this->assertSame([], $s->priority->passed);
        $this->assertTrue($s->player(0)->findOnBattlefield('pg')->tapped, 'Le {T} est payé à l\'activation');
        $this->assertSame([], $this->abilities($s), 'Déjà engagé');
    }

    public function testNonManaAbilityResolvesThroughThePriorityRoundAndStaysOnTheBattlefield(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::pinger())->build([0 => 'scripted:ping', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $registry = new AgentRegistry(['scripted:ping' => new PingAgent('player:2')]);
        $result = (new GameEngine($registry))->advance($s);

        $this->assertSame(19, $s->player(2)->life);
        $types = array_column(array_map(static fn ($e) => ['t' => $e->type], $result->events), 't');
        $this->assertContains('AbilityActivated', $types);
        $this->assertContains('AbilityResolved', $types);
        $this->assertNotNull($s->player(0)->findOnBattlefield('pg'));
        // Tous les joueurs ont reçu la priorité avec l'activation sur la pile.
        $activated = array_search('AbilityActivated', $types, true);
        $granted = array_filter(array_slice($result->events, $activated, 12), static fn ($e) => $e->type === 'PriorityGranted');
        $this->assertGreaterThanOrEqual(4, count($granted));
    }

    public function testRitualResolvesThroughTheStackAndFloatsUntilEndOfStep(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::ritual())->build([0 => 'scripted:rit', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->player(0)->battlefield = [self::mountain('m1')];
        $registry = new AgentRegistry(['scripted:rit' => new ScriptedAgent('Rite of Flame', null)]);
        $result = (new GameEngine($registry))->advance($s);

        $types = array_column(array_map(static fn ($e) => ['t' => $e->type], $result->events), 't');
        $added = array_values(array_filter($result->events, static fn ($e) => $e->type === 'ManaAdded'));
        $this->assertCount(1, $added);
        $this->assertSame(['R' => 2], $added[0]->payload['pool']);
        $emptied = array_values(array_filter($result->events, static fn ($e) => $e->type === 'ManaPoolEmptied'));
        $this->assertNotEmpty($emptied, 'Le mana flottant disparaît à la fin de l\'étape');
        $this->assertSame(['R' => 2], $emptied[0]->payload['lost']);
        $this->assertTrue($s->player(0)->pool->isEmpty());
        $this->assertLessThan(array_search('ManaPoolEmptied', $types, true), array_search('ManaAdded', $types, true));
    }

    public function testCounterspellCannotTargetAnAbilityOnTheStack(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::pinger())->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)->build(self::PASS);
        AbilityManager::activate($s, array_values(array_filter($this->abilities($s), static fn (ActivateAbility $a) => $a->targets === ['player:2']))[0]);

        $s->priority->holder = 1;
        $counters = array_filter((new LegalActionGenerator())->for($s, 1), static fn (Action $a) => $a instanceof CastSpell);
        $this->assertSame([], array_values($counters), 'Rien à contrer : seule une capacité est sur la pile');
        $this->assertNotNull($s->player(0)->findOnBattlefield('pg'), 'La source reste sur le champ de bataille');
    }

    public function testAnAbilityCannotBeActivatedTwiceNorFromAnotherPlayer(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::solRing())->build(self::PASS);
        $action = $this->abilities($s)[0];
        AbilityManager::activate($s, $action);
        $this->expectException(IllegalActionException::class);
        AbilityManager::activate($s, $action);
    }

    public function testHumanAutoPassStillAutoPassesWithOnlyManaAbilities(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->player(0)->battlefield = [self::mountain('m1')];
        $s->phase = Phase::Upkeep;
        $legal = (new LegalActionGenerator())->for($s, 0);
        $this->assertNotEmpty(array_filter($legal, static fn (Action $a) => $a instanceof ActivateAbility));

        $action = (new HumanAgent(autoPass: true))->decide($s, 0, $legal);
        $this->assertSame('pass:0', $action->id());
    }

    public function testHumanAutoStopsOnANonManaAbility(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::pinger())->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->phase = Phase::Upkeep;
        $this->expectException(DecisionRequired::class);
        (new HumanAgent(autoPass: true))->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
    }

    public function testSerializationRoundTripKeepsAbilitiesAndPool(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::solRing())->permanent(0, self::pinger())->build(self::PASS);
        AbilityManager::activate($s, $this->abilities($s)[0]);
        $copy = GameState::fromArray($s->toArray());

        $this->assertSame(json_encode($s->toArray()), json_encode($copy->toArray()));
        $this->assertSame(['C' => 2], $copy->player(0)->pool->toArray());
    }
}
