<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use App\Engine\Game\TurnManager;
use App\Engine\Rules\CombatManager;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/** Coûts riches (sacrifice d'une créature, défausse, sacrifice de la source), effets (pioche, jeton, bonus) et timing de rituel. */
final class ActivatedCostsTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function card(string $id, string $name, string $type, array $abilities, int $power = 0, int $toughness = 0): CardInstance
    {
        return new CardInstance($id, $name, $type, 0, null, 0, false, false, $power, $toughness, abilities: $abilities);
    }

    private static function mountain(string $id): CardInstance
    {
        return new CardInstance($id, 'Mountain', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['R']);
    }

    private static function bear(string $id): CardInstance
    {
        return new CardInstance($id, 'Grizzly Bears', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2);
    }

    /** @return list<ActivateAbility> */
    private function abilities(GameState $s, ?string $cardId = null, int $player = 0): array
    {
        return array_values(array_filter(
            (new LegalActionGenerator())->for($s, $player),
            static fn (Action $a) => $a instanceof ActivateAbility && ($cardId === null || $a->cardId === $cardId),
        ));
    }

    /** @return list<string> */
    private function types(GameState $s): array
    {
        return array_map(static fn ($e) => $e->type, $s->drainEvents());
    }

    public function testSorcerySpeedAbilityOnlyInOwnMainPhaseWithEmptyStack(): void
    {
        $tome = self::card('tome', 'Tome', CardInstance::ARTIFACT, [ActivatedAbility::draw(1, null, true, false, true)]);
        $s = (new ScenarioBuilder())->permanent(0, $tome)->permanent(1, self::card('tome1', 'Tome', CardInstance::ARTIFACT, [ActivatedAbility::draw(1, null, true, false, true)]))->build(self::PASS);

        $this->assertCount(1, $this->abilities($s, 'tome'));
        $this->assertSame([], $this->abilities($s, 'tome1', 1), 'Pas pendant le tour d\'un autre');
        $s->phase = Phase::Upkeep;
        $this->assertSame([], $this->abilities($s, 'tome'), 'Pas hors phase principale');
        $s->phase = Phase::Main2;
        $s->stack[] = new StackItem('s9', ScenarioBuilder::bolt('b9'), 1, ['player:0']);
        $this->assertSame([], $this->abilities($s, 'tome'), 'Pas avec une pile non vide');
        $this->assertStringContainsString('(rituel)', ActivatedAbility::draw(1, null, true, false, true)->label());
    }

    public function testMindStoneCannotPayItsOwnCostThenDrawsAndIsSacrificed(): void
    {
        $stone = self::card('ms', 'Mind Stone', CardInstance::ARTIFACT, [ActivatedAbility::mana('{C}'), ActivatedAbility::draw(1, '{1}', true, true)]);
        $s = (new ScenarioBuilder())->permanent(0, $stone)->build(self::PASS);
        $this->assertSame([0], array_map(static fn (ActivateAbility $a) => $a->abilityIndex, $this->abilities($s)), 'Seule sa capacité de mana');

        $s->player(0)->battlefield[] = self::mountain('m1');
        $draw = array_values(array_filter($this->abilities($s, 'ms'), static fn (ActivateAbility $a) => $a->abilityIndex === 1))[0];
        $this->assertSame('Activer Mind Stone ({1}, {T}, sacrifier : piocher 1)', $draw->describe($s));
        $s->drainEvents();
        AbilityManager::activate($s, $draw);
        $this->assertSame(['AbilityActivated', 'PermanentSacrificed'], $this->types($s));
        $this->assertSame(['ms'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->graveyard));
        $this->assertTrue($s->player(0)->findOnBattlefield('m1')->tapped);

        $hand = count($s->player(0)->hand);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['AbilityResolved', 'CardDrawn'], $this->types($s));
        $this->assertCount($hand + 1, $s->player(0)->hand);
    }

    public function testSacrificeACreatureOneActionPerNameAndTargetAndCommanderGoesHome(): void
    {
        $bombardment = self::card('gb', 'Goblin Bombardment', CardInstance::ENCHANTMENT, [ActivatedAbility::bombardment(1)]);
        $s = (new ScenarioBuilder())->permanent(0, $bombardment)->build(self::PASS);
        $this->assertSame([], $this->abilities($s), 'Aucune créature à sacrifier');

        $commander = ScenarioBuilder::commanderCard('cmd');
        $s->player(0)->battlefield = [$bombardment, self::bear('b1'), self::bear('b2'), $commander];
        $actions = $this->abilities($s);
        $this->assertCount(2 * 4, $actions, '2 noms de créature x 4 joueurs ciblables');
        $this->assertSame('Activer Goblin Bombardment (sacrifier une créature : 1 dégât) en sacrifiant Grizzly Bears → joueur 1', $actions[1]->describe($s));

        $sacCommander = array_values(array_filter($actions, static fn (ActivateAbility $a) => $a->costChoices === ['cmd'] && $a->targets === ['player:1']))[0];
        $s->drainEvents();
        AbilityManager::activate($s, $sacCommander);
        $events = $s->drainEvents();
        $this->assertSame(['AbilityActivated', 'PermanentSacrificed'], array_map(static fn ($e) => $e->type, $events));
        // CR 903.9a : le commandant sacrifié passe par le cimetière, les SBA le renvoient en zone de commandement.
        $this->assertSame('graveyard', $events[1]->payload['destination']);
        $this->assertSame('cmd', $s->player(0)->graveyard[0]->id);
        (new StateBasedActions())->check($s);
        $this->assertSame('cmd', $s->player(0)->command[0]->id);
        $this->assertSame([], $s->player(0)->graveyard);
        $this->assertSame(['CommanderReturned'], array_map(static fn ($e) => $e->type, $s->drainEvents()));

        (new StackResolver())->resolveTop($s);
        $this->assertSame(19, $s->player(1)->life);
    }

    public function testInvalidCostChoiceIsRejected(): void
    {
        $bombardment = self::card('gb', 'Goblin Bombardment', CardInstance::ENCHANTMENT, [ActivatedAbility::bombardment(1)]);
        $s = (new ScenarioBuilder())->permanent(0, $bombardment)->permanent(0, self::bear('b1'))->permanent(1, self::bear('enemy'))->build(self::PASS);
        $this->expectException(IllegalActionException::class);
        AbilityManager::activate($s, new ActivateAbility(0, 'gb', 0, 0, ['player:1'], false, ['enemy']));
    }

    public function testDiscardCostPumpUntilEndOfTurnAndCleanupResets(): void
    {
        $mongrel = self::card('wm', 'Wild Mongrel', CardInstance::CREATURE, [ActivatedAbility::pump('+1/+1', null, 1)], 2, 2);
        $s = (new ScenarioBuilder())->permanent(0, $mongrel)->build(self::PASS);
        $this->assertSame([], $this->abilities($s), 'Main vide : rien à défausser');

        $s->player(0)->hand = [self::mountain('h1'), self::mountain('h2'), ScenarioBuilder::bolt('h3')];
        $actions = $this->abilities($s);
        $this->assertSame([['h1'], ['h3']], array_map(static fn (ActivateAbility $a) => $a->costChoices, $actions), 'Une action par nom de carte');
        $this->assertSame('ability:0:wm:0:0::h1', $actions[0]->id());

        $s->drainEvents();
        AbilityManager::activate($s, $actions[0]);
        $this->assertSame(['AbilityActivated', 'CardDiscarded'], $this->types($s));
        (new StackResolver())->resolveTop($s);
        $events = $s->drainEvents();
        $this->assertSame(['card' => 'wm', 'power' => 3, 'toughness' => 3], array_intersect_key($events[1]->payload, ['card' => 1, 'power' => 1, 'toughness' => 1]));
        $this->assertSame(3, $mongrel->currentPower());

        // Sérialisation : le bonus survit (clé `boost`), puis le cleanup le remet à zéro avec un événement absolu.
        $copy = GameState::fromArray($s->toArray());
        $this->assertSame(3, $copy->player(0)->findOnBattlefield('wm')->currentToughness());
        $s->phase = Phase::Cleanup;
        $s->stepStarted = false;
        (new TurnManager(new CombatManager(new AgentRegistry(), new LegalActionGenerator())))->beginStep($s);
        $boosted = array_values(array_filter($s->drainEvents(), static fn ($e) => $e->type === 'CreatureBoosted'));
        $this->assertSame(2, $boosted[0]->payload['power']);
        $this->assertSame(2, $mongrel->currentPower());
        $this->assertArrayNotHasKey('boost', $mongrel->toArray());
    }

    public function testLandWithDeclaredAbilityCreatesASummoningSickToken(): void
    {
        $keep = new CardInstance('kk', 'Kher Keep', CardInstance::LAND, abilities: [ActivatedAbility::token('Kobold:0/1', 1, '{1}{R}')]);
        $s = (new ScenarioBuilder())->permanent(0, $keep)->permanent(0, self::mountain('m1'))->permanent(0, self::mountain('m2'))->build(self::PASS);
        $s->turn = 5;
        $this->assertCount(2, $keep->abilities(), '{T} : {C}, puis la capacité déclarée');

        $token = array_values(array_filter($this->abilities($s, 'kk'), static fn (ActivateAbility $a) => $a->abilityIndex === 1))[0];
        AbilityManager::activate($s, $token);
        $this->assertTrue($keep->tapped);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $created = array_values(array_filter($s->drainEvents(), static fn ($e) => $e->type === 'TokenCreated'))[0];
        $this->assertSame(['name' => 'Kobold', 'type' => 'creature', 'power' => 0, 'toughness' => 1, 'token' => true], array_intersect_key($created->payload['card'], ['name' => 1, 'type' => 1, 'power' => 1, 'toughness' => 1, 'token' => 1]));
        $this->assertSame(5, $s->player(0)->findOnBattlefield('p0_t1')->enteredTurn, 'Arrive avec le mal d\'invocation');
    }
}
