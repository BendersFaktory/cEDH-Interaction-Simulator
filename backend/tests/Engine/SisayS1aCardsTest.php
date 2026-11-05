<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ResolutionChoice;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Mana\ManaContext;
use App\Engine\Mana\ManaPayment;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot S1a du deck Sisay (étape 17), textes réels de Scryfall : mana des créatures et des terrains (Noble / Ignoble Hierarch,
 * Delighted Halfling, Bloom Tender, Gaea's Cradle, Selvala, Kinnan, Badgermole Cub, Forbidden Orchard, Deathrite Shaman, Mount
 * Doom, Warren Soultrader).
 */
final class SisayS1aCardsTest extends TestCase
{
    private const HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass'];
    public const LOT = ['Noble Hierarch', 'Ignoble Hierarch', 'Delighted Halfling', 'Bloom Tender', 'Gaea\'s Cradle', 'Selvala, Heart of the Wilds',
        'Kinnan, Bonder Prodigy', 'Badgermole Cub', 'Forbidden Orchard', 'Deathrite Shaman', 'Mount Doom', 'Warren Soultrader'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    private static function stackPending(GameState $s, array $targets = []): StackItem
    {
        $pending = array_shift($s->pendingTriggers);
        self::assertNotNull($pending);
        $item = new StackItem('s' . $s->nextStackId++, $pending->source, $pending->controller, $targets, trigger: $pending->ability());
        $s->stack[] = $item;

        return $item;
    }

    /** Active la capacité `$index` de `$cardId` (premier choix légal correspondant, ou celui dont les cibles sont `$targets`). */
    private static function activate(GameState $s, string $cardId, int $index, ?array $targets = null, int $option = 0): void
    {
        foreach ((new LegalActionGenerator())->for($s, 0) as $a) {
            if ($a instanceof ActivateAbility && $a->cardId === $cardId && $a->abilityIndex === $index && $a->option === $option && ($targets === null || $a->targets === $targets)) {
                AbilityManager::activate($s, $a);

                return;
            }
        }
        self::fail("Capacité $index de $cardId non proposée");
    }

    private static function bear(string $id, int $power = 2, string $subtype = 'Bear'): CardInstance
    {
        return new CardInstance($id, 'Bear ' . $id, CardInstance::CREATURE, 2, power: $power, toughness: 2, manaCost: '{1}{G}', subtypes: [CardInstance::CREATURE => [$subtype]]);
    }

    public function testEveryCardOfTheLotIsMapped(): void
    {
        foreach (self::LOT as $name) {
            self::def($name);
        }
        $this->assertSame([['G'], ['W'], ['U']], array_map(static fn (array $o) => array_map(static fn ($c) => $c->symbol(), $o), self::def('Noble Hierarch')->abilities[0]->options));
        $this->assertSame(TriggeredAbility::ATTACKS_ALONE, self::def('Ignoble Hierarch')->triggers[0]->event);
        $this->assertSame([CardInstance::STATIC_MANA_NONLAND_EXTRA], self::def('Kinnan, Bonder Prodigy')->statics);
        $this->assertSame(['mana', 'damage_each_opponent', 'doom_wrath'], array_map(static fn ($a) => $a->effect, self::def('Mount Doom')->abilities));
        $this->assertSame(['gy_exile_mana', 'gy_exile_drain', 'gy_exile_gain'], array_map(static fn ($a) => $a->effect, self::def('Deathrite Shaman')->abilities));
        $this->assertSame(['G'], array_map(static fn ($c) => $c->symbol(), self::card('Bloom Tender', 'x')->colorIdentity()), 'Vivid n\'a aucun symbole de mana');
    }

    public function testExaltedPumpsTheLoneAttacker(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Noble Hierarch', 'p0_c1'))->permanent(0, self::card('Ignoble Hierarch', 'p0_c2'))
            ->creature(0, 'p0_c3', 2, 2)->build(self::HUMAN);
        $s->queuePlayerTriggers(0, TriggeredAbility::ATTACKS_ALONE, stackId: 'p0_c3');
        $this->assertCount(2, $s->pendingTriggers, 'une instance d\'exalted par permanent');
        $this->assertSame(['permanent:p0_c3'], (new LegalActionGenerator())->triggerActions($s, 0)[0]->targets);
        self::stackPending($s, ['permanent:p0_c3']);
        self::stackPending($s, ['permanent:p0_c3']);
        (new StackResolver())->resolveTop($s);
        (new StackResolver())->resolveTop($s);
        $this->assertSame([4, 4], [$s->player(0)->findOnBattlefield('p0_c3')->currentPower(), $s->player(0)->findOnBattlefield('p0_c3')->currentToughness()]);
    }

    public function testCradleSelvalaAndBloomTenderScaleTheirMana(): void
    {
        $cradle = self::card('Gaea\'s Cradle', 'p0_c1');
        $selvala = self::card('Selvala, Heart of the Wilds', 'p0_c2');
        $tender = self::card('Bloom Tender', 'p0_c3');
        $s = (new ScenarioBuilder())->permanent(0, $cradle)->build(self::HUMAN);
        $p = $s->player(0);
        $this->assertFalse(AbilityManager::canActivate($s, $p, $cradle, $cradle->abilities()[0]), 'sans créature : rien à produire');

        $p->battlefield[] = $selvala;
        $p->battlefield[] = $tender;
        $p->battlefield[] = self::bear('p0_c4', 5);
        $p->battlefield[] = new CardInstance('p0_c5', 'Blue Rock', CardInstance::ARTIFACT, 1, manaCost: '{U}');
        $this->assertSame(['G' => 3], $p->manaProduced($cradle, $cradle->abilities()[0], 0));
        $this->assertSame(['R' => 5], $p->manaProduced($selvala, $selvala->abilities[0], 3), 'X = 5 (plus grande force), d\'une seule couleur');
        $this->assertSame(['U' => 1, 'G' => 1], $p->manaProduced($tender, $tender->abilities[0], 0), 'couleurs parmi ses permanents');

        $s->turn = 3;
        self::activate($s, 'p0_c1', 0);
        $this->assertSame(3, $p->pool->toArray()['G'] ?? 0);
        $plan = ManaPayment::plan($p, \App\Engine\Mana\ManaCost::parse('{6}'), 0, $s->turn, null, null, new ManaContext($p, $s->turn));
        $this->assertNotNull($plan, '3 {G} en réserve + Bloom Tender 2 + Selvala (payée avec la réserve)');
    }

    public function testSelvalaLetsTheBiggestNewCreatureDraw(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Selvala, Heart of the Wilds', 'p0_c1'))->build(self::HUMAN);
        $s->enterBattlefield(1, self::bear('p1_c1', 2));
        $this->assertCount(1, $s->pendingTriggers, 'une autre créature, d\'un adversaire');
        $targets = (new LegalActionGenerator())->triggerActions($s, 0)[0]->targets;
        $this->assertSame(['permanent:p1_c1'], $targets);
        self::stackPending($s, $targets);
        (new StackResolver())->resolveTop($s);
        $this->assertCount(0, $s->player(1)->hand, 'force 2, pas plus grande que Selvala (2)');

        $s->enterBattlefield(1, self::bear('p1_c2', 4));
        self::stackPending($s, ['permanent:p1_c2']);
        (new StackResolver())->resolveTop($s);
        $this->assertCount(1, $s->player(1)->hand, 'son contrôleur pioche');
    }

    public function testKinnanAddsManaAndDigsForANonHumanCreature(): void
    {
        $kinnan = self::card('Kinnan, Bonder Prodigy', 'p0_c1');
        $rock = new CardInstance('p0_c2', 'Sol Ring', CardInstance::ARTIFACT, 1, abilities: [ActivatedAbility::mana('{C}{C}')]);
        $s = (new ScenarioBuilder())->untappedLands(0, 7)->permanent(0, $kinnan)->permanent(0, $rock)->build(self::HUMAN);
        $p = $s->player(0);
        $this->assertSame(['C' => 3], $p->manaProduced($rock, $rock->abilities[0], 0));
        $this->assertSame(['C' => 1], $p->manaProduced($p->battlefield[0], $p->battlefield[0]->abilities()[0], 0), 'un terrain : rien de plus');
        self::activate($s, 'p0_c2', 0);
        $this->assertSame(3, $p->pool->toArray()['C']);

        $p->library = [self::bear('p0_c10', 2, 'Human'), self::bear('p0_c11', 3), new CardInstance('p0_c12', 'Bolt', CardInstance::INSTANT, 1), ...$p->library];
        $s->stack[] = new StackItem('s1', $kinnan, 0, [], 0, $kinnan->abilities[0]);
        (new StackResolver())->resolveTop($s);
        $choices = array_map(static fn (ResolutionChoice $c) => $c->choice, array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ResolutionChoice)));
        $this->assertSame(['card:p0_c11', 'none'], $choices, 'pas l\'Humain, pas l\'éphémère');
        (new StackResolver())->choose($s, new ResolutionChoice(0, 'card:p0_c11'));
        $this->assertNotNull($p->findOnBattlefield('p0_c11'));
        $this->assertSame(['p0_lib2', 'p0_lib3', 'p0_lib4', 'p0_lib5', 'p0_lib6'], array_map(static fn (CardInstance $c) => $c->id, array_slice($p->library, 0, 5)), 'le reste sous la bibliothèque');
        $this->assertCount(12, $p->library);
    }

    public function testBadgermoleEarthbendsALandThatReturnsWhenItDies(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->hand(0, self::card('Badgermole Cub', 'p0_c1'))->build(self::HUMAN);
        $s->turn = 2;
        $p = $s->player(0);
        $s->enterBattlefield(0, $p->takeFromHand('p0_c1'));
        $this->assertSame([['permanent:p0_land0']], array_map(static fn ($a) => $a->targets, (new LegalActionGenerator())->triggerActions($s, 0)));
        self::stackPending($s, ['permanent:p0_land0']);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $land = $p->findOnBattlefield('p0_land0');
        $this->assertTrue($land->isCreature() && $land->isLand() && $land->hasKeyword(CardInstance::HASTE));
        $this->assertSame([1, 1], [$land->currentPower(), $land->currentToughness()]);
        $this->assertSame(['LandAnimated', 'CountersAdded'], array_map(static fn ($e) => $e->type, array_values(array_filter($s->drainEvents(), static fn ($e) => in_array($e->type, ['LandAnimated', 'CountersAdded'], true)))));
        $this->assertTrue(GameState::fromArray($s->toArray())->player(0)->findOnBattlefield('p0_land0')->earthbent);

        $this->assertSame(['C' => 1, 'G' => 1], $p->manaProduced($land, $land->abilities()[0], 0), 'une créature engagée pour du mana : {G} de plus');
        $s->destroyPermanent(0, 'p0_land0', 'test');
        $this->assertFalse($p->graveyard[0]->earthbent);
        self::stackPending($s);
        (new StackResolver())->resolveTop($s);
        $back = $p->findOnBattlefield('p0_land0');
        $this->assertTrue($back->tapped && !$back->isCreature(), 'revient engagé, simple terrain');
        $this->assertSame([], $p->graveyard);
    }

    public function testForbiddenOrchardGivesTargetOpponentASpirit(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Forbidden Orchard', 'p0_c1'))->build(self::HUMAN);
        self::activate($s, 'p0_c1', 4, []); // terrain : une capacité {T} par couleur de `produces`, {G} en cinquième
        $this->assertSame(1, $s->player(0)->pool->toArray()['G']);
        $this->assertCount(1, $s->pendingTriggers);
        $this->assertSame([['player:1'], ['player:2']], array_map(static fn ($a) => $a->targets, (new LegalActionGenerator())->triggerActions($s, 0)));
        self::stackPending($s, ['player:2']);
        (new StackResolver())->resolveTop($s);
        $spirit = $s->player(2)->battlefield[0];
        $this->assertSame(['Spirit', 1, 1, true], [$spirit->name, $spirit->power, $spirit->toughness, $spirit->token]);
    }

    public function testDeathriteShamanExilesFromGraveyards(): void
    {
        $shaman = self::card('Deathrite Shaman', 'p0_c1');
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->permanent(0, $shaman)->build(self::HUMAN);
        $s->turn = 2;
        $s->player(1)->graveyard = [new CardInstance('p1_c9', 'Polluted Delta', CardInstance::LAND), new CardInstance('p1_c8', 'Bolt', CardInstance::INSTANT, 1)];
        $acts = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c1'));
        $this->assertSame([['card:p1_c9', 'mana:W'], ['card:p1_c9', 'mana:U'], ['card:p1_c9', 'mana:B'], ['card:p1_c9', 'mana:R'], ['card:p1_c9', 'mana:G']],
            array_map(static fn ($a) => $a->targets, array_values(array_filter($acts, static fn ($a) => $a->abilityIndex === 0))));
        $this->assertFalse($acts[0]->mana, 'capacité ciblée : sur la pile (CR 605.1a)');
        self::activate($s, 'p0_c1', 0, ['card:p1_c9', 'mana:B']);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(1, $s->player(0)->pool->toArray()['B']);
        $this->assertSame(['p1_c9'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->exile));

        $s->stack[] = new StackItem('s9', $shaman, 0, ['card:p1_c8'], 0, $shaman->abilities[1], 1);
        (new StackResolver())->resolveTop($s);
        $this->assertSame([20, 18, 18], [$s->player(0)->life, $s->player(1)->life, $s->player(2)->life]);
    }

    public function testMountDoomPingsAndWipesAllButTwoCreatures(): void
    {
        $doom = self::card('Mount Doom', 'p0_c1');
        $s = (new ScenarioBuilder())->permanent(0, $doom)->creature(0, 'p0_c2', 3, 3)->creature(1, 'p1_c2', 5, 5)->creature(2, 'p2_c2', 1, 1)->build(self::HUMAN);
        $s->stack[] = new StackItem('s1', $doom, 0, [], 0, $doom->abilities[1], 1);
        (new StackResolver())->resolveTop($s);
        $this->assertSame([20, 19, 19], [$s->player(0)->life, $s->player(1)->life, $s->player(2)->life]);

        $s->stack[] = new StackItem('s2', $doom, 0, [], 0, $doom->abilities[2], 2);
        (new StackResolver())->resolveTop($s);
        $this->assertSame('doom_keep', $s->selection['kind']);
        (new StackResolver())->choose($s, new ResolutionChoice(0, 'card:p0_c2'));
        $choices = array_map(static fn (ResolutionChoice $c) => $c->choice, array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ResolutionChoice)));
        $this->assertSame(['card:p1_c2', 'card:p2_c2', 'none'], $choices);
        (new StackResolver())->choose($s, new ResolutionChoice(0, 'card:p2_c2'));
        $this->assertNull($s->selection);
        $this->assertSame([true, false, true], [$s->player(0)->findOnBattlefield('p0_c2') !== null, $s->player(1)->findOnBattlefield('p1_c2') !== null, $s->player(2)->findOnBattlefield('p2_c2') !== null]);
    }

    public function testMountDoomWrathCostsALegendaryArtifact(): void
    {
        $doom = self::card('Mount Doom', 'p0_c1');
        $relic = new CardInstance('p0_c3', 'Relic', CardInstance::ARTIFACT, 1, supertypes: [CardInstance::LEGENDARY]);
        $s = (new ScenarioBuilder())->untappedLands(0, 7)->permanent(0, $doom)->build(self::HUMAN);
        $p = $s->player(0);
        foreach ($p->battlefield as $i => $c) {
            if ($c->id === 'p0_land0' || $c->id === 'p0_land1') {
                $p->battlefield[$i] = new CardInstance($c->id, $c->id === 'p0_land0' ? 'Swamp' : 'Mountain', CardInstance::LAND, produces: [$c->id === 'p0_land0' ? 'B' : 'R']);
            }
        }
        $this->assertSame([], AbilityManager::costChoices($p, $doom, $doom->abilities[2]), 'sans artefact légendaire : impossible');
        $p->battlefield[] = $relic;
        $this->assertSame([[$relic]], AbilityManager::costChoices($p, $doom, $doom->abilities[2]));
        self::activate($s, 'p0_c1', 2);
        $this->assertSame(['Mount Doom', 'Relic'], array_map(static fn (CardInstance $c) => $c->name, $p->graveyard));
    }

    public function testWarrenSoultraderSacrificesAnotherCreatureForATreasure(): void
    {
        $trader = self::card('Warren Soultrader', 'p0_c1');
        $s = (new ScenarioBuilder())->permanent(0, $trader)->build(self::HUMAN);
        $this->assertSame([], AbilityManager::costChoices($s->player(0), $trader, $trader->abilities[0]), 'jamais lui-même');
        $s->player(0)->battlefield[] = self::bear('p0_c2');
        self::activate($s, 'p0_c1', 0);
        $this->assertSame(19, $s->player(0)->life);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['Warren Soultrader', 'Treasure'], array_map(static fn (CardInstance $c) => $c->name, $s->player(0)->battlefield));
    }

    public function testDelightedHalflingManaMakesALegendarySpellUncounterable(): void
    {
        $legend = new CardInstance('p0_c5', 'Legend', CardInstance::CREATURE, 1, power: 1, toughness: 1, manaCost: '{W}', supertypes: [CardInstance::LEGENDARY]);
        $s = (new ScenarioBuilder())->permanent(0, self::card('Delighted Halfling', 'p0_c1'))->hand(0, $legend)->build(self::HUMAN);
        $s->turn = 2;
        $cast = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === 'p0_c5'))[0] ?? null;
        $this->assertNotNull($cast, 'payé par le mana restreint de Delighted Halfling');
        (new GameEngine(new AgentRegistry()))->advance($s, $cast);
        $item = $s->findStackItem('s1') ?? $s->stack[0] ?? null;
        $this->assertNotNull($item);
        $this->assertTrue($item->uncounterable);
        $this->assertTrue(StackItem::fromArray($item->toArray())->uncounterable);

        $counter = new CardInstance('p1_c1', 'Counterspell', CardInstance::INSTANT, 2, effects: [new SpellEffect('counter')]);
        $s->stack[] = new StackItem('s9', $counter, 1, ['stack:' . $item->id]);
        (new StackResolver())->resolveTop($s);
        $this->assertNotNull($s->findStackItem($item->id), 'toujours sur la pile');
    }
}
