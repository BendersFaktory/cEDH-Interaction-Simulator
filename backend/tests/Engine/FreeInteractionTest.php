<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PayCost;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Interaction gratuite (lot G2) : Force of Negation (exil d'une carte bleue hors de son tour, sort contré exilé), Daze (renvoyer
 * une Île ; « sauf si son contrôleur paie {1} »), Gush (renvoyer deux Îles), Mental Misstep (valeur de mana 1), Pact of Negation
 * (trigger retardé : payer ou perdre) et Mana Drain (trigger retardé : {C} à la prochaine phase principale), Memory Lapse.
 */
final class FreeInteractionTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function island(string $id, bool $tapped = false): CardInstance
    {
        return new CardInstance($id, 'Island', CardInstance::LAND, tapped: $tapped, produces: ['U'], subtypes: [CardInstance::LAND => ['Island']]);
    }

    private static function fon(): CardInstance
    {
        return new CardInstance('fon', 'Force of Negation', CardInstance::INSTANT, 3, manaCost: '{1}{U}{U}', altCost: CardInstance::ALT_PITCH_BLUE_OFF_TURN,
            effects: [new SpellEffect('counter', target: 'noncreature', counterTo: SpellEffect::COUNTER_EXILE)]);
    }

    private static function daze(): CardInstance
    {
        return new CardInstance('daze', 'Daze', CardInstance::INSTANT, 2, manaCost: '{1}{U}', altCost: CardInstance::ALT_RETURN_ISLANDS . '1',
            effects: [new SpellEffect('counter', payCost: '{1}')]);
    }

    private static function blue(): CardInstance
    {
        return new CardInstance('blue', 'Test Blue', CardInstance::INSTANT, 1, manaCost: '{U}', effects: [new SpellEffect('draw', 1)]);
    }

    /** Sort de J1 sur la pile, priorité à J0 (tour de J0 par défaut). */
    private static function withVictim(GameState $s, string $manaCost = '{2}', int $cost = 2): GameState
    {
        $s->stack[] = new StackItem('x', new CardInstance('x', 'Test Sorcery', CardInstance::SORCERY, $cost, manaCost: $manaCost, effects: [new SpellEffect('draw', 1)]), 1);
        $s->priority = new PriorityState(0);

        return $s;
    }

    /** @return list<array{0: list<string>, 1: ?string}> */
    private function casts(GameState $s, string $cardId, int $player = 0): array
    {
        return array_values(array_map(
            static fn (CastSpell $a) => [$a->targets, $a->alt],
            array_filter((new LegalActionGenerator())->for($s, $player), static fn ($a) => $a instanceof CastSpell && $a->cardId === $cardId),
        ));
    }

    /** @param list<\App\Engine\Event\GameEvent> $events @return list<string> */
    private static function types(array $events): array
    {
        return array_map(static fn ($e) => $e->type, $events);
    }

    private static function ids(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->id, $cards);
    }

    public function testForceOfNegationIsFreeOnlyOnAnOpponentsTurnAndExilesTheCounteredSpell(): void
    {
        $s = self::withVictim((new ScenarioBuilder())->hand(0, self::fon(), self::blue())->build(self::PASS));
        $this->assertSame([], $this->casts($s, 'fon'), 'Son propre tour : coût de mana seulement, et aucun terrain');

        $s->activePlayer = 1;
        $this->assertSame([[['stack:x'], 'pitch:blue']], $this->casts($s, 'fon'));

        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'fon', ['stack:x'], 0, 'pitch:blue'));
        $this->assertSame(20, $s->player(0)->life, 'Aucun PV payé (contrairement à Force of Will)');
        $this->assertContains('blue', self::ids($s->player(0)->exile));
        $this->assertContains('x', self::ids($s->player(1)->exile), 'Le sort contré est exilé au lieu du cimetière');
        $countered = array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCountered'))[0];
        $this->assertSame('exile', $countered->payload['destination']);
    }

    public function testDazeReturnsAnIslandAndCountersWhenItsControllerCannotPay(): void
    {
        $s = self::withVictim((new ScenarioBuilder())->hand(0, self::daze())->permanent(0, self::island('i1', true))->build(self::PASS));
        $this->assertSame([[['stack:x'], 'return:i1']], $this->casts($s, 'daze'), 'Île engagée : renvoyable, et pas de mana pour le coût normal');

        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'daze', ['stack:x'], 0, 'return:i1'));
        $types = self::types($result->events);
        $this->assertContains('PermanentReturnedToHand', $types);
        $this->assertContains('CostNotPaid', $types, 'J1 sans terrain : pas de décision, le sort est contré');
        $this->assertContains('SpellCountered', $types);
        $this->assertLessThan(array_search('SpellCast', $types, true), array_search('PermanentReturnedToHand', $types, true), 'Le coût est payé en lançant');
    }

    public function testDazeIsPaidByTheTargetedSpellsController(): void
    {
        $s = self::withVictim((new ScenarioBuilder())->hand(0, self::daze())->permanent(0, self::island('i1'))->untappedLands(1, 1)->build(self::PASS));
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'daze', ['stack:x'], 0, 'return:i1'));

        $paid = array_values(array_filter($result->events, static fn ($e) => $e->type === 'CostPaid'))[0] ?? null;
        $this->assertNotNull($paid, 'L\'IA paie {1}');
        $this->assertSame(1, $paid->payload['player']);
        $this->assertSame(['p1_land0'], $paid->payload['tapped']);
        $this->assertNotContains('SpellCountered', self::types($result->events));
        $resolved = array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellResolved'));
        $this->assertSame(['daze', 'x'], array_map(static fn ($e) => $e->payload['card']['id'], $resolved), 'Daze se résout sans effet, puis le sort visé');
    }

    public function testAHumanChoosesWhetherToPayForDaze(): void
    {
        $s = self::withVictim((new ScenarioBuilder())->hand(0, self::daze())->permanent(0, self::island('i1'))->untappedLands(1, 1)
            ->build([0 => 'ai:pass', 1 => 'human:auto', 2 => 'ai:pass']));
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s, new CastSpell(0, 'daze', ['stack:x'], 0, 'return:i1'));

        $this->assertNotNull($result->pending);
        $this->assertSame(1, $result->pending->player);
        $this->assertSame(['pay:1:yes', 'pay:1:no', 'ability:1:p1_land0:0:0:'], array_map(static fn ($a) => $a->id(), $result->pending->legalActions), 'Payer, refuser, ou engager une source à la main d\'abord');
        $this->assertNotContains('PriorityGranted', array_slice(self::types($result->events), -1), 'Décision hors priorité : pas de checkpoint');

        // Reprise depuis un état sérialisé (comme une ancre de pause humaine).
        $s = GameState::fromArray($s->toArray());
        $this->assertTrue($s->paymentPending);
        $result = $engine->advance($s, new PayCost(1, false, '{1}'));
        $this->assertContains('CostNotPaid', self::types($result->events));
        $this->assertContains('x', self::ids($s->player(1)->graveyard));
    }

    public function testGushReturnsTwoIslandsAndDraws(): void
    {
        $gush = new CardInstance('gush', 'Gush', CardInstance::INSTANT, 5, manaCost: '{4}{U}', altCost: CardInstance::ALT_RETURN_ISLANDS . '2', effects: [new SpellEffect('draw', 2)]);
        $s = (new ScenarioBuilder())->hand(0, $gush)->permanent(0, self::island('i1'))->permanent(0, self::island('i2'))->permanent(0, self::island('i3', true))->build(self::PASS);
        $this->assertSame([[[], 'return:i1,i2'], [[], 'return:i1,i3']], $this->casts($s, 'gush'), 'Deux dégagées, ou une dégagée et l\'engagée (dédoublonnées par nom et état)');

        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'gush', [], 0, 'return:i1,i3'));
        $returned = array_values(array_filter($result->events, static fn ($e) => $e->type === 'PermanentReturnedToHand'));
        $this->assertSame(['i1', 'i3'], array_map(static fn ($e) => $e->payload['card']['id'], $returned));
        $drawn = array_values(array_filter($result->events, static fn ($e) => $e->type === 'CardDrawn' && $e->payload['player'] === 0));
        $this->assertGreaterThanOrEqual(2, count($drawn));
    }

    public function testMentalMisstepOnlyTargetsManaValueOne(): void
    {
        $misstep = new CardInstance('mm', 'Mental Misstep', CardInstance::INSTANT, 1, manaCost: '{U/P}', effects: [new SpellEffect('counter', manaValue: 1)]);
        $s = self::withVictim((new ScenarioBuilder())->hand(0, $misstep)->build(self::PASS));
        $s->stack[] = new StackItem('y', new CardInstance('y', 'Test One', CardInstance::INSTANT, 1, manaCost: '{R}', effects: [new SpellEffect('draw', 1)]), 1);
        $this->assertSame([[['stack:y'], null]], $this->casts($s, 'mm'), 'Payé avec 2 PV (phyrexien), seul le sort à 1 est une cible');
    }

    public function testPactOfNegationMustBePaidAtTheNextUpkeep(): void
    {
        $pact = new CardInstance('pact', 'Pact of Negation', CardInstance::INSTANT, 0, manaCost: '{0}', effects: [new SpellEffect('counter'), new SpellEffect('pact', payCost: '{3}{U}{U}')]);
        $build = static function (int $islands) use ($pact): GameState {
            $b = (new ScenarioBuilder())->hand(0, $pact)->untappedLands(0, 3);
            for ($i = 0; $i < $islands; $i++) {
                $b->permanent(0, self::island("i$i"));
            }

            return self::withVictim($b->build(self::PASS));
        };

        // Payable : l'IA paie à son entretien suivant (tour 4 : J1, J2 et J3... ici trois joueurs, J0 rejoue au tour 4).
        $s = $build(2);
        $this->assertSame([[['stack:x'], null]], $this->casts($s, 'pact'));
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'pact', ['stack:x']));
        $created = array_values(array_filter($result->events, static fn ($e) => $e->type === 'DelayedTriggerCreated'));
        $this->assertCount(1, $created);
        $this->assertSame(TriggeredAbility::NEXT_UPKEEP, $created[0]->payload['trigger']['event']);
        $paid = array_values(array_filter($result->events, static fn ($e) => $e->type === 'CostPaid'))[0] ?? null;
        $this->assertNotNull($paid);
        $this->assertSame([0, '{3}{U}{U}', 'Pact of Negation'], [$paid->payload['player'], $paid->payload['cost'], $paid->payload['source']]);
        $this->assertNotContains('pact_unpaid', array_map(static fn ($e) => $e->payload['reason'] ?? null, $result->events));

        // Sans bleu : impayable, J0 perd la partie à son entretien.
        $s = $build(0);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'pact', ['stack:x']));
        $lost = array_values(array_filter($result->events, static fn ($e) => $e->type === 'PlayerLost'))[0];
        $this->assertSame([0, 'pact_unpaid'], [$lost->payload['player'], $lost->payload['reason']]);
        $phases = array_values(array_filter($result->events, static fn ($e) => $e->type === 'PhaseChanged' && $e->seq < $lost->seq));
        $this->assertSame(['upkeep', 0], [end($phases)->payload['phase'], end($phases)->payload['activePlayer']]);
    }

    public function testManaDrainAddsColorlessAtTheNextMainPhase(): void
    {
        $drain = new CardInstance('md', 'Mana Drain', CardInstance::INSTANT, 2, manaCost: '{U}{U}', effects: [new SpellEffect('counter', drainMana: true)]);
        $s = self::withVictim((new ScenarioBuilder())->hand(0, $drain)->permanent(0, self::island('i1'))->permanent(0, self::island('i2'))->build(self::PASS), '{1}{R}{R}', 3);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'md', ['stack:x']));

        $created = array_values(array_filter($result->events, static fn ($e) => $e->type === 'DelayedTriggerCreated'))[0];
        $this->assertSame(['event' => TriggeredAbility::NEXT_MAIN, 'effect' => 'mana', 'amount' => 3], $created->payload['trigger']);
        $added = array_values(array_filter($result->events, static fn ($e) => $e->type === 'ManaAdded' && $e->payload['player'] === 0))[0];
        $phase = array_values(array_filter($result->events, static fn ($e) => $e->type === 'PhaseChanged' && $e->seq < $added->seq));
        $this->assertSame(Phase::Main2->value, end($phase)->payload['phase'], 'Résolu pendant la phase principale 1 : la prochaine est la 2');
        $this->assertSame(['C' => 3], $added->payload['mana']);
    }

    public function testMemoryLapsePutsTheSpellOnTopOfItsOwnersLibrary(): void
    {
        $lapse = new CardInstance('ml', 'Memory Lapse', CardInstance::INSTANT, 2, manaCost: '{1}{U}', effects: [new SpellEffect('counter', counterTo: SpellEffect::COUNTER_LIBRARY_TOP)]);
        $s = self::withVictim((new ScenarioBuilder())->hand(0, $lapse)->permanent(0, self::island('i1'))->permanent(0, self::island('i2'))->build(self::PASS));
        $engine = new GameEngine(new AgentRegistry());
        $s->maxTurns = 1;
        $result = $engine->advance($s, new CastSpell(0, 'ml', ['stack:x']));
        $countered = array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCountered'))[0];
        $this->assertSame(['library', 11], [$countered->payload['destination'], $countered->payload['library']]);
    }

    public function testDelayedTriggersSurviveSerialization(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $source = new CardInstance('pact', 'Pact of Negation', CardInstance::INSTANT, 0, manaCost: '{0}', effects: [new SpellEffect('counter'), new SpellEffect('pact', payCost: '{3}{U}{U}')]);
        $s->addDelayedTrigger(0, $source, new TriggeredAbility(TriggeredAbility::NEXT_UPKEEP, 'pact', spec: '{3}{U}{U}'));
        $copy = GameState::fromArray($s->toArray());

        $this->assertSame($s->toArray(), $copy->toArray());
        $this->assertSame('pact', $copy->delayedTriggers[0]->ability()->effect);
        $this->assertArrayNotHasKey('delayedTriggers', (new ScenarioBuilder())->build(self::PASS)->toArray(), 'Omis quand vide');
    }
}
