<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PregameChoice;
use App\Engine\Action\ReplaceChoice;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\CombatResolver;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\MulliganManager;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot H5a du deck Kraum / Tymna (étape 13, point 6), textes réels de Scryfall : Strike It Rich, An Offer You Can't Refuse,
 * Gleaming Splendor, Lotho, Tataru Taru, Ragavan, Wishclaw Talisman (lot 1 et 2 ; Gemstone Caverns, Mockingbird et
 * Opposition Agent : lot 3).
 */
final class KraumH5aCardsTest extends TestCase
{
    private const HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass'];
    public const LOT = ['Strike It Rich', 'An Offer You Can\'t Refuse', 'Gleaming Splendor', 'Lotho, Corrupt Shirriff', 'Tataru Taru', 'Ragavan, Nimble Pilferer',
        'Wishclaw Talisman', 'Gemstone Caverns', 'Mockingbird', 'Opposition Agent'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** Met le premier trigger en attente sur la pile avec `$targets`. */
    private static function stackPending(GameState $s, array $targets = []): StackItem
    {
        $pending = array_shift($s->pendingTriggers);
        self::assertNotNull($pending);
        $item = new StackItem('s' . $s->nextStackId++, $pending->source, $pending->controller, $targets, trigger: $pending->ability());
        $s->stack[] = $item;

        return $item;
    }

    /** @return list<string> */
    private static function names(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->name, $cards);
    }

    private static function library(GameState $s, int $player, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $s->player($player)->library[] = new CardInstance("p{$player}_c" . (80 + $i), 'Lib ' . $i, CardInstance::SORCERY, 1, manaCost: '{1}');
        }
    }

    public function testLotHasTheirMechanisms(): void
    {
        $this->assertSame(['flashback' => '{2}{R}'], self::def('Strike It Rich')->altCasts);
        $offer = self::def('An Offer You Can\'t Refuse')->effects;
        $this->assertSame(['counter', 'treasure'], array_map(static fn ($e) => $e->effect, $offer));
        $this->assertSame([2, 'spell_controller'], [$offer[1]->amount, $offer[1]->target]);
        $this->assertSame('two_players_draw', self::def('Gleaming Splendor')->abilities[0]->effect);
        $this->assertSame([TriggeredAbility::ANY_CAST, 'second_spell'], [self::def('Lotho, Corrupt Shirriff')->triggers[0]->event, self::def('Lotho, Corrupt Shirriff')->triggers[0]->filter]);
        $this->assertSame(['draw_opponent_may', 'treasure'], array_map(static fn ($t) => $t->effect, self::def('Tataru Taru')->triggers));
        $ragavan = self::def('Ragavan, Nimble Pilferer');
        $this->assertSame([TriggeredAbility::COMBAT_DAMAGE_PLAYER, ['dash' => '{1}{R}']], [$ragavan->triggers[0]->event, $ragavan->altCasts]);
        $this->assertSame(['wish' => 3], self::def('Wishclaw Talisman')->entersWithCounters);
    }

    public function testLothoTriggersOnAnyPlayersSecondSpell(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Lotho, Corrupt Shirriff', 'p0_c3'))->build(self::HUMAN);
        $spell = new CardInstance('p1_c1', 'Spell', CardInstance::INSTANT, 1);
        $s->spellsCast = [1 => 1];
        $s->queueOpponentCastTriggers(1, $spell);
        $this->assertSame([], $s->pendingTriggers, 'premier sort : rien');
        $s->spellsCast = [1 => 2, 0 => 2];
        $s->queueOpponentCastTriggers(1, $spell);
        $s->queueOpponentCastTriggers(0, $spell);
        $this->assertCount(2, $s->pendingTriggers, 'deuxième sort d\'un adversaire, puis le sien');

        self::stackPending($s);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $this->assertSame(19, $s->player(0)->life, 'ScenarioBuilder : 20 PV');
        $this->assertContains('Treasure', self::names($s->player(0)->battlefield));
    }

    public function testTataruTaruDrawsTwiceAndTriggersOnceOffTurn(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Tataru Taru', 'p0_c3'))->build(self::HUMAN);
        $s->player(0)->library = $s->player(1)->library = [];
        self::library($s, 0, 3);
        self::library($s, 1, 3);
        $tataru = array_pop($s->player(0)->hand);
        $s->enterBattlefield(0, $tataru);
        $targets = array_values(array_filter((new LegalActionGenerator())->triggerActions($s, 0), static fn ($a) => true));
        $this->assertSame([['player:1'], ['player:2']], array_map(static fn ($a) => $a->targets, $targets), 'un adversaire seulement');
        self::stackPending($s, ['player:1']);
        (new StackResolver())->resolveTop($s);
        $this->assertCount(1, $s->player(0)->hand);
        $this->assertCount(1, $s->player(1)->hand, 'l\'adversaire ciblé pioche');
        $this->assertCount(1, $s->pendingTriggers, 'cette pioche, hors du tour de J1, déclenche déjà Scions\' Secretary');

        $s->drawCard(1);
        $s->drawCard(1);
        $this->assertCount(1, $s->pendingTriggers, 'une seule fois par tour');
        $this->assertSame(['p0_c3'], $s->onceTriggered);
        $this->assertSame(['p0_c3'], GameState::fromArray($s->toArray())->onceTriggered);
        self::stackPending($s);
        (new StackResolver())->resolveTop($s);
        $treasure = array_values(array_filter($s->player(0)->battlefield, static fn (CardInstance $c) => $c->name === 'Treasure'))[0];
        $this->assertTrue($treasure->tapped);

        $s->onceTriggered = [];
        $s->activePlayer = 1;
        $s->drawCard(1);
        $this->assertSame([], $s->pendingTriggers, 'pendant son propre tour : rien');
    }

    public function testGleamingSplendorTargetsTwoPlayers(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 3)->permanent(0, self::card('Gleaming Splendor', 'p0_c3'))->build(self::HUMAN);
        $s->player(0)->battlefield = array_map(static fn (CardInstance $c) => $c->isLand() ? new CardInstance($c->id, 'Plains', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['W']) : $c, $s->player(0)->battlefield);
        $acts = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c3'));
        $this->assertSame([['player:0', 'player:1'], ['player:0', 'player:2'], ['player:1', 'player:2']], array_map(static fn ($a) => $a->targets, $acts));
        $s->player(1)->library = $s->player(2)->library = [];
        self::library($s, 1, 1);
        self::library($s, 2, 1);
        $s->stack[] = new StackItem('s1', $s->player(0)->battlefield[3], 0, ['player:1', 'player:2'], 0, $s->player(0)->battlefield[3]->abilities[0]);
        (new StackResolver())->resolveTop($s);
        $this->assertSame([1, 1], [count($s->player(1)->hand), count($s->player(2)->hand)]);
    }

    public function testRagavanExilesTheTopCardAndLetsItsControllerCastIt(): void
    {
        $ragavan = self::card('Ragavan, Nimble Pilferer', 'p0_c6');
        $s = (new ScenarioBuilder())->untappedLands(0, 2)->permanent(0, $ragavan)->build(self::HUMAN);
        $s->player(1)->library = [];
        self::library($s, 1, 2);
        $s->combat->attackers['p0_c6'] = 1;
        (new CombatResolver())->resolve($s);
        $this->assertSame(18, $s->player(1)->life, 'Ragavan 2/1, ScenarioBuilder : 20 PV');
        $this->assertSame(['player:1'], (new LegalActionGenerator())->triggerActions($s, 0)[0]->targets, 'le joueur blessé');
        self::stackPending($s, ['player:1']);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);

        $this->assertContains('Treasure', self::names($s->player(0)->battlefield));
        $this->assertSame(['p1_c80'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->exile));
        $exiled = $this->of($s->drainEvents(), 'CardExiled')[0]->payload;
        $this->assertSame(['library', 1], [$exiled['from'], $exiled['library']]);
        $s->combat->attackers = [];
        $casts = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === 'p1_c80'));
        $this->assertCount(1, $casts, 'J0 peut lancer la carte exilée de J1 ce tour');
    }

    public function testWishclawTalismanTutorsThenGoesToAnOpponent(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->untappedLands(1, 1)->build(self::HUMAN);
        $s->player(0)->library = [];
        self::library($s, 0, 2);
        $s->enterBattlefield(0, self::card('Wishclaw Talisman', 'p0_c50'));
        $talisman = $s->player(0)->findOnBattlefield('p0_c50');
        $this->assertSame(['wish' => 3], $talisman->counters);
        $s->pendingTriggers = [];

        $acts = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c50'));
        $this->assertSame([['library:p0_c80'], ['library:p0_c81']], array_map(static fn ($a) => $a->targets, $acts));
        $events = (new GameEngine(new AgentRegistry()))->advance($s, $acts[1])->events;
        $this->assertSame(2, $this->of($events, 'CountersRemoved')[0]->payload['count']);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['p0_c81'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->hand));
        $this->assertNull($s->player(0)->findOnBattlefield('p0_c50'));
        $stolen = $s->player(1)->findOnBattlefield('p0_c50');
        $this->assertSame(['wish' => 2], $stolen->counters, 'un adversaire en prend le contrôle, marqueurs gardés');

        $stolen->tapped = false;
        $this->assertSame([], array_filter((new LegalActionGenerator())->for($s, 1), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c50'), 'pas hors de son tour');
        $s->activePlayer = 1;
        $s->turn++;
        $this->assertNotSame([], array_filter((new LegalActionGenerator())->for($s, 1), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c50'), 'à son tour, J1 peut s\'en servir');
    }

    public function testGemstoneCavernsStartsInPlayForANonStartingPlayer(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'human', 2 => 'ai:pass']);
        $s->player(0)->hand = [self::card('Gemstone Caverns', 'p0_c1'), new CardInstance('p0_c2', 'X', CardInstance::SORCERY, 1)];
        $s->player(1)->hand = [self::card('Gemstone Caverns', 'p1_c1'), new CardInstance('p1_c2', 'Bolt', CardInstance::INSTANT, 1), new CardInstance('p1_c3', 'Bear', CardInstance::CREATURE, 2, power: 2, toughness: 2)];
        $s->mulligan = [0 => ['taken' => 0, 'kept' => true], 1 => ['taken' => 0, 'kept' => true], 2 => ['taken' => 0, 'kept' => true]];
        $legal = new LegalActionGenerator();
        $this->assertSame([], $legal->for($s, 0), 'premier joueur : rien');
        $choices = $legal->for($s, 1);
        $this->assertSame(['pregame:1:p1_c1:no', 'pregame:1:p1_c1:p1_c2', 'pregame:1:p1_c1:p1_c3'], array_map(static fn (Action $a) => $a->id(), $choices));
        $this->assertSame(['taken' => 0, 'kept' => true, 'pregame' => ['p1_c1']], (function () use ($s) {
            $copy = clone $s;
            $copy->mulligan[1]['pregame'] = ['p1_c1'];

            return GameState::fromArray($copy->toArray())->mulligan[1];
        })(), 'sérialisé');

        $s->drainEvents();
        $injected = $choices[2];
        (new MulliganManager(new AgentRegistry(), $legal))->run($s, $injected);
        $this->assertNull($s->mulligan, 'avant-partie terminée');
        $caverns = $s->player(1)->findOnBattlefield('p1_c1');
        $this->assertSame(['luck' => 1], $caverns->counters);
        $this->assertSame(['p1_c3'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->exile));
        $this->assertSame(['p1_c2'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->hand));
        $this->assertSame(['PregameCardPlaced', 'CountersAdded', 'CardExiled'], array_map(static fn (GameEvent $e) => $e->type, $s->drainEvents()));
        $mana = $caverns->abilities[0];
        $this->assertSame(['W' => true, 'U' => true, 'B' => true, 'R' => true, 'G' => true], $s->player(1)->allowedColors($caverns, $mana));
        $caverns->counters = [];
        $this->assertSame(['C' => true], $s->player(1)->allowedColors($caverns, $mana), 'sans marqueur : {C}');
        $this->assertSame([], $caverns->colorIdentity());
    }

    public function testMockingbirdEntersAsACopyAndRevertsWhenItLeaves(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        $s->player(1)->battlefield[] = new CardInstance('p1_c4', 'Bear', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2, 0, 0, '{1}{G}', subtypes: [CardInstance::CREATURE => ['Bear']]);
        $s->player(1)->battlefield[] = new CardInstance('p1_c5', 'Dragon', CardInstance::CREATURE, 5, null, 0, false, false, 5, 5, 0, 0, '{4}{R}');
        $s->stack[] = new StackItem('s1', self::card('Mockingbird', 'p0_c7'), 0, [], 1); // X = 1 : deux mana dépensés
        (new StackResolver())->resolveTop($s);
        $choices = (new LegalActionGenerator())->for($s, 0);
        $this->assertSame(['replace:0:decline', 'replace:0:copy=p1_c4'], array_map(static fn (Action $a) => $a->id(), $choices), 'le Dragon coûte trop');
        $this->assertSame('Mockingbird arrive comme une copie de Bear', $choices[1]->describe($s));

        $events = (new GameEngine(new AgentRegistry()))->advance($s, $choices[1])->events;
        $bird = $s->player(0)->findOnBattlefield('p0_c7');
        $this->assertSame(['Bear', 2, 2], [$bird->name, $bird->power, $bird->toughness]);
        $this->assertTrue($bird->hasKeyword(CardInstance::FLYING));
        $this->assertSame(['Bear', 'Bird'], $bird->subtypes[CardInstance::CREATURE]);
        $this->assertSame('p1_c4', $this->of($events, 'ReplacementApplied')[0]->payload['copyOf']);
        $this->assertSame('Mockingbird', GameState::fromArray($s->toArray())->player(0)->findOnBattlefield('p0_c7')->original->name, 'sérialisé');

        $s->drainEvents();
        $s->sacrifice(0, ['p0_c7']);
        $this->assertSame(['Mockingbird'], self::names($s->player(0)->graveyard), 'redevient Mockingbird en quittant le champ de bataille');
        $this->assertSame('Mockingbird', $this->of($s->drainEvents(), 'PermanentSacrificed')[0]->payload['card']['name'], 'l\'événement porte la carte imprimée');
    }

    public function testMockingbirdEntersAsItselfWithoutACandidate(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        $s->stack[] = new StackItem('s1', self::card('Mockingbird', 'p0_c7'), 0, [], 0);
        (new StackResolver())->resolveTop($s);
        $this->assertNull($s->selection);
        $this->assertSame('Mockingbird', $s->player(0)->findOnBattlefield('p0_c7')->name);
    }

    public function testOppositionAgentExilesWhatAnOpponentFinds(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(1, 1)->permanent(1, self::card('Opposition Agent', 'p1_c9'))->build(self::HUMAN);
        $s->player(0)->library = [new CardInstance('p0_c80', 'Brainstorm', CardInstance::INSTANT, 1, manaCost: '{U}', effects: [new SpellEffect('draw', 1)]),
            new CardInstance('p0_c81', 'Island', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['U'], subtypes: [CardInstance::LAND => ['Island']])];
        $tutor = new CardInstance('p0_c5', 'Demonic Tutor', CardInstance::SORCERY, 2, manaCost: '{1}{B}', effects: [new SpellEffect('tutor')]);
        $s->stack[] = new StackItem('s1', $tutor, 0, ['library:p0_c80']);
        (new StackResolver())->resolveTop($s);
        $this->assertSame([], $s->player(0)->hand, 'pas en main');
        $this->assertSame(['p0_c80'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile));
        $casts = array_values(array_filter((new LegalActionGenerator())->for($s, 1), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === 'p0_c80'));
        $this->assertCount(1, $casts, 'J1 lance le Brainstorm de J0 avec un terrain incolore (mana de n\'importe quelle couleur)');

        $s->player(0)->battlefield[] = new CardInstance('p0_c6', 'Polluted Delta', CardInstance::LAND, abilities: [ActivatedAbility::fetch('Island|Swamp')]);
        $s->stack[] = new StackItem('s2', $s->player(0)->battlefield[array_key_last($s->player(0)->battlefield)], 0, ['library:p0_c81'], 0, ActivatedAbility::fetch('Island|Swamp'));
        (new StackResolver())->resolveTop($s);
        $this->assertNull($s->player(0)->findOnBattlefield('p0_c81'), 'la fetchland ne trouve rien en jeu');
        $s->activePlayer = 1;
        $s->landsPlayed = 0;
        $plays = array_values(array_filter((new LegalActionGenerator())->for($s, 1), static fn (Action $a) => $a instanceof PlayLand && $a->cardId === 'p0_c81'));
        $this->assertCount(1, $plays, 'à son tour, J1 joue l\'Island exilée de J0');
    }

    /** Deck Kraum / Tymna de démonstration : les 10 cartes du lot, quelques staples, terrains. */
    public static function lotDeck(): LoadedDeck
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach ([...self::LOT, 'Lightning Bolt', 'Sol Ring', 'Counterspell', 'Brainstorm', 'Command Tower', 'Rhystic Study', 'Demonic Tutor', 'Polluted Delta', 'Wall of Omens',
            'City of Brass', 'Mana Confluence'] as $name) {
            $library[] = $m->map($f[$name]); // rouge : Command Tower, City of Brass, Mana Confluence (pas de Mountain dans les fixtures)
        }
        foreach (['Plains', 'Island', 'Swamp'] as $basic) {
            for ($i = 0; $i < 28; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }

        return new LoadedDeck($m->map($f['Kraum, Ludevic\'s Opus']), array_slice($library, 0, 98), partner: $m->map($f['Tymna the Weaver']));
    }

    public function testFourAiGameWithTheLotPlaysToTheEnd(): void
    {
        $deck = self::lotDeck();
        $state = GameFactory::create(11, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));
        $types = [];
        $cast = [];
        foreach ((new GameEngine(new AgentRegistry()))->advance($state)->events as $e) {
            $types[$e->type] = true;
            $name = $e->payload['card']['name'] ?? null;
            if (in_array($e->type, ['SpellCast', 'AbilityTriggered', 'LandPlayed', 'PregameCardPlaced'], true) && in_array($name, self::LOT, true)) {
                $cast[$name] = true;
            }
        }
        self::assertArrayHasKey('GameOver', $types);
        self::assertGreaterThanOrEqual(3, count($cast), 'plusieurs cartes du lot jouées : ' . implode(', ', array_keys($cast)));
    }
}
