<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PayCost;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Restes des lots G1 à G3 : filtre d'un contresort revérifié à la résolution, Mana Drain sur un sort de valeur 0, paiement
 * « sauf si paie » avec des sources engagées à la main, « you may draw » de Rhystic Study, Force of Will / Force of Negation
 * exilées par l'IA, estimation du mana dynamique par l'IA.
 */
final class RestesGTest extends TestCase
{
    private static function card(string $name, string $id): CardInstance
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name])->instantiate($id);
    }

    private static function land(string $id, string $color): CardInstance
    {
        return new CardInstance($id, 'Land ' . $id, CardInstance::LAND, produces: [$color]);
    }

    /** @param list<GameEvent> $events @return list<string> */
    private static function types(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->type, $events);
    }

    public function testACounterWhoseTargetNoLongerMatchesItsFilterFizzles(): void
    {
        // Mental Misstep (valeur de mana 1) vise un sort dont X a fait monter la valeur : la cible n'est plus légale.
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $fireball = new CardInstance('fb', 'Fireball', CardInstance::SORCERY, 1, manaCost: '{X}{R}', effects: [new SpellEffect('damage')]);
        $s->stack[] = new StackItem('v', $fireball, 1, ['player:0'], 3);
        $s->stack[] = new StackItem('mm', self::card('Mental Misstep', 'mm'), 0, ['stack:v']);
        (new StackResolver())->resolveTop($s);
        self::assertSame(['v'], array_map(static fn (StackItem $i) => $i->id, $s->stack), 'Le sort reste sur la pile');
        self::assertSame([], $s->player(1)->graveyard);
        self::assertContains('SpellFizzled', self::types($s->drainEvents()));
    }

    public function testManaDrainOnASpellOfManaValueZeroCreatesNoDelayedTrigger(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $s->stack[] = new StackItem('v', self::card('Pact of Negation', 'pact'), 1, ['stack:none']);
        $s->stack[] = new StackItem('md', self::card('Mana Drain', 'md'), 0, ['stack:v']);
        (new StackResolver())->resolveTop($s);
        self::assertSame(['pact'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->graveyard), 'Le sort est contré');
        self::assertSame([], $s->delayedTriggers);
    }

    public function testThePayerCanTapASourceByHandBeforePaying(): void
    {
        // Daze de J0 sur le sort de J1 : J1 (humain) engage d'abord son Île à la main, puis paie avec sa réserve.
        $s = (new ScenarioBuilder())->hand(0, self::card('Daze', 'daze'))->permanent(0, new CardInstance('i0', 'Island', CardInstance::LAND, produces: ['U'], subtypes: [CardInstance::LAND => ['Island']]))
            ->permanent(1, self::land('i1', 'U'))->permanent(1, self::land('g1', 'G'))->build([0 => 'ai:pass', 1 => 'human:auto', 2 => 'ai:pass']);
        $s->stack[] = new StackItem('x', new CardInstance('t', 'Test Sorcery', CardInstance::SORCERY, 1, manaCost: '{1}', effects: [new SpellEffect('draw', 1)]), 1);
        $engine = new GameEngine(new AgentRegistry());
        $r = $engine->advance($s, new CastSpell(0, 'daze', ['stack:x'], 0, 'return:i0'));
        self::assertSame(1, $r->pending->player);
        $byHand = array_values(array_filter($r->pending->legalActions, static fn (Action $a) => $a instanceof ActivateAbility && $a->cardId === 'g1'));
        self::assertCount(1, $byHand);

        $r = $engine->advance($s, $byHand[0]);
        self::assertTrue($s->paymentPending, 'Toujours la décision de paiement');
        self::assertSame(['G' => 1], $s->player(1)->pool->toArray());
        $r = $engine->advance($s, new PayCost(1, true, '{1}'));
        $paid = array_values(array_filter($r->events, static fn (GameEvent $e) => $e->type === 'CostPaid'))[0]->payload;
        self::assertSame(['G' => 1], $paid['fromPool'], 'Le mana engagé à la main est dépensé');
        self::assertSame([], $paid['tapped'], 'L\'Île reste dégagée');
        self::assertFalse($s->player(1)->findOnBattlefield('i1')->tapped);
    }

    public function testRhysticStudyDoesNotDrawFromAnEmptyLibrary(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $s->player(0)->library = [];
        $rhystic = new CardInstance('rs', 'Rhystic Study', CardInstance::ENCHANTMENT, 3, triggers: [new TriggeredAbility(TriggeredAbility::OPPONENT_CAST, 'draw_unless', 1, '{1}')]);
        $s->stack[] = new StackItem('t', $rhystic, 0, ['player:1'], 0, null, 0, $rhystic->triggers[0]);
        (new StackResolver())->resolveTop($s, false);
        self::assertFalse($s->player(0)->drewFromEmpty, '« You may draw » : il ne pioche pas pour perdre');
    }

    public function testTheAiPitchesForceOfWillOnlyAgainstAHugeThreatWithoutAPayableCounter(): void
    {
        $build = function (int $damage): GameState {
            $s = (new ScenarioBuilder())->hand(0, self::card('Force of Will', 'fow'), new CardInstance('bl', 'Blue Card', CardInstance::SORCERY, 2, manaCost: '{1}{U}', effects: [new SpellEffect('draw', 1)]))
                ->build([0 => 'ai:heuristic', 1 => 'ai:pass']);
            $s->player(0)->life = 40;
            $s->activePlayer = 1;
            $s->stack[] = new StackItem('v', new CardInstance('burn', 'Burn', CardInstance::INSTANT, 1, manaCost: '{R}', effects: [new SpellEffect('damage', $damage)]), 1, $damage > 0 ? ['player:0'] : ['player:1']);
            $s->priority = new PriorityState(0);

            return $s;
        };
        $s = $build(5);
        $action = (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        self::assertInstanceOf(CastSpell::class, $action, 'Un sort qui vise l\'IA : menace énorme');
        self::assertSame('pitch:bl', $action->alt);

        $s = $build(0);
        $action = (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        self::assertNotInstanceOf(CastSpell::class, $action, 'Pas pour un petit sort qui ne la vise pas');
    }

    public function testTheAiCountsOnlyTheCurrentColorsOfDynamicMana(): void
    {
        // Chrome Mox sans imprint ne produit rien : l'IA ne compte pas cinq couleurs (ni un mana) pour lui.
        $s = (new ScenarioBuilder())->permanent(0, self::card('Chrome Mox', 'mox'))->build([0 => 'ai:heuristic', 1 => 'ai:pass']);
        $agent = new HeuristicAgent();
        $potential = (new \ReflectionMethod($agent, 'manaPotential'))->invoke($agent, $s->player(0), $s->player(0)->battlefield);
        $symbols = (new \ReflectionMethod($agent, 'producedSymbols'))->invoke($agent, $s->player(0), $s->player(0)->battlefield);
        self::assertSame(0, $potential);
        self::assertSame([], $symbols);
        $s->player(0)->findOnBattlefield('mox')->imprint = ['R'];
        self::assertSame(['R' => true], (new \ReflectionMethod($agent, 'producedSymbols'))->invoke($agent, $s->player(0), $s->player(0)->battlefield));
    }
}
