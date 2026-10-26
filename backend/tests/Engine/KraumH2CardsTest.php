<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutTrigger;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Service\Deck\UnsupportedCard;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot H2 (deck Kraum / Tymna) : coûts alternatifs et additionnels (Snapback, Culling the Weak, Diabolic Intent), mana (Simian
 * Spirit Guide, Lion's Eye Diamond, Rite of Flame, Cabal Ritual, Rain of Filth, Mox Opal, Mox Amber), terrains (Starting Town,
 * City of Traitors, Otawara), modes (Pyroblast, Red Elemental Blast), tuteurs (Gamble, Ranger-Captain of Eos).
 */
final class KraumH2CardsTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    private static function land(string $id, string $name, string $color): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
    }

    private static function blueSpell(string $id): CardInstance
    {
        return new CardInstance($id, 'Blue Spell', CardInstance::INSTANT, 2, manaCost: '{U}{U}', effects: [new SpellEffect('draw', 1)]);
    }

    private static function redSpell(string $id): CardInstance
    {
        return new CardInstance($id, 'Red Spell', CardInstance::INSTANT, 1, manaCost: '{R}', effects: [new SpellEffect('draw', 1)]);
    }

    /** @return list<Action> */
    private function legal(GameState $s, int $player = 0): array
    {
        return (new LegalActionGenerator())->for($s, $player);
    }

    /** @return list<CastSpell> */
    private function casts(GameState $s, string $cardId, int $player = 0): array
    {
        return array_values(array_filter($this->legal($s, $player), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === $cardId));
    }

    /** @return list<ActivateAbility> */
    private function abilities(GameState $s, string $cardId, int $player = 0): array
    {
        return array_values(array_filter($this->legal($s, $player), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === $cardId));
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<string> */
    private static function ids(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->id, $cards);
    }

    /** Joue l'action puis passe la priorité (J0 est humain : les IA passent) jusqu'à la résolution de la pile. */
    private function play(GameState $s, Action $action): \App\Engine\Game\AdvanceResult
    {
        $engine = new GameEngine(new AgentRegistry());
        $r = $engine->advance($s, $action);
        $events = $r->events;
        for ($i = 0; $i < 6 && $s->stack !== [] && $r->pending !== null && $r->pending->player === 0; $i++) {
            $r = $engine->advance($s, new PassPriority(0));
            $events = [...$events, ...$r->events];
        }

        return new \App\Engine\Game\AdvanceResult($events, $r->pending, $r->over);
    }

    private function resolve(GameState $s, CardInstance $card, array $targets = [], int $mode = 0, int $controller = 0): void
    {
        $s->stack[] = new StackItem('st', $card, $controller, $targets, 0, mode: $mode);
        (new StackResolver())->resolveTop($s);
    }

    // --- Correspondance Scryfall -------------------------------------------------------------------------------------------

    public function testCardsOfTheLotAreAcceptedAndTheOthersRefusedWithAReason(): void
    {
        foreach (['Snapback', 'Culling the Weak', 'Diabolic Intent', 'Simian Spirit Guide', "Lion's Eye Diamond", 'Rite of Flame', 'Cabal Ritual', 'Rain of Filth',
            'Mox Opal', 'Mox Amber', 'Starting Town', 'City of Traitors', 'Otawara, Soaring City', 'Pyroblast', 'Red Elemental Blast', 'Gamble', 'Ranger-Captain of Eos'] as $name) {
            self::assertInstanceOf(CardDefinition::class, self::def($name), $name);
        }
        // Misdirection et Commandeer : acceptées depuis le lot H3 (KraumH3CardsTest).
        foreach (['Gemstone Caverns' => 'opening hand', 'Wishclaw Talisman' => 'wish counters'] as $name => $why) {
            try {
                self::def($name);
                self::fail("$name devrait être refusée");
            } catch (UnsupportedCard $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function testNewFieldsRoundTripAndAreOmittedByDefault(): void
    {
        self::assertArrayNotHasKey('extraCost', self::card('Lightning Bolt', 'b')->toArray());
        $culling = self::card('Culling the Weak', 'c');
        self::assertSame(CardInstance::EXTRA_SACRIFICE_CREATURE, CardInstance::fromArray($culling->toArray())->extraCost);
        $town = self::card('Starting Town', 't');
        self::assertSame(3, CardInstance::fromArray($town->toArray())->tappedAfterOwnTurn);
        self::assertSame(CardInstance::ALT_PITCH_BLUE_FREE, CardInstance::fromArray(self::card('Snapback', 's')->toArray())->altCost);
        $pyro = self::card('Pyroblast', 'p');
        self::assertSame([1, 2], CardInstance::fromArray($pyro->toArray())->modes());
        $led = self::card("Lion's Eye Diamond", 'l');
        self::assertTrue(CardInstance::fromArray($led->toArray())->abilities()[0]->discardHand);
        self::assertSame($led->toArray(), CardInstance::fromArray($led->toArray())->toArray());
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->noncreatureLocks = [2];
        $s->player(0)->turnsTaken = 4;
        $s->stack[] = new StackItem('x', $pyro, 0, ['stack:y'], 0, mode: 1);
        $back = GameState::fromArray($s->toArray());
        self::assertSame([2], $back->noncreatureLocks);
        self::assertSame(4, $back->player(0)->turnsTaken);
        self::assertSame(1, $back->stack[0]->mode);
        self::assertSame($s->toArray(), $back->toArray());
    }

    // --- Snapback ------------------------------------------------------------------------------------------------------------

    public function testSnapbackExilesABlueCardWithoutPayingLifeAndBouncesACreature(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Snapback', 'snap'), self::blueSpell('blue'))->creature(1, 'c1', 2, 2)->build(self::J0_HUMAN);
        $alts = array_map(static fn (CastSpell $a) => [$a->targets, $a->alt], $this->casts($s, 'snap'));
        self::assertSame([[['permanent:c1'], 'pitch:blue']], $alts, 'Aucun terrain : seulement le coût alternatif, sur la créature adverse');

        $r = $this->play($s, $this->casts($s, 'snap')[0]);
        self::assertSame(20, $s->player(0)->life, 'Aucun PV payé');
        self::assertSame(['blue'], self::ids($s->player(0)->exile));
        self::assertContains('c1', self::ids($s->player(1)->hand), 'La créature retourne dans la main de son propriétaire');
        self::assertSame([], $s->player(1)->battlefield);
        self::assertCount(1, $this->of($r->events, 'PermanentReturnedToHand'));
    }

    public function testSnapbackOnlyTargetsCreatures(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Snapback', 'snap'), self::blueSpell('blue'))->permanent(1, self::card('Sol Ring', 'ring'))->build(self::J0_HUMAN);
        self::assertSame([], $this->casts($s, 'snap'), 'Pas de créature à viser');
    }

    // --- Culling the Weak / Diabolic Intent ----------------------------------------------------------------------------------

    public function testCullingTheWeakSacrificesACreatureToAddBBBB(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Culling the Weak', 'cull'))->permanent(0, self::land('sw', 'Swamp', 'B'))->creature(0, 'mine', 1, 1)->build(self::J0_HUMAN);
        $casts = $this->casts($s, 'cull');
        self::assertSame(['mine'], array_map(static fn (CastSpell $a) => $a->sacrifice, $casts));

        $r = $this->play($s, $casts[0]);
        self::assertSame(['mine'], array_map(static fn ($e) => $e->payload['card']['id'], $this->of($r->events, 'PermanentSacrificed')));
        self::assertSame(['B' => 4], $this->of($r->events, 'ManaAdded')[0]->payload['mana']);
        self::assertContains('mine', self::ids($s->player(0)->graveyard));
    }

    public function testCullingTheWeakIsNotOfferedWithoutACreatureAndAIgnoresIt(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Culling the Weak', 'cull'))->permanent(0, self::land('sw', 'Swamp', 'B'))->build(self::J0_HUMAN);
        self::assertSame([], $this->casts($s, 'cull'));

        $s = (new ScenarioBuilder())->hand(0, self::card('Culling the Weak', 'cull'))->permanent(0, self::land('sw', 'Swamp', 'B'))->creature(0, 'mine', 1, 1)->build(['ai:heuristic', 'ai:pass', 'ai:pass']);
        $action = (new \App\Engine\Agent\HeuristicAgent())->decide($s, 0, $this->legal($s));
        self::assertInstanceOf(PassPriority::class, $action, 'L\'IA ne sacrifie jamais une créature pour du mana');
    }

    public function testCastRejectsAMissingOrUselessSacrifice(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Culling the Weak', 'cull'), self::card('Lightning Bolt', 'bolt'))->permanent(0, self::land('sw', 'Swamp', 'B'))
            ->creature(0, 'mine', 1, 1)->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $this->expectException(\App\Engine\Decision\IllegalActionException::class);
        $engine->advance($s, new CastSpell(0, 'cull', [], 0, null, 0, null));
    }

    public function testDiabolicIntentSacrificesACreatureToTutorAnyCard(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Diabolic Intent', 'di'))->untappedLands(0, 1)->permanent(0, self::land('sw', 'Swamp', 'B'))->creature(0, 'mine', 1, 1)->build(self::J0_HUMAN);
        $s->player(0)->library = [new CardInstance('t1', 'Titan', CardInstance::CREATURE, 6, power: 6, toughness: 6)];
        $casts = $this->casts($s, 'di');
        self::assertCount(1, $casts);
        self::assertSame(['library:t1'], $casts[0]->targets);
        self::assertSame('mine', $casts[0]->sacrifice);

        $this->play($s, $casts[0]);
        self::assertContains('t1', self::ids($s->player(0)->hand));
        self::assertContains('mine', self::ids($s->player(0)->graveyard));
    }

    // --- Mana : Simian Spirit Guide, Lion's Eye Diamond ---------------------------------------------------------------------

    public function testSimianSpiritGuideExilesItselfFromHandForRedMana(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Simian Spirit Guide', 'ssg'))->build(self::J0_HUMAN);
        $offer = $this->abilities($s, 'ssg');
        self::assertCount(1, $offer);
        self::assertTrue($offer[0]->mana);

        $r = (new GameEngine(new AgentRegistry()))->advance($s, $offer[0]);
        self::assertSame(['R' => 1], $s->player(0)->pool->toArray());
        self::assertSame(['ssg'], self::ids($s->player(0)->exile));
        self::assertSame([], $s->player(0)->hand);
        self::assertSame('hand', $this->of($r->events, 'CardExiled')[0]->payload['from']);
    }

    public function testSimianSpiritGuideCannotBeActivatedFromTheBattlefield(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Simian Spirit Guide', 'ssg'))->build(self::J0_HUMAN);
        self::assertSame([], $this->abilities($s, 'ssg'));
    }

    public function testLionsEyeDiamondDiscardsTheHandAndSacrificesForThreeManaOfOneColor(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::blueSpell('h1'), self::redSpell('h2'))->permanent(0, self::card("Lion's Eye Diamond", 'led'))->build(self::J0_HUMAN);
        $offer = $this->abilities($s, 'led');
        self::assertCount(5, $offer, 'Une alternative par couleur');
        $red = array_values(array_filter($offer, static fn (ActivateAbility $a) => $s->player(0)->findOnBattlefield('led')->abilities()[0]->produced($a->option) === ['R' => 3]));

        (new GameEngine(new AgentRegistry()))->advance($s, $red[0]);
        self::assertSame(['R' => 3], $s->player(0)->pool->toArray());
        self::assertSame([], $s->player(0)->hand, 'Main défaussée');
        self::assertEqualsCanonicalizing(['h1', 'h2', 'led'], self::ids($s->player(0)->graveyard));
        self::assertSame([], $s->player(0)->battlefield);
    }

    public function testLionsEyeDiamondIsNeverUsedByAutoPayment(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::redSpell('h2'))->permanent(0, self::card("Lion's Eye Diamond", 'led'))->build(self::J0_HUMAN);
        self::assertSame([], $this->casts($s, 'h2'), 'LED ne paie jamais un sort tout seul (défausse de la main)');
    }

    public function testLionsEyeDiamondHasNoColorIdentity(): void
    {
        self::assertSame([], self::card("Lion's Eye Diamond", 'led')->colorIdentity());
    }

    // --- Mana : rituels ------------------------------------------------------------------------------------------------------

    public function testRiteOfFlameAddsOneMoreRedPerRiteInGraveyards(): void
    {
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $this->resolve($s, self::card('Rite of Flame', 'r1'));
        self::assertSame(['R' => 2], $s->player(0)->pool->toArray(), 'Seule, elle ne compte pas');

        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->player(0)->graveyard[] = self::card('Rite of Flame', 'old1');
        $s->player(1)->graveyard[] = self::card('Rite of Flame', 'old2');
        $this->resolve($s, self::card('Rite of Flame', 'r2'));
        self::assertSame(['R' => 4], $s->player(0)->pool->toArray(), 'RR + une par Rite dans chaque cimetière (celle qui se résout exclue)');
    }

    public function testCabalRitualNeedsSevenOtherCardsInGraveyardForThreshold(): void
    {
        foreach ([6 => 3, 7 => 5] as $graveyard => $black) {
            $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
            for ($i = 0; $i < $graveyard; $i++) {
                $s->player(0)->graveyard[] = self::redSpell("g$i");
            }
            $this->resolve($s, self::card('Cabal Ritual', 'cr'));
            self::assertSame(['B' => $black], $s->player(0)->pool->toArray(), "$graveyard cartes au cimetière avant la résolution");
        }
    }

    public function testRainOfFilthLetsLandsSacrificeForBlackUntilEndOfTurn(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Rain of Filth', 'rain'))->permanent(0, self::land('sw', 'Swamp', 'B'))->build(self::J0_HUMAN);
        self::assertCount(1, $this->abilities($s, 'sw'), 'Avant : seulement la capacité de mana ordinaire');

        $r = $this->play($s, $this->casts($s, 'rain')[0]);
        self::assertCount(1, $this->of($r->events, 'AbilitiesGranted'));
        $sacrifice = array_values(array_filter($this->abilities($s, 'sw'), static fn (ActivateAbility $a) => $a->abilityIndex === 1));
        self::assertCount(1, $sacrifice);

        // L'état survit à une sérialisation (pause humaine / fork).
        $back = GameState::fromArray($s->toArray());
        self::assertCount(2, $back->player(0)->findOnBattlefield('sw')->abilities());

        (new GameEngine(new AgentRegistry()))->advance($s, $sacrifice[0]);
        self::assertSame(['B' => 1], $s->player(0)->pool->toArray());
        self::assertContains('sw', self::ids($s->player(0)->graveyard));
    }

    public function testRainOfFilthAbilitiesExpireAtCleanup(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::land('sw', 'Swamp', 'B'))->build(self::J0_HUMAN);
        $this->resolve($s, self::card('Rain of Filth', 'rain'));
        self::assertCount(2, $s->player(0)->findOnBattlefield('sw')->abilities());

        $s->phase = Phase::Cleanup;
        $s->stepStarted = false;
        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        self::assertCount(1, $s->player(0)->findOnBattlefield('sw')->abilities());
        self::assertCount(1, $this->of($r->events, 'AbilitiesExpired'));
    }

    // --- Moxen ---------------------------------------------------------------------------------------------------------------

    public function testMoxOpalNeedsMetalcraft(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Mox Opal', 'opal'))->permanent(0, self::card('Sol Ring', 'ring'))->build(self::J0_HUMAN);
        self::assertSame([], $this->abilities($s, 'opal'), 'Deux artefacts seulement');
        self::assertFalse(AbilityManager::requirementsMet($s->player(0), self::card('Mox Opal', 'x')->abilities()[0]));

        $s->player(0)->battlefield[] = self::card('Mana Vault', 'vault');
        self::assertCount(5, $this->abilities($s, 'opal'), 'Trois artefacts : une alternative par couleur');
    }

    public function testMoxAmberOnlyMakesTheColorsOfLegendaryCreatures(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Mox Amber', 'amber'))->build(self::J0_HUMAN);
        self::assertSame([], $this->abilities($s, 'amber'), 'Aucune créature légendaire');

        $legend = new CardInstance('leg', 'Red Legend', CardInstance::CREATURE, 2, manaCost: '{1}{R}', power: 2, toughness: 2, supertypes: [CardInstance::LEGENDARY]);
        $s->player(0)->battlefield[] = $legend;
        $s->player(0)->battlefield[] = new CardInstance('bear', 'Green Bear', CardInstance::CREATURE, 2, manaCost: '{1}{G}', power: 2, toughness: 2);
        $offer = $this->abilities($s, 'amber');
        self::assertCount(1, $offer, 'Seul le rouge de la créature légendaire');
        (new GameEngine(new AgentRegistry()))->advance($s, $offer[0]);
        self::assertSame(['R' => 1], $s->player(0)->pool->toArray());
    }

    // --- Terrains ------------------------------------------------------------------------------------------------------------

    public function testStartingTownEntersTappedAfterTheThirdTurn(): void
    {
        foreach ([3 => false, 4 => true] as $turns => $tapped) {
            $s = (new ScenarioBuilder())->hand(0, self::card('Starting Town', 'town'))->build(self::J0_HUMAN);
            $s->player(0)->turnsTaken = $turns;
            (new GameEngine(new AgentRegistry()))->advance($s, new PlayLand(0, 'town'));
            self::assertSame($tapped, $s->player(0)->findOnBattlefield('town')->tapped, "Tour $turns du joueur");
        }
    }

    public function testStartingTownCanPayLifeForAnyColor(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Starting Town', 'town'))->build(self::J0_HUMAN);
        $offer = $this->abilities($s, 'town');
        self::assertCount(6, $offer, '{C} gratuit, ou une couleur contre 1 PV');
        $blue = array_values(array_filter($offer, static fn (ActivateAbility $a) => $a->abilityIndex === 1 && $s->player(0)->findOnBattlefield('town')->abilities()[1]->produced($a->option) === ['U' => 1]));
        (new GameEngine(new AgentRegistry()))->advance($s, $blue[0]);
        self::assertSame(19, $s->player(0)->life);
        self::assertSame(['U' => 1], $s->player(0)->pool->toArray());
    }

    public function testTurnsTakenCountsEachPlayersOwnTurns(): void
    {
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->phase = Phase::Untap;
        $s->stepStarted = false;
        (new GameEngine(new AgentRegistry()))->advance($s);
        self::assertSame(1, $s->player(0)->turnsTaken);
        self::assertSame(0, $s->player(1)->turnsTaken);
    }

    public function testCityOfTradersIsSacrificedWhenYouPlayAnotherLand(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::land('mtn', 'Mountain', 'R'))->permanent(0, self::card('City of Traitors', 'city'))->build(self::J0_HUMAN);
        $r = $this->play($s, new PlayLand(0, 'mtn'));
        self::assertContains('city', self::ids($s->player(0)->graveyard));
        self::assertContains('mtn', self::ids($s->player(0)->battlefield));
        self::assertCount(1, $this->of($r->events, 'AbilityTriggered'));
    }

    public function testCityOfTradersDoesNotTriggerOnItself(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('City of Traitors', 'city'))->build(self::J0_HUMAN);
        (new GameEngine(new AgentRegistry()))->advance($s, new PlayLand(0, 'city'));
        self::assertContains('city', self::ids($s->player(0)->battlefield));
        $offer = $this->abilities($s, 'city');
        self::assertSame(['{C}{C}'], array_map(static fn (ActivateAbility $a) => $s->player(0)->findOnBattlefield('city')->abilities()[$a->abilityIndex]->toArray()['options'][0], $offer));
    }

    public function testOtawaraChannelBouncesAPermanentAndCostsLessPerLegendaryCreature(): void
    {
        $build = static function (bool $legend): GameState {
            $b = (new ScenarioBuilder())->hand(0, self::card('Otawara, Soaring City', 'ota'))->permanent(0, self::land('is', 'Island', 'U'))->untappedLands(0, 2)->creature(1, 'c1', 2, 2);
            if ($legend) {
                $b->permanent(0, new CardInstance('leg', 'Legend', CardInstance::CREATURE, 1, manaCost: '{1}', power: 1, toughness: 1, supertypes: [CardInstance::LEGENDARY]));
            }

            return $b->build(self::J0_HUMAN);
        };
        // {3}{U} avec 3 terrains (dont une Île) : impayable ; avec une créature légendaire : {2}{U}, payable.
        $channel = static fn (array $as) => array_values(array_filter($as, static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'ota' && $a->abilityIndex === 1));
        $s = $build(false);
        self::assertSame([], $channel($this->legal($s)));

        $s = $build(true);
        $offer = $channel($this->legal($s));
        self::assertNotSame([], $offer);
        self::assertContains(['permanent:c1'], array_map(static fn (ActivateAbility $a) => $a->targets, $offer));

        $target = array_values(array_filter($offer, static fn (ActivateAbility $a) => $a->targets === ['permanent:c1']))[0];
        $this->play($s, $target);
        self::assertSame(['ota'], self::ids($s->player(0)->graveyard), 'Défaussée en coût');
        self::assertContains('c1', self::ids($s->player(1)->hand), 'Le permanent retourne dans la main de son propriétaire');
    }

    public function testOtawaraChannelNeverTargetsLands(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Otawara, Soaring City', 'ota'))->permanent(0, self::land('is', 'Island', 'U'))->untappedLands(0, 4)->build(self::J0_HUMAN);
        $targets = array_map(static fn (ActivateAbility $a) => $a->targets, array_filter($this->abilities($s, 'ota'), static fn (ActivateAbility $a) => $a->abilityIndex === 1));
        self::assertSame([], array_values($targets), 'Aucun artefact, créature ni enchantement à renvoyer');
    }

    // --- Pyroblast / Red Elemental Blast -------------------------------------------------------------------------------------

    public function testPyroblastOffersOneActionPerModeAndTarget(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Pyroblast', 'pyro'))->permanent(0, self::land('mt', 'Mountain', 'R'))->build(self::J0_HUMAN);
        $s->stack[] = new StackItem('x', self::redSpell('x'), 1);
        $actions = array_map(static fn (CastSpell $a) => [$a->mode, $a->targets], $this->casts($s, 'pyro'));
        self::assertEqualsCanonicalizing([[1, ['stack:x']], [2, ['permanent:mt']]], $actions, 'Mode 1 : le sort ; mode 2 : le seul permanent (le sien, non bleu)');

        $s->player(1)->battlefield[] = new CardInstance('art', 'Blue Rock', CardInstance::ARTIFACT, 1, manaCost: '{U}');
        $s->player(1)->battlefield[] = new CardInstance('rock', 'Red Rock', CardInstance::ARTIFACT, 1, manaCost: '{R}');
        $actions = array_map(static fn (CastSpell $a) => [$a->mode, $a->targets], $this->casts($s, 'pyro'));
        self::assertContains([2, ['permanent:art']], $actions);
        self::assertContains([2, ['permanent:rock']], $actions, 'Pyroblast peut viser un permanent non bleu (sans effet)');
    }

    public function testPyroblastCountersOnlyBlueSpells(): void
    {
        foreach ([true, false] as $blue) {
            $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
            $s->stack[] = new StackItem('x', $blue ? self::blueSpell('x') : self::redSpell('x'), 1);
            $this->resolve($s, self::card('Pyroblast', 'pyro'), ['stack:x'], 1);
            self::assertSame($blue ? [] : ['x'], self::ids(array_map(static fn (StackItem $i) => $i->card, $s->stack)), $blue ? 'Sort bleu contré' : 'Sort rouge intact');
        }
    }

    public function testPyroblastDestroysOnlyBluePermanents(): void
    {
        foreach ([true, false] as $blue) {
            $s = (new ScenarioBuilder())->permanent(1, new CardInstance('art', 'Rock', CardInstance::ARTIFACT, 1, manaCost: $blue ? '{U}' : '{R}'))->build(self::J0_HUMAN);
            $this->resolve($s, self::card('Pyroblast', 'pyro'), ['permanent:art'], 2);
            self::assertSame($blue ? [] : ['art'], self::ids($s->player(1)->battlefield));
            self::assertSame($blue ? ['art'] : [], self::ids($s->player(1)->graveyard));
        }
    }

    public function testRedElementalBlastOnlyTargetsBlueObjects(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Red Elemental Blast', 'reb'))->permanent(0, self::land('mt', 'Mountain', 'R'))->build(self::J0_HUMAN);
        $s->stack[] = new StackItem('x', self::redSpell('x'), 1);
        $s->player(1)->battlefield[] = new CardInstance('rock', 'Red Rock', CardInstance::ARTIFACT, 1, manaCost: '{R}');
        self::assertSame([], $this->casts($s, 'reb'), 'Rien de bleu à viser');

        $s->stack[] = new StackItem('y', self::blueSpell('y'), 1);
        $s->player(1)->battlefield[] = new CardInstance('art', 'Blue Rock', CardInstance::ARTIFACT, 1, manaCost: '{U}');
        $actions = array_map(static fn (CastSpell $a) => [$a->mode, $a->targets], $this->casts($s, 'reb'));
        self::assertEqualsCanonicalizing([[1, ['stack:y']], [2, ['permanent:art']]], $actions);
    }

    public function testRedElementalBlastCountersABlueSpellEndToEnd(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Red Elemental Blast', 'reb'))->permanent(0, self::land('mt', 'Mountain', 'R'))->build(self::J0_HUMAN);
        $s->priority = new \App\Engine\Game\PriorityState(0);
        $s->stack[] = new StackItem('y', self::blueSpell('y'), 1);
        $cast = $this->casts($s, 'reb')[0];
        $r = $this->play($s, $cast);
        self::assertCount(1, $this->of($r->events, 'SpellCountered'));
        self::assertContains('y', self::ids($s->player(1)->graveyard));
    }

    public function testTheAiNeverCastsModalSpells(): void
    {
        $s = (new ScenarioBuilder())->hand(1, self::card('Pyroblast', 'pyro'))->permanent(1, self::land('mt', 'Mountain', 'R'))->build(['ai:pass', 'ai:heuristic', 'ai:pass']);
        $s->stack[] = new StackItem('y', self::blueSpell('y'), 0);
        $s->priority = new \App\Engine\Game\PriorityState(1);
        $action = (new \App\Engine\Agent\HeuristicAgent())->decide($s, 1, $this->legal($s, 1));
        self::assertInstanceOf(PassPriority::class, $action);
    }

    // --- Tuteurs : Gamble, Ranger-Captain of Eos ----------------------------------------------------------------------------

    public function testGambleDiscardsARandomCardFromHandAfterTutoring(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::redSpell('h1'), self::redSpell('h2'))->build(self::J0_HUMAN);
        $s->player(0)->library = [new CardInstance('t1', 'Titan', CardInstance::CREATURE, 6, power: 6, toughness: 6)];
        $this->resolve($s, self::card('Gamble', 'gamble'), ['library:t1']);
        self::assertCount(2, $s->player(0)->hand, '2 cartes + la cherchée - 1 défaussée au hasard');
        $discarded = array_values(array_filter($s->player(0)->graveyard, static fn (CardInstance $c) => $c->id !== 'gamble'));
        self::assertCount(1, $discarded);

        // Déterministe : même graine, même défausse.
        $again = (new ScenarioBuilder())->hand(0, self::redSpell('h1'), self::redSpell('h2'))->build(self::J0_HUMAN);
        $again->player(0)->library = [new CardInstance('t1', 'Titan', CardInstance::CREATURE, 6, power: 6, toughness: 6)];
        $this->resolve($again, self::card('Gamble', 'gamble'), ['library:t1']);
        self::assertSame(self::ids($discarded), self::ids(array_values(array_filter($again->player(0)->graveyard, static fn (CardInstance $c) => $c->id !== 'gamble'))));
    }

    public function testGambleStillDiscardsWhenTheLibraryIsEmpty(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::redSpell('h1'))->build(self::J0_HUMAN);
        $s->player(0)->library = [];
        $this->resolve($s, self::card('Gamble', 'gamble'), ['library:none']);
        self::assertSame([], $s->player(0)->hand);
    }

    public function testRangerCaptainFetchesAOneDropCreatureOnEntering(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Ranger-Captain of Eos', 'rc'))->untappedLands(0, 3)->build(self::J0_HUMAN);
        $s->player(0)->hand[0] = new CardInstance('rc', 'Ranger-Captain of Eos', CardInstance::CREATURE, 3, manaCost: '{3}', power: 3, toughness: 3,
            triggers: self::def('Ranger-Captain of Eos')->triggers, abilities: self::def('Ranger-Captain of Eos')->abilities);
        $s->player(0)->library = [
            new CardInstance('titan', 'Titan', CardInstance::CREATURE, 6, power: 6, toughness: 6),
            new CardInstance('bear', 'Bear', CardInstance::CREATURE, 1, manaCost: '{G}', power: 1, toughness: 1),
            new CardInstance('rock', 'Rock', CardInstance::ARTIFACT, 1, manaCost: '{1}'),
        ];
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s, $this->casts($s, 'rc')[0]);
        $engine->advance($s, new PassPriority(0));
        // Une seule carte convient (valeur de mana 1 ou moins, créature) : le trigger est posé sans décision, avec cette cible.
        self::assertCount(1, $s->stack);
        self::assertTrue($s->stack[0]->isTrigger());
        self::assertSame(['library:bear'], $s->stack[0]->targets);

        $engine->advance($s, new PassPriority(0));
        self::assertContains('bear', self::ids($s->player(0)->hand));
        self::assertNotContains('titan', self::ids($s->player(0)->hand));
    }

    public function testRangerCaptainSacrificeForbidsOpponentsNoncreatureSpellsUntilEndOfTurn(): void
    {
        $rc = new CardInstance('rc', 'Ranger-Captain of Eos', CardInstance::CREATURE, 3, manaCost: '{3}', power: 3, toughness: 3, abilities: self::def('Ranger-Captain of Eos')->abilities);
        $s = (new ScenarioBuilder())->permanent(0, $rc)->hand(1, self::redSpell('burn'), new CardInstance('bear', 'Bear', CardInstance::CREATURE, 1, manaCost: '{R}', power: 1, toughness: 1, keywords: [CardInstance::FLASH]))
            ->permanent(1, self::land('mt', 'Mountain', 'R'))->build(self::J0_HUMAN);
        $s->priority = new \App\Engine\Game\PriorityState(0);
        $lock = $this->abilities($s, 'rc');
        self::assertCount(1, $lock);
        self::assertFalse($lock[0]->mana);

        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s, $lock[0]);
        self::assertSame([], $s->player(0)->battlefield, 'Sacrifiée en coût');
        $engine->advance($s, new PassPriority(0));
        self::assertSame([0], $s->noncreatureLocks);
        self::assertSame([], $this->casts($s, 'burn', 1), 'Sort non-créature interdit à l\'adversaire');
        self::assertNotSame([], $this->casts($s, 'bear', 1), 'Les créatures restent permises');

        $s->phase = Phase::Cleanup;
        $s->stepStarted = false;
        $engine->advance($s);
        self::assertSame([], $s->noncreatureLocks, 'Fini au cleanup');
    }
}
