<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\ResolutionChoice;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use App\Engine\Game\TurnManager;
use App\Engine\Rules\CombatManager;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot H5b du deck Kraum / Tymna (étape 14, combos), textes réels de Scryfall : Thassa's Oracle, Demonic Consultation, Tainted
 * Pact, Ad Nauseam, Necropotence, Underworld Breach, Final Fortune, Lim-Dûl's Vault, Scheming Symmetry, Beseech the Mirror.
 */
final class KraumH5bCardsTest extends TestCase
{
    private const HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass'];
    public const LOT = ['Thassa\'s Oracle', 'Demonic Consultation', 'Tainted Pact', 'Ad Nauseam', 'Necropotence', 'Underworld Breach', 'Final Fortune',
        'Lim-Dûl\'s Vault', 'Scheming Symmetry', 'Beseech the Mirror'];

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

    /** @return list<string> */
    private static function names(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->name, $cards);
    }

    /** Bibliothèque de `$player` remplacée par `$n` cartes « Lib i » (valeur de mana i % 4). */
    private static function library(GameState $s, int $player, int $n): void
    {
        $s->player($player)->library = [];
        for ($i = 0; $i < $n; $i++) {
            $s->player($player)->library[] = new CardInstance("p{$player}_c" . (80 + $i), 'Lib ' . $i, CardInstance::SORCERY, $i % 4, manaCost: '{' . ($i % 4) . '}');
        }
    }

    private static function land(string $id, string $color): CardInstance
    {
        return new CardInstance($id, 'Land ' . $color, CardInstance::LAND, produces: [$color]);
    }

    /** @return list<ResolutionChoice> */
    private static function choices(GameState $s, int $player): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, $player), static fn (Action $a) => $a instanceof ResolutionChoice));
    }

    /** @return list<CastSpell> */
    private static function casts(GameState $s, string $cardId): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === $cardId));
    }

    private static function resolveSpell(GameState $s, CardInstance $card, array $targets = [], bool $kicked = false): void
    {
        $s->stack[] = new StackItem('s' . $s->nextStackId++, $card, 0, $targets, kicked: $kicked);
        (new StackResolver())->resolveTop($s);
    }

    public function testLotHasTheirMechanisms(): void
    {
        $this->assertSame('oracle', self::def('Thassa\'s Oracle')->triggers[0]->effect);
        $this->assertSame('consult', self::def('Demonic Consultation')->effects[0]->effect);
        $this->assertSame('tainted_pact', self::def('Tainted Pact')->effects[0]->effect);
        $this->assertSame('ad_nauseam', self::def('Ad Nauseam')->effects[0]->effect);
        $necro = self::def('Necropotence');
        $this->assertSame([CardInstance::STATIC_SKIP_DRAW], $necro->statics);
        $this->assertSame([TriggeredAbility::DISCARD, 'exile_discarded'], [$necro->triggers[0]->event, $necro->triggers[0]->effect]);
        $this->assertSame(['necro', 1], [$necro->abilities[0]->effect, $necro->abilities[0]->lifeCost]);
        $breach = self::def('Underworld Breach');
        $this->assertSame([CardInstance::STATIC_ESCAPE], $breach->statics);
        $this->assertSame([TriggeredAbility::EACH_END_STEP, 'sacrifice_self'], [$breach->triggers[0]->event, $breach->triggers[0]->effect]);
        $this->assertSame(['extra_turn', 1], [self::def('Final Fortune')->effects[0]->effect, self::def('Final Fortune')->effects[0]->amount]);
        $this->assertSame(['vault', 5], [self::def('Lim-Dûl\'s Vault')->effects[0]->effect, self::def('Lim-Dûl\'s Vault')->effects[0]->amount]);
        $this->assertSame('symmetry', self::def('Scheming Symmetry')->effects[0]->effect);
        $beseech = self::def('Beseech the Mirror');
        $this->assertSame([CardInstance::EXTRA_BARGAIN, 'tutor', 4], [$beseech->extraCost, $beseech->effects[0]->effect, $beseech->effects[0]->freeCastMax]);
    }

    public function testThassasOracleWinsWithFewerCardsThanItsDevotion(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        self::library($s, 0, 2);
        $s->enterBattlefield(0, self::card('Thassa\'s Oracle', 'p0_c1')); // dévotion au bleu : {U}{U} = 2
        $this->assertSame(2, $s->devotion(0, 'U'));
        $pending = array_shift($s->pendingTriggers);
        $s->stack[] = new StackItem('s1', $pending->source, 0, trigger: $pending->ability());
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);

        $this->assertTrue($s->over);
        $this->assertSame(0, $s->winner);
        $this->assertSame(['opponent_won', 'opponent_won'], array_map(static fn (GameEvent $e) => $e->payload['reason'], $this->of($s->drainEvents(), 'PlayerLost')));
    }

    public function testThassasOracleKeepsOneCardOnTopAndTheRestOnTheBottom(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 6);
        $s->stack[] = new StackItem('s1', self::card('Thassa\'s Oracle', 'p0_c1'), 0, trigger: new TriggeredAbility(TriggeredAbility::ETB, 'oracle'));
        $s->player(0)->battlefield[] = self::card('Thassa\'s Oracle', 'p0_c1');
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['kind' => 'oracle', 'count' => 2], array_intersect_key($s->selection, ['kind' => 1, 'count' => 1]));
        $this->assertSame(['top:p0_c80', 'top:p0_c81', 'none'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($s, 0)));
        $this->assertSame('Garder Lib 1 sur le dessus, le reste dessous', (new ResolutionChoice(0, 'top:p0_c81'))->describe($s));

        (new StackResolver())->choose($s, new ResolutionChoice(0, 'top:p0_c81'));
        $this->assertNull($s->selection);
        $this->assertFalse($s->over);
        $this->assertSame(['Lib 1', 'Lib 2', 'Lib 3', 'Lib 4', 'Lib 5', 'Lib 0'], self::names($s->player(0)->library));
    }

    public function testDemonicConsultationFindsTheNamedCardAfterExilingSix(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 12);
        $s->player(0)->hand = [$consult = self::card('Demonic Consultation', 'p0_c1')];
        $s->player(0)->battlefield[] = self::land('p0_b', 'B');
        $targets = array_merge(...array_map(static fn (CastSpell $c) => $c->targets, self::casts($s, 'p0_c1')));
        $this->assertSame('name:', $targets[0], 'un nom absent en premier');
        $this->assertContains('name:Lib 9', $targets);
        $this->assertSame('Lancer Demonic Consultation → nom « Lib 9 »', (new CastSpell(0, 'p0_c1', ['name:Lib 9']))->describe($s));

        $s->player(0)->hand = [];
        $s->drainEvents();
        self::resolveSpell($s, $consult, ['name:Lib 9']);
        $this->assertSame(['Lib 9'], self::names($s->player(0)->hand));
        $this->assertSame(['Lib 10', 'Lib 11'], self::names($s->player(0)->library));
        $this->assertCount(9, $s->player(0)->exile, 'six, puis Lib 6 à Lib 8 révélées');
        $this->assertSame('library', $this->of($s->drainEvents(), 'CardPutIntoHand')[0]->payload['from']);
    }

    public function testDemonicConsultationWithAMissingNameExilesTheLibraryForThassasOracle(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        self::library($s, 0, 30);
        $s->player(0)->battlefield[] = $oracle = self::card('Thassa\'s Oracle', 'p0_c1');
        $s->stack[] = new StackItem('s1', $oracle, 0, trigger: new TriggeredAbility(TriggeredAbility::ETB, 'oracle')); // trigger sur la pile, Consultation en réponse
        self::resolveSpell($s, self::card('Demonic Consultation', 'p0_c2'), ['name:']);
        $this->assertSame([], $s->player(0)->library);
        $this->assertCount(30, $s->player(0)->exile);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(0, $s->winner);
    }

    public function testTaintedPactTakesAChosenCardOrStopsOnADuplicateName(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 5);
        self::resolveSpell($s, self::card('Tainted Pact', 'p0_c1'));
        $this->assertSame(['take', 'continue'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($s, 0)));
        $this->assertSame('Ne pas prendre Lib 0 : exiler la carte suivante', (new ResolutionChoice(0, 'continue'))->describe($s));
        $resolver = new StackResolver();
        $resolver->choose($s, new ResolutionChoice(0, 'continue'));
        $resolver->choose($s, new ResolutionChoice(0, 'take'));
        $this->assertNull($s->selection);
        $this->assertSame(['Lib 1'], self::names($s->player(0)->hand));
        $this->assertSame(['Lib 0'], self::names($s->player(0)->exile));

        // Deux cartes homonymes (terrains de base) : la seconde arrête tout, sans choix.
        $s = (new ScenarioBuilder())->build(self::HUMAN); // bibliothèque : dix « Land »
        self::resolveSpell($s, self::card('Tainted Pact', 'p0_c1'));
        (new StackResolver())->choose($s, new ResolutionChoice(0, 'continue'));
        $this->assertNull($s->selection);
        $this->assertCount(2, $s->player(0)->exile);
        $this->assertSame([], $s->player(0)->hand);
    }

    public function testAdNauseamRevealsUntilTheCasterStops(): void
    {
        $s = (new ScenarioBuilder())->life(40)->build(self::HUMAN);
        self::library($s, 0, 6);
        $s->player(0)->hand = [self::card('Ad Nauseam', 'p0_c1')];
        foreach (['B', 'B', 'C', 'C', 'C'] as $i => $color) {
            $s->player(0)->battlefield[] = self::land("p0_l$i", $color);
        }
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s, self::casts($s, 'p0_c1')[0]);
        $engine->advance($s, new PassPriority(0));
        $this->assertSame('ad_nauseam', $s->selection['kind'], 'Lib 0 déjà révélée et mise en main');
        $this->assertSame(['stop', 'continue'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($s, 0)));
        $engine->advance($s, new ResolutionChoice(0, 'continue')); // Lib 1 : 1 PV
        $events = $engine->advance($s, new ResolutionChoice(0, 'continue'))->events; // Lib 2 : 2 PV
        $this->assertSame('ad_nauseam', $s->selection['kind']);
        $this->assertSame([37], array_map(static fn (GameEvent $e) => $e->payload['life'], $this->of($events, 'LifeLost')));
        $this->assertSame([], $this->of($events, 'CardDrawn'), 'mise en main, pas une pioche');
        $engine->advance($s, new ResolutionChoice(0, 'stop'));
        $this->assertNull($s->selection);
        $this->assertSame(['Lib 0', 'Lib 1', 'Lib 2'], self::names($s->player(0)->hand));
        $this->assertSame(37, $s->player(0)->life);
    }

    public function testNecropotenceSkipsTheDrawStepAndDeliversAtTheEndStep(): void
    {
        $s = (new ScenarioBuilder())->life(40)->build(self::HUMAN);
        self::library($s, 0, 5);
        $s->player(0)->battlefield[] = $necro = self::card('Necropotence', 'p0_c1');
        $turns = new TurnManager(new CombatManager(new AgentRegistry(), new LegalActionGenerator()));
        $s->phase = Phase::Upkeep;
        $turns->endStep($s);
        $this->assertSame(Phase::Main1, $s->phase, 'étape de pioche passée');
        $this->assertSame('draw', $this->of($s->drainEvents(), 'StepSkipped')[0]->payload['phase']);

        $necroAbility = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'p0_c1'));
        $this->assertCount(1, $necroAbility);
        (new GameEngine(new AgentRegistry()))->advance($s, $necroAbility[0]);
        $this->assertSame(39, $s->player(0)->life);
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['Lib 0'], self::names($s->player(0)->exile));
        $this->assertTrue($this->of($s->drainEvents(), 'CardExiled')[0]->payload['faceDown']);
        $this->assertCount(1, $s->delayedTriggers);

        $s->phase = Phase::End;
        $s->stepStarted = false;
        $turns->beginStep($s);
        $pending = array_shift($s->pendingTriggers);
        $this->assertSame('necro_return', $pending->ability()->effect);
        $s->stack[] = new StackItem('s9', $pending->source, 0, trigger: $pending->ability());
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['Lib 0'], self::names($s->player(0)->hand));
        $this->assertSame([], $s->player(0)->exile);

        // « Whenever you discard a card, exile that card from your graveyard. »
        $s->discard(0, 'p0_c80');
        $this->assertSame([['card:p0_c80']], array_map(static fn ($a) => $a->targets, (new LegalActionGenerator())->triggerActions($s, 0)));
        $pending = array_shift($s->pendingTriggers);
        $s->stack[] = new StackItem('s10', $necro, 0, ['card:p0_c80'], trigger: $pending->ability());
        (new StackResolver())->resolveTop($s);
        $this->assertSame([], $s->player(0)->graveyard);
        $this->assertSame(['Lib 0'], self::names($s->player(0)->exile));
    }

    public function testUnderworldBreachGrantsEscapeAndIsSacrificedAtEachEndStep(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->build(self::HUMAN);
        $s->player(0)->battlefield[] = self::land('p0_r', 'R');
        $s->player(0)->battlefield[] = self::card('Underworld Breach', 'p0_c1');
        $bolt = ScenarioBuilder::bolt('p0_c2');
        $s->player(0)->graveyard = [new CardInstance('p0_c3', 'Old', CardInstance::SORCERY, 1), new CardInstance('p0_c4', 'Swamp', CardInstance::LAND, produces: ['B']), $bolt,
            new CardInstance('p0_c5', 'Milled 1', CardInstance::SORCERY, 1), new CardInstance('p0_c6', 'Milled 2', CardInstance::SORCERY, 1)];
        $escapes = array_values(array_filter(self::casts($s, 'p0_c2'), static fn (CastSpell $c) => $c->alt === CastSpell::ALT_ESCAPE));
        $this->assertNotEmpty($escapes);
        $this->assertSame([], array_filter(self::casts($s, 'p0_c4')), 'un terrain n\'a pas l\'escape');
        $this->assertStringContainsString('(escape {1} et exil de trois autres cartes du cimetière)', $escapes[0]->describe($s));

        $events = (new GameEngine(new AgentRegistry()))->advance($s, $escapes[0])->events;
        $this->assertSame(['Swamp', 'Milled 2', 'Milled 1'], self::names($s->player(0)->exile), 'les terrains, puis les plus récentes');
        $this->assertSame(['Old'], self::names($s->player(0)->graveyard));
        $this->assertSame('escape', $this->of($events, 'SpellCast')[0]->payload['altCast']);
        $this->assertSame(['graveyard', 'graveyard', 'graveyard'], array_map(static fn (GameEvent $e) => $e->payload['from'], $this->of($events, 'CardExiled')));
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['Old', 'Lightning Bolt'], self::names($s->player(0)->graveyard), 'un sort lancé par escape retourne au cimetière');

        // Étape de fin d'un autre joueur : le trigger de Breach se déclenche quand même.
        $s->activePlayer = 1;
        $s->phase = Phase::End;
        $s->stepStarted = false;
        (new TurnManager(new CombatManager(new AgentRegistry(), new LegalActionGenerator())))->beginStep($s);
        $this->assertSame([[0, 'sacrifice_self']], array_map(static fn ($p) => [$p->controller, $p->ability()->effect], $s->pendingTriggers));
    }

    public function testFinalFortuneGivesAnExtraTurnThenTheCasterLoses(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        self::resolveSpell($s, self::card('Final Fortune', 'p0_c1'));
        $this->assertSame(1, $this->of($s->drainEvents(), 'ExtraTurnAdded')[0]->payload['pending']);
        $this->assertSame(0, GameState::fromArray($s->toArray())->extraTurns[0]['player'], 'sérialisé');

        $turns = new TurnManager(new CombatManager(new AgentRegistry(), new LegalActionGenerator()));
        $s->phase = Phase::Cleanup;
        $turns->endStep($s);
        $this->assertSame([0, 2, []], [$s->activePlayer, $s->turn, $s->extraTurns], 'J0 rejoue au lieu de J1');
        $this->assertSame(0, $this->of($s->drainEvents(), 'ExtraTurnStarted')[0]->payload['player']);
        $this->assertSame('lose_game', $s->delayedTriggers[0]->ability()->effect);

        $s->phase = Phase::End;
        $s->stepStarted = false;
        $turns->beginStep($s);
        $pending = array_shift($s->pendingTriggers);
        $s->stack[] = new StackItem('s9', $pending->source, 0, trigger: $pending->ability());
        (new StackResolver())->resolveTop($s);
        $this->assertTrue($s->player(0)->lost);

        $s->phase = Phase::Cleanup;
        $turns->endStep($s);
        $this->assertSame(1, $s->activePlayer, 'le tour normal suivant reprend');
    }

    public function testLimDulsVaultPaysLifeToLookAgainThenKeepsTheChosenCardOnTop(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 12);
        self::resolveSpell($s, self::card('Lim-Dûl\'s Vault', 'p0_c1'));
        $this->assertSame(['top:p0_c80', 'top:p0_c81', 'top:p0_c82', 'top:p0_c83', 'top:p0_c84', 'continue'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($s, 0)));
        $resolver = new StackResolver();
        $resolver->choose($s, new ResolutionChoice(0, 'continue'));
        $this->assertSame(19, $s->player(0)->life);
        $this->assertSame('Garder ces cartes, Lib 7 sur le dessus', (new ResolutionChoice(0, 'top:p0_c87'))->describe($s));
        $resolver->choose($s, new ResolutionChoice(0, 'top:p0_c87'));
        $this->assertNull($s->selection);
        $this->assertSame(['Lib 7', 'Lib 5', 'Lib 6', 'Lib 8', 'Lib 9'], self::names(array_slice($s->player(0)->library, 0, 5)));
        $this->assertCount(12, $s->player(0)->library);
    }

    public function testSchemingSymmetryLetsEachTargetPlayerPutACardOnTop(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 5);
        self::library($s, 2, 5);
        $s->player(0)->hand = [self::card('Scheming Symmetry', 'p0_c1')];
        $s->player(0)->battlefield[] = self::land('p0_b', 'B');
        $this->assertSame([['players:0+1'], ['players:0+2'], ['players:1+2']], array_map(static fn (CastSpell $c) => $c->targets, self::casts($s, 'p0_c1')));

        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s, new CastSpell(0, 'p0_c1', ['players:0+2']));
        $engine->advance($s, new PassPriority(0));
        $this->assertSame(['player' => 0, 'kind' => 'symmetry', 'queue' => [2]], array_intersect_key($s->selection, ['player' => 1, 'kind' => 1, 'queue' => 1]));
        $this->assertCount(5, self::choices($s, 0));
        $engine->advance($s, new ResolutionChoice(0, 'card:p0_c83')); // J2 (ai:pass) cherche à son tour, sa première carte
        $this->assertNull($s->selection);
        $this->assertSame('Lib 3', $s->player(0)->library[0]->name);
        $this->assertSame('Lib 0', $s->player(2)->library[0]->name);
    }

    public function testBeseechTheMirrorBargainedCastsTheFoundCardForFree(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 1)->build(self::HUMAN);
        self::library($s, 0, 5);
        $s->player(0)->library[] = $oracle = self::card('Thassa\'s Oracle', 'p0_c90');
        $s->player(0)->hand = [self::card('Beseech the Mirror', 'p0_c1')];
        foreach (['B', 'B', 'B'] as $i => $color) {
            $s->player(0)->battlefield[] = self::land("p0_s$i", $color);
        }
        $treasure = $s->createTreasure(0);
        $variants = array_values(array_filter(self::casts($s, 'p0_c1'), static fn (CastSpell $c) => $c->targets === ['library:p0_c90']));
        $this->assertSame([null, $treasure->id], array_map(static fn (CastSpell $c) => $c->sacrifice, $variants));
        $this->assertStringContainsString('(bargain : sacrifier Treasure)', $variants[1]->describe($s));

        $engine = new GameEngine(new AgentRegistry());
        $cast = $engine->advance($s, $variants[1])->events;
        $this->assertTrue($this->of($cast, 'SpellCast')[0]->payload['bargained']);
        $engine->advance($s, new PassPriority(0));
        $this->assertSame(['cast', 'hand'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($s, 0)));
        $this->assertSame('Lancer Thassa\'s Oracle sans payer son coût de mana', (new ResolutionChoice(0, 'cast'))->describe($s));
        $events = $engine->advance($s, new ResolutionChoice(0, 'cast'))->events;
        $free = $this->of($events, 'SpellCast')[0]->payload;
        $this->assertSame(['Thassa\'s Oracle', true, 0], [$free['card']['name'], $free['free'], $free['cost']]);
        $this->assertSame($oracle->id, $s->stack[0]->card->id);
        $this->assertSame([], $s->player(0)->exile);

        // Sans bargain : la carte trouvée va en main.
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 5);
        self::resolveSpell($s, self::card('Beseech the Mirror', 'p0_c1'), ['library:p0_c82']);
        $this->assertNull($s->selection);
        $this->assertSame(['Lib 2'], self::names($s->player(0)->hand));
    }

    public function testSelectionsSurviveARoundTrip(): void
    {
        $s = (new ScenarioBuilder())->build(self::HUMAN);
        self::library($s, 0, 5);
        self::resolveSpell($s, self::card('Tainted Pact', 'p0_c1'));
        $copy = GameState::fromArray($s->toArray());
        $this->assertSame($s->selection, $copy->selection);
        $this->assertSame(['take', 'continue'], array_map(static fn (ResolutionChoice $c) => $c->choice, self::choices($copy, 0)));
    }

    /** Deck Kraum / Tymna de démonstration : les 10 cartes du lot, quelques staples (Brain Freeze, LED), terrains. */
    public static function lotDeck(): LoadedDeck
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach ([...self::LOT, 'Brain Freeze', 'Lion\'s Eye Diamond', 'Sol Ring', 'Counterspell', 'Brainstorm', 'Command Tower', 'Rhystic Study', 'Demonic Tutor', 'Polluted Delta',
            'City of Brass', 'Mana Confluence', 'Dark Ritual'] as $name) {
            $library[] = $m->map($f[$name]);
        }
        foreach (['Island', 'Swamp', 'Plains'] as $basic) {
            for ($i = 0; $i < 28; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }

        return new LoadedDeck($m->map($f['Kraum, Ludevic\'s Opus']), array_slice($library, 0, 98), partner: $m->map($f['Tymna the Weaver']));
    }

    public function testFourAiGameWithTheLotPlaysToTheEnd(): void
    {
        $deck = self::lotDeck();
        $state = GameFactory::create(14, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));
        $types = [];
        foreach ((new GameEngine(new AgentRegistry()))->advance($state)->events as $e) {
            $types[$e->type] = true;
        }
        self::assertArrayHasKey('GameOver', $types);
    }
}
