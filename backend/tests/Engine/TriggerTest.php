<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PutTrigger;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\AdvanceResult;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use PHPUnit\Framework\TestCase;

/** Mise sur la pile des capacités déclenchées (APNAP, choix d'ordre et de cible hors priorité) et résolution. */
final class TriggerTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function types(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->type, $events);
    }

    /** @return list<array{string, int}> [type d'action, joueur] des actions proposées */
    private function actions(AdvanceResult $r): array
    {
        return array_map(static fn (array $a) => [$a['type'], $a['player']], $r->pending->toArray()['actions']);
    }

    private static function dyingMyr(string $id): CardInstance
    {
        $myr = TriggerQueueTest::myr($id);
        $myr->damage = 1;

        return $myr;
    }

    public function testEtbTriggerGoesOnTheStackAfterTheSpellAndResolves(): void
    {
        $visionary = new CardInstance('v1', 'Elvish Visionary', CardInstance::CREATURE, 2, power: 1, toughness: 1,
            triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)]);
        $s = (new ScenarioBuilder())->hand(0, $visionary)->untappedLands(0, 2)->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $engine->advance($s, new CastSpell(0, 'v1'));
        $r = $engine->advance($s, new PassPriority(0));
        $resolved = $this->types($r->events);
        $this->assertSame(['SpellResolved', 'AbilityTriggered', 'PriorityGranted'], array_slice($resolved, array_search('SpellResolved', $resolved, true), 3));
        $triggered = $this->of($r->events, 'AbilityTriggered')[0]->payload;
        $this->assertSame(['player' => 0, 'stackId' => 's2', 'triggerIndex' => 0, 'label' => 'Quand elle entre : piocher 1', 'targets' => []],
            array_intersect_key($triggered, array_flip(['player', 'stackId', 'triggerIndex', 'label', 'targets'])));
        $this->assertSame(0, $r->pending->toArray()['priority']['holder']);
        $this->assertSame([], $r->pending->toArray()['priority']['passed']);
        $this->assertCount(1, $s->stack);
        $this->assertSame([], $s->pendingTriggers);

        $hand = count($s->player(0)->hand);
        $r = $engine->advance($s, new PassPriority(0));
        $this->assertTrue($this->of($r->events, 'AbilityResolved')[0]->payload['trigger']);
        $this->assertSame(0, $this->of($r->events, 'CardDrawn')[0]->payload['player']);
        $this->assertSame($hand + 1, count($s->player(0)->hand));
        $this->assertSame([], $s->stack);
    }

    public function testHumanChoosesTheTargetOfADiesTriggerOutsidePriority(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::dyingMyr('m1'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $this->assertSame('CreatureDied', $this->types($r->events)[array_key_last($r->events)]);
        $this->assertSame(array_fill(0, 4, ['trigger', 0]), $this->actions($r));
        $this->assertSame('Mettre sur la pile : Perilous Myr (Quand elle meurt : 2 dégâts) → joueur 2', $r->pending->toArray()['actions'][2]['label']);
        $this->assertSame(0, $s->triggerResumeHolder);

        // Reprise depuis un état sérialisé au milieu du choix : même suite.
        $copy = GameState::fromArray($s->toArray());
        $r = $engine->advance($s, new PutTrigger(0, 'tr1', ['player:2']));
        $r2 = (new GameEngine(new AgentRegistry()))->advance($copy, new PutTrigger(0, 'tr1', ['player:2']));
        $this->assertSame($s->toArray(), $copy->toArray());
        $this->assertSame($this->types($r->events), $this->types($r2->events));

        $this->assertSame(['AbilityTriggered', 'PriorityGranted'], $this->types($r->events));
        $this->assertNull($s->triggerResumeHolder);
        $this->assertSame(['player:2'], $s->stack[0]->targets);

        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame(18, $s->player(2)->life);
        $this->assertSame(['source' => 'Perilous Myr', 'target' => 'player:2', 'amount' => 2, 'life' => 18], $this->of($r->events, 'DamageDealt')[0]->payload);
    }

    public function testApnapActivePlayerTriggersGoOnTheStackFirstAndResolveLast(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::dyingMyr('m0'))->permanent(2, self::dyingMyr('m2'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $this->assertSame(array_fill(0, 4, ['trigger', 0]), $this->actions($r), 'le joueur actif choisit en premier');
        $this->assertSame([], $s->stack);

        $r = $engine->advance($s, new PutTrigger(0, 'tr1', ['player:1']));
        // J2 (ai:pass) pose ensuite le sien sur la première cible proposée.
        $this->assertSame([0, 2], array_map(static fn (StackItem $i) => $i->controller, $s->stack));
        $this->assertSame([['player:1'], ['player:0']], array_map(static fn (StackItem $i) => $i->targets, $s->stack));
        $this->assertSame([0, 2], array_map(static fn (GameEvent $e) => $e->payload['player'], $this->of($r->events, 'AbilityTriggered')));
        $this->assertSame(0, $r->pending->toArray()['priority']['holder']);

        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame(['player:0'], array_column(array_column($this->of($r->events, 'DamageDealt'), 'payload'), 'target'), 'le déclencheur de J2 se résout d\'abord');
    }

    public function testControllerOrdersDistinctTriggersAndIdenticalOnesNeedNoChoice(): void
    {
        $draw = new CardInstance('i1', 'Idole A', CardInstance::ARTIFACT, triggers: [new TriggeredAbility(TriggeredAbility::UPKEEP, 'draw', 1)]);
        $token = new CardInstance('i2', 'Idole B', CardInstance::ARTIFACT, triggers: [new TriggeredAbility(TriggeredAbility::UPKEEP, 'token', 1, 'Kobold:0/1')]);
        $s = (new ScenarioBuilder())->permanent(0, $draw)->permanent(0, $token)->build(self::J0_HUMAN);
        $s->phase = Phase::Upkeep;
        $s->stepStarted = false;
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $this->assertSame([['trigger', 0], ['trigger', 0]], $this->actions($r));
        $r = $engine->advance($s, new PutTrigger(0, 'tr2'));
        $this->assertSame(['Idole B', 'Idole A'], array_map(static fn (StackItem $i) => $i->card->name, $s->stack), 'le premier choisi va en dessous');
        $this->assertSame(0, $r->pending->toArray()['priority']['holder']);

        // Deux déclencheurs identiques : leur ordre est indifférent, aucune décision.
        $s = (new ScenarioBuilder())->permanent(0, TriggerQueueTest::idol('a'))->permanent(0, TriggerQueueTest::idol('b'))->build(self::J0_HUMAN);
        $s->phase = Phase::Upkeep;
        $s->stepStarted = false;
        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertSame('pass', $r->pending->toArray()['actions'][0]['type']);
        $this->assertCount(2, $s->stack);
    }

    public function testSacrificeAsACostTriggersAboveTheActivatedAbility(): void
    {
        $bombardment = new CardInstance('gb', 'Goblin Bombardment', CardInstance::ENCHANTMENT, abilities: [ActivatedAbility::bombardment(1)]);
        $s = (new ScenarioBuilder())->permanent(0, $bombardment)->permanent(0, TriggerQueueTest::myr('m1'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $r = $engine->advance($s, new ActivateAbility(0, 'gb', 0, 0, ['player:1'], false, ['m1']));
        $this->assertSame(['AbilityActivated', 'PermanentSacrificed'], $this->types($r->events));
        $this->assertSame('trigger', $r->pending->toArray()['actions'][0]['type']);

        $engine->advance($s, new PutTrigger(0, 'tr1', ['player:3']));
        $this->assertSame(['Goblin Bombardment', 'Perilous Myr'], array_map(static fn (StackItem $i) => $i->card->name, $s->stack));
        $this->assertTrue($s->stack[1]->isTrigger());
    }

    public function testCounterspellCannotTargetATrigger(): void
    {
        $s = (new ScenarioBuilder())->hand(1, ScenarioBuilder::counterspell('cs'))->untappedLands(1, 2)->build(self::J0_HUMAN);
        $s->stack[] = new StackItem('s1', TriggerQueueTest::myr('m1'), 0, ['player:1'], 0, null, 0, TriggerQueueTest::myr('m1')->triggers[0]);

        $legal = (new \App\Engine\Rules\LegalActionGenerator())->for($s, 1);
        $this->assertSame([], array_filter($legal, static fn ($a) => $a instanceof CastSpell));
    }

    public function testTriggersOfAnEliminatedPlayerAreDropped(): void
    {
        $s = (new ScenarioBuilder())->permanent(1, self::dyingMyr('m1'))->build(self::J0_HUMAN);
        $s->player(1)->life = 0;

        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertSame([], $this->of($r->events, 'AbilityTriggered'));
        $this->assertSame([], $s->pendingTriggers);
    }
}
