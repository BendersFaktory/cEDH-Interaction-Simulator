<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Flashback (CR 702.34, Strike It Rich) et Dash (CR 702.109, Ragavan) : `CardInstance::$altCasts`, action `CastSpell` d'`alt`
 * `flashback` (depuis le cimetière) ou `dash` (depuis la main), objet de pile `altCast`.
 */
final class AltCastTest extends TestCase
{
    private const HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<CastSpell> */
    private function casts(GameState $s, string $cardId): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === $cardId));
    }

    private static function strike(): CardInstance
    {
        return new CardInstance('p0_c4', 'Strike It Rich', CardInstance::SORCERY, 1, manaCost: '{1}', effects: [new SpellEffect('treasure')],
            altCasts: [CardInstance::CAST_FLASHBACK => '{2}']);
    }

    private static function monkey(): CardInstance
    {
        return new CardInstance('p0_c6', 'Monkey', CardInstance::CREATURE, 3, null, 0, false, false, 2, 1, 0, 0, '{3}', altCasts: [CardInstance::CAST_DASH => '{1}']);
    }

    /** J0, phase principale, deux terrains incolores ; Strike It Rich au cimetière. */
    private function graveyard(): GameState
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 2)->build(self::HUMAN);
        $s->player(0)->graveyard[] = self::strike();
        $s->drainEvents();

        return $s;
    }

    public function testFlashbackCastsFromTheGraveyardThenExiles(): void
    {
        $s = $this->graveyard();
        $casts = $this->casts($s, 'p0_c4');
        $this->assertCount(1, $casts, 'seulement pour son coût de flashback');
        $this->assertSame(CastSpell::ALT_FLASHBACK, $casts[0]->alt);
        $this->assertSame('Lancer Strike It Rich (flashback {2}, depuis le cimetière)', $casts[0]->describe($s));

        $events = (new GameEngine(new AgentRegistry()))->advance($s, $casts[0])->events;
        $this->assertSame([], $s->player(0)->graveyard);
        $cast = $this->of($events, 'SpellCast')[0]->payload;
        $this->assertSame([CastSpell::ALT_FLASHBACK, '{2}'], [$cast['altCast'], $cast['manaCost']]);
        $this->assertTrue($s->stack[0]->flashback());

        (new StackResolver())->resolveTop($s);
        $this->assertSame(['p0_c4'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile), 'exilée au lieu du cimetière');
        $this->assertSame('exile', $this->of($s->drainEvents(), 'SpellResolved')[0]->payload['destination']);
        $this->assertCount(1, array_filter($s->player(0)->battlefield, static fn (CardInstance $c) => $c->name === 'Treasure'));
    }

    public function testFlashbackFollowsTheCardsTimingAndIsExiledWhenCountered(): void
    {
        $s = $this->graveyard();
        $s->activePlayer = 1;
        $this->assertSame([], $this->casts($s, 'p0_c4'), 'rituel : pas au tour d\'un adversaire');

        $s = $this->graveyard();
        $strike = array_pop($s->player(0)->graveyard);
        $s->stack[] = new StackItem('s1', $strike, 0, altCast: CardInstance::CAST_FLASHBACK);
        $s->stack[] = new StackItem('s2', new CardInstance('p1_c2', 'Counterspell', CardInstance::INSTANT, 2, effects: [new SpellEffect('counter')]), 1, ['stack:s1']);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['p0_c4'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile));
        $this->assertSame('exile', $this->of($s->drainEvents(), 'SpellCountered')[0]->payload['destination']);
    }

    public function testDashGivesHasteAndReturnsTheCreatureAtTheNextEndStep(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->hand(0, self::monkey())->build(self::HUMAN);
        $casts = $this->casts($s, 'p0_c6');
        $this->assertSame([CastSpell::ALT_DASH], array_map(static fn (CastSpell $c) => $c->alt, $casts), '{3} impayable, dash {1} payable');
        $this->assertSame('Lancer Monkey (dash {1})', $casts[0]->describe($s));

        (new GameEngine(new AgentRegistry()))->advance($s, $casts[0]);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $monkey = $s->player(0)->findOnBattlefield('p0_c6');
        $this->assertTrue($monkey->dashed);
        $this->assertTrue($monkey->hasKeyword(CardInstance::HASTE), 'célérité');
        $this->assertCount(1, $s->delayedTriggers);
        $copy = GameState::fromArray($s->toArray());
        $this->assertTrue($copy->player(0)->findOnBattlefield('p0_c6')->dashed, 'sérialisé');

        $return = new TriggeredAbility(TriggeredAbility::NEXT_END, 'return_dashed', spec: 'p0_c6');
        $s->stack[] = new StackItem('s9', $monkey, 0, [], 0, null, 0, $return);
        (new StackResolver())->resolveTop($s);
        $this->assertNull($s->player(0)->findOnBattlefield('p0_c6'));
        $this->assertSame('p0_c6', $s->player(0)->hand[0]->id);
        $this->assertFalse($s->player(0)->hand[0]->dashed, 'un nouvel objet');
        $this->assertSame('Dash', $this->of($s->drainEvents(), 'PermanentReturnedToHand')[0]->payload['source']);
    }

    public function testDashedReturnDoesNothingWhenItIsANewObject(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::monkey())->build(self::HUMAN); // arrivée normalement : pas `dashed`
        $s->stack[] = new StackItem('s9', self::monkey(), 0, [], 0, null, 0, new TriggeredAbility(TriggeredAbility::NEXT_END, 'return_dashed', spec: 'p0_c6'));
        (new StackResolver())->resolveTop($s);
        $this->assertNotNull($s->player(0)->findOnBattlefield('p0_c6'));
    }

    public function testAltCastsAreSerialized(): void
    {
        $def = CardDefinition::fromArray(['name' => 'Strike It Rich', 'type' => 'sorcery', 'manaCost' => '{R}', 'altCasts' => ['flashback' => '{2}{R}']]);
        $this->assertSame(['flashback' => '{2}{R}'], $def->toArray()['altCasts']);
        $card = $def->instantiate('p0_c1');
        $this->assertSame(['flashback' => '{2}{R}'], CardInstance::fromArray($card->toArray())->altCasts);
        $this->assertSame(['flashback' => '{2}{R}'], $card->toPublic()['altCasts']);
        $item = new StackItem('s1', $card, 0, altCast: CardInstance::CAST_FLASHBACK);
        $this->assertTrue(StackItem::fromArray($item->toArray())->flashback());
        $this->assertArrayNotHasKey('altCasts', (new CardInstance('p0_c7', 'Bear', CardInstance::CREATURE, 2))->toPublic(), 'omis sans autre façon de lancer');
    }
}
