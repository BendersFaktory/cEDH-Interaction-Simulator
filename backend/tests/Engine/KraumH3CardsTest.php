<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ChainCopy;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\PassPriority;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\AdvanceResult;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Game\PendingTrigger;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot H3 (deck Kraum / Tymna) : pile et restrictions. Changer une cible (Deflecting Swat, Misdirection), prendre un sort
 * (Commandeer), Chain of Vapor, Storm (Flusterstorm, Brain Freeze), « ne peut pas lancer » (Silence, Orim's Chant, Lavinia,
 * Voice of Victory), flash accordé (Borne Upon a Wind, Valley Floodcaller), Mindbreak Trap, Gift (Into the Flood Maw).
 */
final class KraumH3CardsTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];
    private const LOT = ['Deflecting Swat', 'Misdirection', 'Commandeer', 'Chain of Vapor', 'Flusterstorm', 'Brain Freeze', 'Silence', "Orim's Chant",
        'Lavinia, Azorius Renegade', 'Voice of Victory', 'Borne Upon a Wind', 'Valley Floodcaller', 'Mindbreak Trap', 'Into the Flood Maw'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    private static function land(string $id, string $color, string $name = 'Land'): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
    }

    private static function blueSpell(string $id, string $name = 'Blue Spell'): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::INSTANT, 2, manaCost: '{U}{U}', effects: [new SpellEffect('draw', 1)]);
    }

    private static function bolt(string $id): CardInstance
    {
        return self::card('Lightning Bolt', $id);
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

    /** Joue l'action puis passe la priorité de J0 (les IA passent) tant que la pile n'est pas vide. */
    private function play(GameState $s, Action $action): AdvanceResult
    {
        $engine = new GameEngine(new AgentRegistry());
        $r = $engine->advance($s, $action);
        $events = $r->events;
        for ($i = 0; $i < 20 && $s->stack !== [] && $r->pending !== null && $r->pending->player === 0 && $s->selection === null; $i++) {
            $r = $engine->advance($s, new PassPriority(0));
            $events = [...$events, ...$r->events];
        }

        return new AdvanceResult($events, $r->pending, $r->over);
    }

    /** Met un sort de `$controller` sur la pile (sans le lancer) et donne la priorité à J0. */
    private static function onStack(GameState $s, string $id, CardInstance $card, int $controller, array $targets = []): StackItem
    {
        $item = new StackItem($id, $card, $controller, $targets);
        $s->stack[] = $item;

        return $item;
    }

    // --- Correspondance Scryfall et sérialisation ----------------------------------------------------------------------------

    public function testCardsOfTheLotAreAcceptedWithTheirMechanisms(): void
    {
        foreach (self::LOT as $name) {
            self::assertInstanceOf(CardDefinition::class, self::def($name), $name);
        }
        self::assertSame(CardInstance::ALT_PITCH_TWO_BLUE, self::def('Commandeer')->altCost);
        self::assertSame(CardInstance::ALT_OPPONENT_THREE_SPELLS, self::def('Mindbreak Trap')->altCost);
        self::assertSame('{W}', self::def("Orim's Chant")->kicker);
        self::assertSame([CardInstance::STATIC_OPPONENTS_MV_LANDS], self::def('Lavinia, Azorius Renegade')->statics);
        self::assertSame([CardInstance::STATIC_OPPONENTS_NO_CAST_YOUR_TURN], self::def('Voice of Victory')->statics);
        self::assertSame([CardInstance::STATIC_NONCREATURE_FLASH], self::def('Valley Floodcaller')->statics);
        self::assertContains(CardInstance::STORM, self::def('Flusterstorm')->keywords);
        self::assertSame('instant|sorcery', self::def('Flusterstorm')->effects[0]->target);
        self::assertSame([1, 2], self::card('Into the Flood Maw', 'm')->modes());
    }

    public function testNewFieldsRoundTripAndAreOmittedByDefault(): void
    {
        $bolt = self::bolt('b');
        self::assertArrayNotHasKey('statics', $bolt->toArray());
        self::assertArrayNotHasKey('kicker', $bolt->toArray());
        foreach (['Lavinia, Azorius Renegade', "Orim's Chant", 'Chain of Vapor', 'Into the Flood Maw'] as $name) {
            $card = self::card($name, 'x');
            self::assertSame($card->toArray(), CardInstance::fromArray($card->toArray())->toArray(), $name);
            $def = self::def($name);
            self::assertEquals($def, CardDefinition::fromArray($def->toArray()), $name);
        }

        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->castLocks = [1, 2];
        $s->flashGrants = [0];
        $s->attackLocked = true;
        $s->stack[] = new StackItem('c', $bolt, 1, ['player:2'], copy: true, kicked: true, owner: 0);
        $s->pendingTriggers[] = new PendingTrigger('tr1', 0, self::card('Lavinia, Azorius Renegade', 'lav'), 1, opponent: 1, stackId: 's9');
        $back = GameState::fromArray($s->toArray());
        self::assertSame($s->toArray(), $back->toArray());
        self::assertTrue($back->stack[0]->copy);
        self::assertSame(0, $back->stack[0]->ownerId());
        self::assertSame('s9', $back->pendingTriggers[0]->stackId);
        $plain = (new ScenarioBuilder())->build(self::J0_HUMAN)->toArray();
        foreach (['castLocks', 'flashGrants', 'attackLocked'] as $key) {
            self::assertArrayNotHasKey($key, $plain);
        }
    }

    // --- « Ne peut pas lancer » --------------------------------------------------------------------------------------------

    public function testSilenceStopsOpponentsFromCastingThisTurnOnly(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Silence', 'sil'), self::bolt('b0'))->permanent(0, self::land('w', 'W'))->permanent(0, self::land('r', 'R'))
            ->hand(1, self::bolt('b1'))->permanent(1, self::land('r1', 'R'))->build(self::J0_HUMAN);
        $r = $this->play($s, $this->casts($s, 'sil')[0]);
        self::assertSame([1, 2, 3], $s->castLocks);
        self::assertSame([1, 2, 3], $this->of($r->events, 'CastLockApplied')[0]->payload['players']);
        $s->priority = new PriorityState(1);
        self::assertSame([], $this->casts($s, 'b1', 1), 'J1 ne peut plus lancer de sort');
        $s->priority = new PriorityState(0);
        self::assertNotSame([], $this->casts($s, 'b0'), 'Le lanceur, si');

        $engine = new GameEngine(new AgentRegistry());
        $this->expectException(\App\Engine\Decision\IllegalActionException::class);
        $s->priority = new PriorityState(1);
        $s->controllers[1] = 'human';
        $engine->advance($s, new CastSpell(1, 'b1', ['player:0']));
    }

    public function testCastLocksEndAtCleanup(): void
    {
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->castLocks = [1];
        $s->flashGrants = [0];
        $s->attackLocked = true;
        $s->phase = Phase::Cleanup;
        (new \App\Engine\Game\TurnManager(new \App\Engine\Rules\CombatManager(new AgentRegistry(), new LegalActionGenerator())))->beginStep($s);
        self::assertSame([], $s->castLocks);
        self::assertSame([], $s->flashGrants);
        self::assertFalse($s->attackLocked);
    }

    public function testOrimsChantTargetsAPlayerAndItsKickerStopsAttacks(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card("Orim's Chant", 'oc'))->permanent(0, self::land('w1', 'W'))->permanent(0, self::land('w2', 'W'))
            ->creature(0, 'att', 2, 2)->build(self::J0_HUMAN);
        $casts = $this->casts($s, 'oc');
        self::assertCount(8, $casts, 'Quatre joueurs, sans ou avec kicker');
        $kicked = array_values(array_filter($casts, static fn (CastSpell $c) => $c->kicked && $c->targets === ['player:2']))[0];
        self::assertStringContainsString('kicker {W}', $kicked->describe($s));

        $r = $this->play($s, $kicked);
        self::assertSame([2], $s->castLocks);
        self::assertTrue($s->attackLocked);
        self::assertTrue($this->of($r->events, 'SpellCast')[0]->payload['kicked']);
        self::assertSame([], (new LegalActionGenerator())->attackActions($s, 0), 'Aucune créature ne peut attaquer');

        $s = (new ScenarioBuilder())->hand(0, self::card("Orim's Chant", 'oc'))->permanent(0, self::land('w1', 'W'))->build(self::J0_HUMAN);
        self::assertSame([false], array_values(array_unique(array_map(static fn (CastSpell $c) => $c->kicked, $this->casts($s, 'oc')))), 'Kicker impayable');
        $this->play($s, $this->casts($s, 'oc')[1]);
        self::assertFalse($s->attackLocked, 'Sans kicker, les attaques restent permises');
    }

    public function testLaviniaLimitsNoncreatureSpellsToTheLandsOfTheCaster(): void
    {
        $divination = new CardInstance('div', 'Divination', CardInstance::SORCERY, 3, manaCost: '{2}{U}', effects: [new SpellEffect('draw', 2)]);
        $bear = new CardInstance('bear', 'Bear', CardInstance::CREATURE, 3, manaCost: '{2}{G}', power: 2, toughness: 2);
        $s = (new ScenarioBuilder())->hand(0, $divination, $bear, self::card('Sol Ring', 'bolt'))->untappedLands(0, 2)->permanent(0, self::land('u', 'U'))
            ->permanent(1, self::card('Lavinia, Azorius Renegade', 'lav'))->build(self::J0_HUMAN);
        $s->player(0)->battlefield[] = self::land('g', 'G');
        self::assertNotSame([], $this->casts($s, 'div'), 'Valeur de mana 3 pour 4 terrains');
        array_pop($s->player(0)->battlefield);
        array_pop($s->player(0)->battlefield);
        self::assertSame([], $this->casts($s, 'div'), 'Valeur de mana 3 pour 2 terrains : interdit');
        self::assertNotSame([], $this->casts($s, 'bolt'), 'Valeur de mana 1 : permis');
        $s->player(0)->battlefield[] = self::land('g', 'G');
        self::assertNotSame([], $this->casts($s, 'bear'), 'Une créature n\'est jamais limitée');
    }

    public function testLaviniaCountersASpellCastWithoutMana(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Force of Will', 'fow'), self::blueSpell('blue'))->permanent(1, self::card('Lavinia, Azorius Renegade', 'lav'))
            ->build(self::J0_HUMAN);
        $s->player(0)->battlefield = array_fill(0, 5, null);
        $s->player(0)->battlefield = [self::land('l1', 'U'), self::land('l2', 'U'), self::land('l3', 'U'), self::land('l4', 'U'), self::land('l5', 'U')];
        $victim = self::onStack($s, 'v', self::bolt('vb'), 2, ['player:0']);
        $pitch = array_values(array_filter($this->casts($s, 'fow'), static fn (CastSpell $c) => $c->alt !== null))[0];
        $r = $this->play($s, $pitch);
        $triggered = $this->of($r->events, 'AbilityTriggered');
        self::assertSame('Lavinia, Azorius Renegade', $triggered[0]->payload['card']['name']);
        self::assertSame(['stack:s1'], $triggered[0]->payload['targets']);
        $countered = $this->of($r->events, 'SpellCountered');
        self::assertSame('fow', $countered[0]->payload['card']['id'], 'Force of Will, lancée sans mana, est contrée');
        self::assertContains('fow', self::ids($s->player(0)->graveyard));
        self::assertNotNull($victim);

        $s = (new ScenarioBuilder())->hand(0, self::bolt('b'))->permanent(0, self::land('r', 'R'))->permanent(1, self::card('Lavinia, Azorius Renegade', 'lav'))->build(self::J0_HUMAN);
        $r = $this->play($s, $this->casts($s, 'b')[0]);
        self::assertSame([], $this->of($r->events, 'AbilityTriggered'), 'Sort payé en mana : pas de trigger');
    }

    public function testVoiceOfVictoryStopsOpponentsDuringItsControllersTurnAndMobilizes(): void
    {
        $s = (new ScenarioBuilder())->hand(1, self::bolt('b1'))->permanent(1, self::land('r1', 'R'))->permanent(0, self::card('Voice of Victory', 'voice'))
            ->build(self::J0_HUMAN);
        self::assertSame([], $this->casts($s, 'b1', 1), 'Tour de J0 : J1 ne peut pas lancer de sort');
        $s->activePlayer = 2;
        self::assertNotSame([], $this->casts($s, 'b1', 1), 'Tour de J2 : permis');

        // Mobilize 2 : deux Warrior engagés et attaquants, sacrifiés au début de l'étape de fin.
        $s = (new ScenarioBuilder())->permanent(0, self::card('Voice of Victory', 'voice'))->build([0 => 'human', 1 => 'ai:pass']);
        $s->turn = 2;
        $engine = new GameEngine(new AgentRegistry());
        $s->phase = Phase::DeclareAttackers;
        $s->stepStarted = false;
        $r = $engine->advance($s);
        $events = $r->events;
        $r = $engine->advance($s, new DeclareAttacker(0, 'voice', 1));
        $events = [...$events, ...$r->events];
        for ($i = 0; $i < 60 && $r->pending !== null && !$s->over && $s->turn === 2; $i++) {
            $legal = $this->legal($s, $r->pending->player);
            $pick = null;
            foreach ([\App\Engine\Action\FinishDeclaration::class, PassPriority::class] as $kind) {
                foreach ($legal as $a) {
                    $pick ??= $a instanceof $kind ? $a : null;
                }
            }
            $r = $engine->advance($s, $pick ?? $legal[0]);
            $events = [...$events, ...$r->events];
        }
        $tokens = array_values(array_filter($this->of($events, 'TokenCreated'), static fn (GameEvent $e) => $e->payload['card']['name'] === 'Warrior'));
        self::assertCount(2, $tokens);
        self::assertSame(1, $tokens[0]->payload['attacking']);
        self::assertTrue($tokens[0]->payload['tapped']);
        self::assertSame(20 - 1 - 1 - 1, $s->player(1)->life, 'Voice (1) et deux Warrior (1 chacun) infligent leurs dégâts');
        $sacrificed = array_map(static fn (GameEvent $e) => $e->payload['card']['name'], $this->of($events, 'PermanentSacrificed'));
        self::assertSame(['Warrior', 'Warrior'], $sacrificed, 'Sacrifiés à l\'étape de fin');
    }

    // --- Flash accordé -----------------------------------------------------------------------------------------------------

    public function testBorneUponAWindLetsItsCasterCastSpellsAtInstantSpeedThisTurn(): void
    {
        $bear = new CardInstance('bear', 'Bear', CardInstance::CREATURE, 1, manaCost: '{G}', power: 2, toughness: 2);
        $s = (new ScenarioBuilder())->hand(0, self::card('Borne Upon a Wind', 'borne'), $bear)->permanent(0, self::land('u', 'U'))->permanent(0, self::land('u2', 'U'))
            ->permanent(0, self::land('g', 'G'))->build(self::J0_HUMAN);
        $s->phase = Phase::Upkeep;
        self::assertSame([], $this->casts($s, 'bear'), 'Pas pendant l\'entretien');
        $r = $this->play($s, $this->casts($s, 'borne')[0]);
        self::assertSame([0], $s->flashGrants);
        self::assertCount(1, $this->of($r->events, 'FlashGranted'));
        self::assertCount(1, $this->of($r->events, 'CardDrawn'));
        self::assertNotSame([], $this->casts($s, 'bear'), 'Flash accordé');
    }

    public function testValleyFloodcallerGivesFlashToNoncreatureSpellsAndPumpsOtters(): void
    {
        $ponder = self::card('Ponder', 'ponder');
        $bear = new CardInstance('bear', 'Bear', CardInstance::CREATURE, 1, manaCost: '{G}', power: 2, toughness: 2);
        $s = (new ScenarioBuilder())->hand(0, $ponder, $bear)->permanent(0, self::land('u', 'U'))->permanent(0, self::land('g', 'G'))
            ->permanent(0, self::card('Valley Floodcaller', 'otter'))->build(self::J0_HUMAN);
        $s->player(0)->findOnBattlefield('otter')->tapped = true;
        $s->phase = Phase::Upkeep;
        self::assertSame([], $this->casts($s, 'bear'), 'Une créature reste en rituel');
        $r = $this->play($s, $this->casts($s, 'ponder')[0]);
        $otter = $s->player(0)->findOnBattlefield('otter');
        self::assertSame([3, 3], [$otter->currentPower(), $otter->currentToughness()], 'La loutre gagne +1/+1');
        self::assertFalse($otter->tapped, 'et se dégage');
        self::assertCount(1, array_filter($this->of($r->events, 'AbilityTriggered'), static fn (GameEvent $e) => $e->payload['card']['name'] === 'Valley Floodcaller'));
    }

    // --- Storm -------------------------------------------------------------------------------------------------------------

    public function testBrainFreezeIsCopiedOncePerSpellCastBeforeItThisTurn(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Brain Freeze', 'bf'))->permanent(0, self::land('u', 'U'))->permanent(0, self::land('u2', 'U'))->build(self::J0_HUMAN);
        for ($i = 0; $i < 20; $i++) {
            $s->player(1)->library[] = new CardInstance("p1_more$i", 'Land', CardInstance::LAND);
        }
        $s->spellsCast = [0 => 1, 2 => 1];
        $cast = array_values(array_filter($this->casts($s, 'bf'), static fn (CastSpell $c) => $c->targets === ['player:1']))[0];
        $r = $this->play($s, $cast);
        $copies = $this->of($r->events, 'SpellCopied');
        self::assertCount(2, $copies, 'Deux sorts lancés avant lui ce tour');
        self::assertSame(['player:1'], $copies[0]->payload['targets'], 'Les copies gardent la cible');
        self::assertCount(3, $this->of($r->events, 'CardsMilled'));
        self::assertSame(30 - 9, count($s->player(1)->library));
        self::assertSame(['none', 'none', 'graveyard'], array_map(static fn (GameEvent $e) => $e->payload['destination'], $this->of($r->events, 'SpellResolved')), 'Les copies cessent d\'exister');
        self::assertSame(['bf'], self::ids($s->player(0)->graveyard));
        self::assertSame(3, $s->spellsCast[0] + $s->spellsCast[2] - 0, 'Une copie n\'est pas lancée');
    }

    public function testFlusterstormCopiesTargetTheSameSpellAndAreNotCast(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Flusterstorm', 'fl'))->permanent(0, self::land('u', 'U'))->build(self::J0_HUMAN);
        $s->spellsCast = [1 => 1];
        self::onStack($s, 'v', self::bolt('vb'), 1, ['player:0']);
        $casts = $this->casts($s, 'fl');
        self::assertSame([['stack:v']], array_map(static fn (CastSpell $c) => $c->targets, $casts));
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s, $casts[0]);
        self::assertSame('storm', $s->topOfStack()?->trigger?->effect, 'Le trigger Storm est au-dessus de Flusterstorm');
        $engine->advance($s, new PassPriority(0));
        self::assertSame(['v', 's1', 's3'], array_map(static fn (StackItem $i) => $i->id, $s->stack), 'Le sort, Flusterstorm, puis sa copie');
        self::assertTrue($s->stack[2]->copy);
        self::assertSame(['stack:v'], $s->stack[2]->targets);
        self::assertSame(1, $s->spellsCast[0]);

        $s = (new ScenarioBuilder())->hand(0, self::card('Flusterstorm', 'fl'))->permanent(0, self::land('u', 'U'))->build(self::J0_HUMAN);
        self::onStack($s, 'v', self::bolt('vb'), 1, ['player:0']);
        (new GameEngine(new AgentRegistry()))->advance($s, $this->casts($s, 'fl')[0]);
        self::assertCount(2, $s->stack, 'Premier sort du tour : aucune copie');
    }

    public function testACounteredCopyCeasesToExist(): void
    {
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $copy = new StackItem('c', self::bolt('b'), 1, ['player:0'], copy: true);
        $s->stack[] = $copy;
        $s->stack[] = new StackItem('cs', self::card('Counterspell', 'cs'), 0, ['stack:c']);
        (new StackResolver())->resolveTop($s);
        self::assertSame([], $s->player(1)->graveyard);
        self::assertSame(['cs'], self::ids($s->player(0)->graveyard));
    }

    // --- Changer de cible, prendre un sort ---------------------------------------------------------------------------------

    public function testDeflectingSwatRedirectsASpellToANewTarget(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Deflecting Swat', 'swat'))->commander(0, new CardInstance('cmd', 'Test Commander', CardInstance::CREATURE, 3, commander: true, power: 3, toughness: 3))
            ->build(self::J0_HUMAN);
        $s->player(0)->command = [];
        $s->player(0)->battlefield[] = new CardInstance('cmd', 'Test Commander', CardInstance::CREATURE, 3, commander: true, power: 3, toughness: 3);
        self::onStack($s, 'v', self::bolt('vb'), 1, ['player:0']);
        $casts = $this->casts($s, 'swat');
        self::assertSame(['stack:v>player:1', 'stack:v>player:2', 'stack:v>player:3'], array_map(static fn (CastSpell $c) => $c->targets[0], $casts), 'Gratuit avec son commandant ; une action par nouvelle cible');
        self::assertStringContainsString('nouvelle cible : joueur 2', $casts[1]->describe($s));

        $r = $this->play($s, $casts[1]);
        self::assertSame(['player:2'], $this->of($r->events, 'TargetChanged')[0]->payload['targets']);
        self::assertSame(20, $s->player(0)->life);
        self::assertSame(17, $s->player(2)->life, 'Le Bolt touche le nouveau joueur');
    }

    public function testDeflectingSwatCanRedirectAnAbilityButMisdirectionOnlyASpell(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Deflecting Swat', 'swat'), self::card('Misdirection', 'mis'), self::blueSpell('blue'))
            ->permanent(0, self::land('r', 'R'))->untappedLands(0, 2)->build(self::J0_HUMAN);
        $pinger = new CardInstance('ping', 'Pinger', CardInstance::CREATURE, 1, power: 1, toughness: 1, abilities: [\App\Engine\Ability\ActivatedAbility::pinger(1)]);
        $s->stack[] = new StackItem('ab', $pinger, 1, ['player:0'], 0, $pinger->abilities[0]);
        self::assertCount(3, $this->casts($s, 'swat'), 'Swat vise aussi une capacité');
        self::assertSame([], $this->casts($s, 'mis'), 'Misdirection : sorts seulement');

        // Un contresort redirigé vers un autre sort de la pile.
        $s = (new ScenarioBuilder())->hand(0, self::card('Misdirection', 'mis'), self::blueSpell('blue'))->build(self::J0_HUMAN);
        self::onStack($s, 'mine', self::bolt('mb'), 0, ['player:1']);
        self::onStack($s, 'theirs', self::bolt('tb'), 1, ['player:0']);
        self::onStack($s, 'cs', self::card('Counterspell', 'cs'), 1, ['stack:mine']);
        $casts = $this->casts($s, 'mis');
        $onTheirs = array_values(array_filter($casts, static fn (CastSpell $c) => $c->targets === ['stack:cs>stack:theirs']));
        self::assertCount(1, $onTheirs, 'Le Counterspell peut viser l\'autre sort, pas lui-même');
        self::assertSame([], array_filter($casts, static fn (CastSpell $c) => $c->targets === ['stack:cs>stack:cs']));
        $this->play($s, $onTheirs[0]);
        self::assertSame(20, $s->player(0)->life, 'Le Bolt adverse est contré');
        self::assertSame(17, $s->player(1)->life, 'Le sien se résout');
    }

    public function testCommandeerStealsANoncreatureSpellThatGoesToItsOwnersGraveyard(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Commandeer', 'cmd'), self::blueSpell('b1', 'Blue A'), self::blueSpell('b2', 'Blue B'), self::blueSpell('b3', 'Blue A'))
            ->build(self::J0_HUMAN);
        self::onStack($s, 'v', self::blueSpell('draw'), 1);
        $casts = $this->casts($s, 'cmd');
        self::assertSame(['pitch:b1,b2', 'pitch:b1,b3'], array_map(static fn (CastSpell $c) => $c->alt, $casts), 'Paires de cartes bleues par noms');
        $hand = count($s->player(0)->hand);
        $r = $this->play($s, $casts[0]);
        self::assertSame(['b1', 'b2'], self::ids($s->player(0)->exile));
        self::assertSame(['controller' => 0, 'previous' => 1], array_intersect_key($this->of($r->events, 'SpellControlChanged')[0]->payload, ['controller' => 1, 'previous' => 1]));
        self::assertSame($hand - 3 + 1, count($s->player(0)->hand), 'Commandeer et deux cartes exilées, puis le sort volé pioche pour J0');
        self::assertSame(['draw'], self::ids($s->player(1)->graveyard), 'Le sort volé va au cimetière de son propriétaire');
        self::assertSame(1, $this->of($r->events, 'SpellResolved')[1]->payload['owner']);
    }

    // --- Mindbreak Trap, Chain of Vapor, Into the Flood Maw ------------------------------------------------------------------

    public function testMindbreakTrapIsFreeAfterThreeSpellsOfAnOpponentAndExilesSpells(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Mindbreak Trap', 'trap'))->build(self::J0_HUMAN);
        self::onStack($s, 'a', self::bolt('ba'), 1, ['player:0']);
        self::onStack($s, 'b', self::bolt('bb'), 1, ['player:0']);
        $s->spellsCast = [1 => 2];
        self::assertSame([], $this->casts($s, 'trap'), 'Deux sorts seulement, pas de mana');
        $s->spellsCast = [1 => 3];
        $casts = $this->casts($s, 'trap');
        self::assertSame(['stack:a', 'stack:b', 'stack:a+b'], array_map(static fn (CastSpell $c) => $c->targets[0], $casts));
        self::assertSame(CastSpell::ALT_FREE, $casts[2]->alt);
        $r = $this->play($s, $casts[2]);
        self::assertCount(2, $this->of($r->events, 'SpellExiled'));
        self::assertSame(['ba', 'bb'], self::ids($s->player(1)->exile));
        self::assertSame(20, $s->player(0)->life);
    }

    public function testChainOfVaporBouncesThenLetsTheControllerSacrificeALandToCopyIt(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Chain of Vapor', 'chain'))->permanent(0, self::land('u', 'U'))
            ->permanent(1, self::card('Sol Ring', 'ring'))->permanent(1, self::land('l1', 'R', 'Mountain'))->permanent(2, self::card('Sol Ring', 'ring2'))
            ->build([0 => 'human', 1 => 'human', 2 => 'ai:pass', 3 => 'ai:pass']);
        $casts = $this->casts($s, 'chain');
        self::assertNotContains('permanent:u', array_map(static fn (CastSpell $c) => $c->targets[0], $casts), 'Pas un terrain');
        $engine = new GameEngine(new AgentRegistry());
        $r = $engine->advance($s, array_values(array_filter($casts, static fn (CastSpell $c) => $c->targets === ['permanent:ring']))[0]);
        while ($s->selection === null && $r->pending !== null) {
            $r = $engine->advance($s, new PassPriority($r->pending->player));
        }
        self::assertSame('chain', $s->selection['kind']);
        self::assertSame(1, $r->pending->player, 'Le contrôleur du permanent renvoyé décide');
        $choices = $this->legal($s, 1);
        self::assertInstanceOf(ChainCopy::class, $choices[0]);
        self::assertNull($choices[0]->landId, 'Décliner d\'abord');
        $copy = array_values(array_filter($choices, static fn (Action $a) => $a instanceof ChainCopy && $a->target === 'permanent:ring2'))[0];
        $r = $engine->advance($s, $copy);
        self::assertSame(['ring'], self::ids($s->player(1)->hand));
        self::assertSame(['l1'], self::ids($s->player(1)->graveyard), 'Terrain sacrifié');
        self::assertCount(1, $this->of($r->events, 'SpellCopied'));
        self::assertTrue($s->stack[0]->copy);
        self::assertSame(1, $s->stack[0]->controller);
        while ($s->stack !== [] && $r->pending !== null && $s->selection === null) {
            $r = $engine->advance($s, new PassPriority($r->pending->player));
        }
        self::assertSame(['ring2'], self::ids($s->player(2)->hand), 'La copie renvoie l\'autre Sol Ring');
        self::assertNull($s->selection, 'J2 n\'a pas de terrain : pas de nouvelle copie');
    }

    public function testIntoTheFloodMawGiftsATappedFishToWidenItsTarget(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Into the Flood Maw', 'maw'))->permanent(0, self::land('u', 'U'))->creature(0, 'mine', 1, 1)
            ->creature(1, 'c1', 2, 2)->permanent(1, self::card('Sol Ring', 'ring'))->build(self::J0_HUMAN);
        $casts = $this->casts($s, 'maw');
        $mode1 = array_values(array_filter($casts, static fn (CastSpell $c) => $c->mode === 1));
        self::assertSame([['permanent:c1']], array_map(static fn (CastSpell $c) => $c->targets, $mode1), 'Sans Gift : une créature adverse seulement');
        $gift = array_values(array_filter($casts, static fn (CastSpell $c) => $c->mode === 2 && $c->targets === ['player:2', 'permanent:ring']));
        self::assertCount(1, $gift, 'Gift à J2, puis Sol Ring de J1');
        $r = $this->play($s, $gift[0]);
        $fish = $this->of($r->events, 'TokenCreated')[0]->payload;
        self::assertSame([2, 'Fish', true], [$fish['player'], $fish['card']['name'], $fish['tapped']]);
        self::assertTrue($s->player(2)->battlefield[array_key_last($s->player(2)->battlefield)]->tapped);
        self::assertSame(['ring'], self::ids($s->player(1)->hand));
    }

    public function testAGiftIsNotGivenWhenTheSpellLosesItsTarget(): void
    {
        $s = (new ScenarioBuilder())->creature(1, 'c1', 2, 2)->build(self::J0_HUMAN);
        $s->stack[] = new StackItem('m', self::card('Into the Flood Maw', 'maw'), 0, ['player:2', 'permanent:gone'], 0, mode: 2);
        (new StackResolver())->resolveTop($s);
        self::assertSame(10, count($s->player(2)->library));
        self::assertSame([], array_filter($s->player(2)->battlefield, static fn (CardInstance $c) => $c->token));
    }

    // --- Partie complète ---------------------------------------------------------------------------------------------------

    public function testFourAiGamesWithTheLotPlayToTheEnd(): void
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach ([...self::LOT, 'Lightning Bolt', 'Sol Ring', 'Counterspell', 'Force of Will', 'Brainstorm', 'Ponder', 'Wind Drake', 'Serra Angel'] as $name) {
            $library[] = $m->map($f[$name]);
        }
        foreach (['Plains', 'Island', 'Swamp'] as $basic) {
            for ($i = 0; count($library) < 98 && $i < 30; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }
        $deck = new LoadedDeck($m->map($f["Kraum, Ludevic's Opus"]), $library, partner: $m->map($f['Tymna the Weaver']));
        $over = 0;
        foreach ([3, 7, 11, 19] as $seed) {
            $state = GameFactory::create($seed, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));
            $r = (new GameEngine(new AgentRegistry()))->advance($state);
            $over += (int) $r->over;
            self::assertSame($state->toArray(), GameState::fromArray($state->toArray())->toArray());
        }
        self::assertSame(4, $over);
    }
}
