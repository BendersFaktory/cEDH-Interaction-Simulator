<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\PayCost;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\CardDefinition;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Game\PendingTrigger;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Lot H4 (deck Kraum / Tymna) : triggers sur les adversaires. Esper Sentinel (premier sort non-créature, {X} = force), Faerie
 * Mastermind (deuxième pioche d'un adversaire, chaque joueur pioche), Orcish Bowmasters (pioche hors étape de pioche, Amass),
 * Mystic Remora (Cumulative upkeep, sort non-créature d'un adversaire).
 */
final class KraumH4CardsTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];
    private const LOT = ['Esper Sentinel', 'Faerie Mastermind', 'Orcish Bowmasters', 'Mystic Remora'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    /** Met le premier trigger en attente sur la pile avec `$targets` (sans passer par les décisions). */
    private static function stackPending(GameState $s, array $targets = []): StackItem
    {
        $pending = array_shift($s->pendingTriggers);
        self::assertNotNull($pending);
        $item = new StackItem('s' . $s->nextStackId++, $pending->source, $pending->controller, $targets, trigger: $pending->ability());
        $s->stack[] = $item;

        return $item;
    }

    public function testCardsOfTheLotAreAcceptedWithTheirMechanisms(): void
    {
        foreach (self::LOT as $name) {
            self::assertInstanceOf(CardDefinition::class, self::def($name), $name);
        }
        $sentinel = self::def('Esper Sentinel')->triggers[0];
        self::assertSame([TriggeredAbility::OPPONENT_CAST, 'draw_unless', TriggeredAbility::X_POWER, TriggeredAbility::FIRST_NONCREATURE], [$sentinel->event, $sentinel->effect, $sentinel->spec, $sentinel->filter]);
        $faerie = self::def('Faerie Mastermind');
        self::assertSame([TriggeredAbility::OPPONENT_DRAW, TriggeredAbility::SECOND_DRAW], [$faerie->triggers[0]->event, $faerie->triggers[0]->filter]);
        self::assertSame('each_draw', $faerie->abilities[0]->effect);
        self::assertContains(CardInstance::FLASH, $faerie->keywords);
        $bow = self::def('Orcish Bowmasters')->triggers;
        self::assertSame([TriggeredAbility::ETB, TriggeredAbility::OPPONENT_DRAW], [$bow[0]->event, $bow[1]->event]);
        self::assertSame(['ping_amass', 'Orc:1', TriggeredAbility::NOT_DRAW_STEP_FIRST], [$bow[1]->effect, $bow[1]->spec, $bow[1]->filter]);
        $remora = self::def('Mystic Remora')->triggers;
        self::assertSame([TriggeredAbility::UPKEEP, 'cumulative_upkeep', '{1}'], [$remora[0]->event, $remora[0]->effect, $remora[0]->spec]);
        self::assertSame(['draw_unless', '{4}', TriggeredAbility::NONCREATURE], [$remora[1]->effect, $remora[1]->spec, $remora[1]->filter]);
        self::assertSame('', self::def('Rhystic Study')->triggers[0]->filter, 'Rhystic Study : tout sort');
    }

    public function testNewStateRoundTripsAndIsOmittedByDefault(): void
    {
        $remora = self::card('Mystic Remora', 'rem');
        self::assertArrayNotHasKey('counters', $remora->toArray());
        $remora->counters = [CardInstance::AGE => 2];
        self::assertSame($remora->toArray(), CardInstance::fromArray($remora->toArray())->toArray());
        self::assertSame([CardInstance::AGE => 2], CardInstance::fromArray($remora->toArray())->counters);

        $s = (new ScenarioBuilder())->permanent(1, $remora)->build(self::J0_HUMAN);
        $plain = $s->toArray();
        self::assertArrayNotHasKey('cardsDrawn', $plain);
        self::assertArrayNotHasKey('noncreatureCast', $plain);
        $s->cardsDrawn = [0 => 2];
        $s->noncreatureCast = [0 => 1];
        $back = GameState::fromArray($s->toArray());
        self::assertSame($s->toArray(), $back->toArray());
        self::assertSame([0 => 2], $back->cardsDrawn);
    }

    // --- Esper Sentinel ----------------------------------------------------------------------------------------------------

    public function testEsperSentinelTriggersOnTheFirstNoncreatureSpellOfAnOpponentOnly(): void
    {
        $s = (new ScenarioBuilder())->permanent(1, self::card('Esper Sentinel', 'es'))->build(self::J0_HUMAN);
        $creature = new CardInstance('c', 'Bear', CardInstance::CREATURE, 2, power: 2, toughness: 2);
        $s->queueOpponentCastTriggers(0, $creature);
        self::assertSame([], $s->pendingTriggers, 'sort de créature : rien');

        $s->noncreatureCast = [0 => 1];
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b1'));
        self::assertCount(1, $s->pendingTriggers);
        self::assertSame(0, $s->pendingTriggers[0]->opponent);
        $targets = (new LegalActionGenerator())->triggerActions($s, 1)[0]->targets;
        self::assertSame(['player:0'], $targets, 'le lanceur paiera');

        $s->pendingTriggers = [];
        $s->noncreatureCast = [0 => 2];
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b2'));
        self::assertSame([], $s->pendingTriggers, 'deuxième sort non-créature : rien');
        $s->noncreatureCast = [2 => 1];
        $s->queueOpponentCastTriggers(1, ScenarioBuilder::bolt('b3'));
        self::assertSame([], $s->pendingTriggers, 'son contrôleur : rien');
    }

    public function testEsperSentinelCostIsItsCurrentPowerAndZeroMeansNoDraw(): void
    {
        $sentinel = self::card('Esper Sentinel', 'es');
        $s = (new ScenarioBuilder())->permanent(1, $sentinel)->build(self::J0_HUMAN);
        $s->noncreatureCast = [0 => 1];
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b1'));
        self::stackPending($s, ['player:0']);
        self::assertSame(['player' => 0, 'cost' => '{1}', 'source' => 'Esper Sentinel'], StackResolver::paymentDue($s));
        $sentinel->powerBonus = 2;
        self::assertSame('{3}', StackResolver::paymentDue($s)['cost']);
        $hand = count($s->player(1)->hand);
        (new StackResolver())->resolveTop($s, false);
        self::assertCount($hand + 1, $s->player(1)->hand, 'non payé : J1 pioche');

        $sentinel->powerBonus = -1;
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b2'));
        self::stackPending($s, ['player:0']);
        self::assertNull(StackResolver::paymentDue($s), 'force 0 : {0}, rien à décider');
        (new StackResolver())->resolveTop($s, null);
        self::assertCount($hand + 1, $s->player(1)->hand, '{0} payé : pas de pioche');
    }

    // --- Faerie Mastermind -------------------------------------------------------------------------------------------------

    public function testFaerieMastermindTriggersOnTheSecondCardAnOpponentDrawsEachTurn(): void
    {
        $s = (new ScenarioBuilder())->permanent(1, self::card('Faerie Mastermind', 'fm'))->build(self::J0_HUMAN);
        $s->drawCard(0, setup: true);
        self::assertSame([], $s->cardsDrawn, 'avant-partie : non comptée');
        $s->drawCard(0, drawStep: true);
        self::assertSame([], $s->pendingTriggers);
        $s->drawCard(0);
        self::assertCount(1, $s->pendingTriggers, 'deuxième carte');
        $s->drawCard(0);
        $s->drawCard(1);
        $s->drawCard(1);
        self::assertCount(1, $s->pendingTriggers, 'ni la troisième, ni les pioches de son contrôleur');
        self::assertSame([0 => 3, 1 => 2], $s->cardsDrawn);
    }

    public function testFaerieMastermindAbilityMakesEachPlayerDraw(): void
    {
        $faerie = self::card('Faerie Mastermind', 'fm');
        $s = (new ScenarioBuilder())->permanent(0, $faerie)->untappedLands(0, 3)->permanent(0, new CardInstance('isl', 'Island', CardInstance::LAND, produces: ['U']))->build(self::J0_HUMAN);
        $activate = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof ActivateAbility && $a->cardId === 'fm'));
        self::assertNotSame([], $activate);
        $s->stack[] = new StackItem('s1', $faerie, 0, [], ability: $faerie->abilities[0]);
        (new StackResolver())->resolveTop($s);
        foreach ([0, 1, 2, 3] as $id) {
            self::assertCount(1, $s->player($id)->hand, "J$id pioche");
        }
    }

    // --- Orcish Bowmasters -------------------------------------------------------------------------------------------------

    public function testBowmastersPingsAndAmassesOnEntryAndOnExtraDraws(): void
    {
        $s = (new ScenarioBuilder())->build(self::J0_HUMAN);
        $s->enterBattlefield(1, self::card('Orcish Bowmasters', 'bow'));
        self::assertCount(1, $s->pendingTriggers, 'à l\'entrée');
        self::assertSame(['player:0', 'player:1', 'player:2', 'player:3'], array_map(static fn ($a) => $a->targets[0], (new LegalActionGenerator())->triggerActions($s, 1)));
        self::stackPending($s, ['player:0']);
        (new StackResolver())->resolveTop($s);
        self::assertSame(19, $s->player(0)->life);
        $army = $s->player(1)->battlefield[1];
        self::assertSame(['Orc Army', ['Orc', 'Army'], [CardInstance::PLUS_ONE => 1]], [$army->name, $army->subtypes[CardInstance::CREATURE], $army->counters]);
        self::assertSame([1, 1], [$army->currentPower(), $army->currentToughness()]);
        $added = array_values(array_filter($s->drainEvents(), static fn ($e) => $e->type === 'CountersAdded'));
        self::assertSame(['player' => 1, 'card' => $army->id, 'kind' => '+1/+1', 'count' => 1, 'power' => 1, 'toughness' => 1], $added[0]->payload);

        $s->phase = Phase::Draw;
        $s->drawCard(0, drawStep: true);
        self::assertSame([], $s->pendingTriggers, 'première carte de l\'étape de pioche : rien');
        $s->drawCard(0);
        $s->drawCard(2);
        self::assertCount(2, $s->pendingTriggers, 'chaque autre carte d\'un adversaire');
        self::stackPending($s, ['player:2']);
        (new StackResolver())->resolveTop($s);
        self::assertCount(2, $s->player(1)->battlefield, 'pas de seconde Army : la même grandit');
        self::assertSame(2, $army->currentPower());

        $s->returnToHand(1, $army->id, 'test');
        self::assertSame([], $army->counters, 'les marqueurs disparaissent avec le permanent');
    }

    // --- Mystic Remora -----------------------------------------------------------------------------------------------------

    public function testMysticRemoraCumulativeUpkeepGrowsAndIsSacrificedWhenUnpaid(): void
    {
        $remora = self::card('Mystic Remora', 'rem');
        $s = (new ScenarioBuilder())->permanent(0, $remora)->build(self::J0_HUMAN);
        $s->activePlayer = 0;
        $s->queuePlayerTriggers(0, TriggeredAbility::UPKEEP);
        self::stackPending($s);
        self::assertSame(['player' => 0, 'cost' => '{1}', 'source' => 'Mystic Remora'], StackResolver::paymentDue($s));
        (new StackResolver())->resolveTop($s, true);
        self::assertSame([CardInstance::AGE => 1], $remora->counters);

        $s->queuePlayerTriggers(0, TriggeredAbility::UPKEEP);
        self::stackPending($s);
        self::assertSame('{2}', StackResolver::paymentDue($s)['cost'], 'un {1} par marqueur d\'âge');
        (new StackResolver())->resolveTop($s, false);
        self::assertNull($s->player(0)->findOnBattlefield('rem'), 'non payé : sacrifiée');
        self::assertSame('Mystic Remora', $s->player(0)->graveyard[0]->name);
        self::assertSame([], $s->player(0)->graveyard[0]->counters);
    }

    public function testMysticRemoraDrawsOnNoncreatureSpellsOfOpponentsUnlessTheyPayFour(): void
    {
        $s = (new ScenarioBuilder())->permanent(1, self::card('Mystic Remora', 'rem'))->build(self::J0_HUMAN);
        $s->queueOpponentCastTriggers(0, new CardInstance('c', 'Bear', CardInstance::CREATURE, 2, power: 2, toughness: 2));
        self::assertSame([], $s->pendingTriggers);
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b1'));
        $s->queueOpponentCastTriggers(0, ScenarioBuilder::bolt('b2'));
        self::assertCount(2, $s->pendingTriggers, 'chaque sort non-créature');
        self::stackPending($s, ['player:0']);
        self::assertSame('{4}', StackResolver::paymentDue($s)['cost']);
        self::assertSame('Ne pas payer {4} (Mystic Remora) : J1 pioche', (new PayCost(0, false, '{4}'))->describe($s));
    }

    public function testAiKeepsMysticRemoraWhileTheTotalStaysSmall(): void
    {
        $remora = self::card('Mystic Remora', 'rem');
        $s = (new ScenarioBuilder())->permanent(0, $remora)->untappedLands(0, 5)->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->activePlayer = 0;
        $decide = static function (GameState $s, string $cost): bool {
            $s->stack = [];
            $s->queuePlayerTriggers(0, TriggeredAbility::UPKEEP);
            self::stackPending($s);
            $s->paymentPending = true;
            $legal = [new PayCost(0, true, $cost), new PayCost(0, false, $cost)];
            $action = (new HeuristicAgent())->decide($s, 0, $legal);

            return $action instanceof PayCost && $action->pay;
        };
        $remora->counters = [CardInstance::AGE => 2];
        self::assertTrue($decide($s, '{3}'));
        $remora->counters = [CardInstance::AGE => 3];
        self::assertFalse($decide($s, '{4}'));
    }

    // --- Partie complète ---------------------------------------------------------------------------------------------------

    public function testFourAiGameWithTheLotPlaysToTheEnd(): void
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach ([...self::LOT, 'Lightning Bolt', 'Sol Ring', 'Counterspell', 'Brainstorm', 'Command Tower', 'Rhystic Study', 'Wall of Omens', 'Serra Angel'] as $name) {
            $library[] = $m->map($f[$name]);
        }
        foreach (['Plains', 'Island', 'Swamp'] as $basic) {
            for ($i = 0; $i < 29; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }
        $library = array_slice($library, 0, 98);
        $deck = new LoadedDeck($m->map($f['Kraum, Ludevic\'s Opus']), $library, partner: $m->map($f['Tymna the Weaver']));
        $state = GameFactory::create(11, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));

        $seen = [];
        $types = [];
        foreach ((new GameEngine(new AgentRegistry()))->advance($state)->events as $e) {
            $types[$e->type] = true;
            if ($e->type === 'AbilityTriggered' && in_array($e->payload['card']['name'], self::LOT, true)) {
                $seen[$e->payload['card']['name']] = true;
            }
        }
        self::assertArrayHasKey('GameOver', $types);
        self::assertNotSame([], $seen, 'au moins un trigger du lot observé');
    }
}
