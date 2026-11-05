<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\Discard;
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

/**
 * Mise sur la pile des triggers (APNAP, choix d'ordre et de cible hors priorité) et résolution ; triggers d'attaque, de
 * fin de tour, de sort lancé et de défausse ; ronde de priorité au cleanup (règle 514.3a).
 */
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
        $this->assertSame(['player:0'], array_column(array_column($this->of($r->events, 'DamageDealt'), 'payload'), 'target'), 'le trigger de J2 se résout d\'abord');
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

        // Deux triggers identiques : leur ordre est indifférent, aucune décision.
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

    // --- Triggers d'attaque, de fin de tour, de sort lancé et de défausse ; ronde de priorité au cleanup (514.3a) ---

    private static function raider(string $id): CardInstance
    {
        return new CardInstance($id, 'Pillard des Cendres', CardInstance::CREATURE, 3, power: 2, toughness: 2,
            triggers: [new TriggeredAbility(TriggeredAbility::ATTACKS, 'damage', 1)]);
    }

    private static function lantern(string $id): CardInstance
    {
        return new CardInstance($id, 'Lanterne du Crépuscule', CardInstance::ARTIFACT, 3,
            triggers: [new TriggeredAbility(TriggeredAbility::END_STEP, 'token', 1, 'Spirit:1/1')]);
    }

    private static function ashtray(string $id): CardInstance
    {
        return new CardInstance($id, 'Cendrier des Regrets', CardInstance::ARTIFACT, 2,
            triggers: [new TriggeredAbility(TriggeredAbility::DISCARD, 'damage', 1)]);
    }

    private static function pyromancer(string $id): CardInstance
    {
        return new CardInstance($id, 'Young Pyromancer', CardInstance::CREATURE, 2, power: 2, toughness: 1, manaCost: '{1}{R}',
            triggers: [new TriggeredAbility(TriggeredAbility::CAST, 'token', 1, 'Elemental:1/1', TriggeredAbility::INSTANT_SORCERY)]);
    }

    public function testNewTriggerKindsSerializeAndDescribeThemselves(): void
    {
        $cast = self::pyromancer('y')->triggers[0];
        $this->assertSame(['event' => 'cast', 'effect' => 'token', 'amount' => 1, 'spec' => 'Elemental:1/1', 'filter' => 'instant_sorcery'], $cast->toArray());
        $this->assertEquals($cast, TriggeredAbility::fromArray($cast->toArray()));
        $this->assertSame('Quand vous lancez un éphémère ou un rituel : 1 jeton Elemental 1/1', $cast->label());
        $this->assertArrayNotHasKey('filter', self::raider('r')->triggers[0]->toArray());
        $this->assertSame('Quand elle attaque : 1 dégât', self::raider('r')->triggers[0]->label());
        $this->assertSame('Au début de votre étape de fin : 1 jeton Spirit 1/1', self::lantern('l')->triggers[0]->label());
        $this->assertSame('Quand vous défaussez une carte : 1 dégât', self::ashtray('a')->triggers[0]->label());

        $this->assertTrue($cast->matches(ScenarioBuilder::bolt('b')));
        $this->assertTrue($cast->matches(new CardInstance('s', 'Rituel', CardInstance::SORCERY)));
        $this->assertFalse($cast->matches(new CardInstance('g', 'Grizzly Bears', CardInstance::CREATURE, 2)));

        $this->expectException(\InvalidArgumentException::class);
        new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1, '', TriggeredAbility::INSTANT_SORCERY);
    }

    public function testAttackTriggerResolvesDuringDeclareAttackersBeforeBlockers(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::raider('r1'))->build(self::J0_HUMAN);
        $s->phase = Phase::BeginCombat;
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $engine->advance($s, new PassPriority(0));

        // Seul attaquant possible : la déclaration se termine d'elle-même, puis le joueur humain choisit la cible du
        // trigger, hors priorité, avant le premier octroi de l'étape.
        $r = $engine->advance($s, new DeclareAttacker(0, 'r1', 2));
        $this->assertSame(['AttackerDeclared'], $this->types($r->events));
        $this->assertCount(1, $s->pendingTriggers);
        $this->assertSame('declare_attackers', $r->pending->toArray()['phase']);
        $this->assertSame(array_fill(0, 4, ['trigger', 0]), $this->actions($r));

        $r = $engine->advance($s, new PutTrigger(0, 'tr1', ['player:3']));
        $this->assertSame('Quand elle attaque : 1 dégât', $this->of($r->events, 'AbilityTriggered')[0]->payload['label']);
        $this->assertCount(1, $s->stack);

        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame(19, $s->player(3)->life);
        $this->assertSame('declare_attackers', $r->pending->toArray()['phase'], 'résolu avant l\'étape des bloqueurs');
        $this->assertSame([], $this->of($r->events, 'PhaseChanged'));
    }

    public function testEndStepTriggerOnlyForTheActivePlayersPermanents(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::lantern('l0'))->permanent(1, self::lantern('l1'))->build(self::J0_HUMAN);
        $s->phase = Phase::Main2;
        $engine = new GameEngine(new AgentRegistry());
        $r = $engine->advance($s);
        $this->assertSame([], $this->of($r->events, 'AbilityTriggered'));

        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame('end', $r->pending->toArray()['phase']);
        $triggered = $this->of($r->events, 'AbilityTriggered');
        $this->assertCount(1, $triggered);
        $this->assertSame([0, 'l0'], [$triggered[0]->payload['player'], $triggered[0]->payload['card']['id']]);

        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame(['Spirit'], array_map(static fn (GameEvent $e) => $e->payload['card']['name'], $this->of($r->events, 'TokenCreated')));
        $this->assertSame(0, $this->of($r->events, 'TokenCreated')[0]->payload['player']);
    }

    public function testCastTriggerOnlyForOwnInstantsAndSorceriesAndResolvesFirst(): void
    {
        $bear = new CardInstance('g1', 'Grizzly Bears', CardInstance::CREATURE, 2, power: 2, toughness: 2);
        $s = (new ScenarioBuilder())->permanent(0, self::pyromancer('y1'))
            ->hand(0, ScenarioBuilder::bolt('b1'), $bear)->untappedLands(0, 3)->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        // Créature : pas de trigger.
        $r = $engine->advance($s, new CastSpell(0, 'g1'));
        $this->assertSame([], $this->of($r->events, 'AbilityTriggered'));
        $engine->advance($s, new PassPriority(0));

        // Éphémère : le trigger va au-dessus du sort et se résout avant lui.
        $r = $engine->advance($s, new CastSpell(0, 'b1', ['player:2']));
        $this->assertSame(['SpellCast', 'AbilityTriggered', 'PriorityGranted'], $this->types($r->events));
        $this->assertSame(['Lightning Bolt', 'Young Pyromancer'], array_map(static fn (StackItem $i) => $i->card->name, $s->stack));
        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame([], $this->of($r->events, 'DamageDealt'));
        $this->assertSame('Elemental', $this->of($r->events, 'TokenCreated')[0]->payload['card']['name']);
        $engine->advance($s, new PassPriority(0));
        $this->assertSame(17, $s->player(2)->life);

        // Sort adverse : pas de trigger pour J0.
        $s = (new ScenarioBuilder())->permanent(0, self::pyromancer('y1'))
            ->hand(1, ScenarioBuilder::bolt('b2'))->untappedLands(1, 1)
            ->build([0 => 'ai:pass', 1 => 'scripted:bolt', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->phase = Phase::Upkeep;
        $s->stepStarted = false;
        $s->maxTurns = 1;
        $registry = new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')]);
        $r = (new GameEngine($registry))->advance($s);
        $this->assertCount(1, $this->of($r->events, 'SpellCast'));
        $this->assertSame([], $this->of($r->events, 'AbilityTriggered'));
    }

    public function testDiscardAsACostTriggers(): void
    {
        $mongrel = new CardInstance('wm', 'Wild Mongrel', CardInstance::CREATURE, 2, power: 2, toughness: 2, abilities: [ActivatedAbility::pump('+1/+1', null, 1)]);
        $s = (new ScenarioBuilder())->permanent(0, $mongrel)->permanent(0, self::ashtray('a1'))
            ->hand(0, new CardInstance('h1', 'Land', CardInstance::LAND))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $r = $engine->advance($s, new ActivateAbility(0, 'wm', 0, 0, [], false, ['h1']));
        $this->assertSame(['AbilityActivated', 'CardDiscarded'], $this->types($r->events));
        $this->assertSame(array_fill(0, 4, ['trigger', 0]), $this->actions($r));

        $engine->advance($s, new PutTrigger(0, 'tr1', ['player:1']));
        $this->assertSame(['Wild Mongrel', 'Cendrier des Regrets'], array_map(static fn (StackItem $i) => $i->card->name, $s->stack));
        $engine->advance($s, new PassPriority(0));
        $this->assertSame(19, $s->player(1)->life);
    }

    public function testCleanupDiscardTriggerOpensAPriorityRoundThenANewCleanupStep(): void
    {
        $hand = [];
        for ($i = 1; $i <= 9; $i++) {
            $hand[] = new CardInstance("h$i", "Spell$i", CardInstance::SORCERY, $i);
        }
        $s = (new ScenarioBuilder())->permanent(0, self::ashtray('a1'))->hand(0, ...$hand)->build(self::J0_HUMAN);
        $s->phase = Phase::Cleanup;
        $s->stepStarted = false;
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $this->assertSame(array_fill(0, 9, 'discard'), array_column($r->pending->toArray()['actions'], 'type'));
        $engine->advance($s, new Discard(0, 'h1'));
        $r = $engine->advance($s, new Discard(0, 'h2'));
        $this->assertTrue($s->cleanupRound);
        $this->assertSame(array_fill(0, 4, ['trigger', 0]), $this->actions($r), 'cible du premier trigger');

        $engine->advance($s, new PutTrigger(0, 'tr1', ['player:1']));
        $r = $engine->advance($s, new PutTrigger(0, 'tr2', ['player:2']));
        // Ronde de priorité au cleanup : octroi journalisé, checkpoint, priorité ordinaire (pas de défausse).
        $req = $r->pending->toArray();
        $this->assertSame(['cleanup', 0, 2], [$req['phase'], $req['priority']['holder'], count($req['stack'])]);
        $this->assertSame('pass', $req['actions'][0]['type']);
        $granted = $this->of($r->events, 'PriorityGranted');
        $this->assertTrue(end($granted)->payload['checkpoint']);

        // Reprise depuis un état sérialisé pendant la ronde : même suite.
        $copy = GameState::fromArray($s->toArray());
        $this->assertTrue($copy->cleanupRound);
        $r = $engine->advance($s, new PassPriority(0));
        $r2 = (new GameEngine(new AgentRegistry()))->advance($copy, new PassPriority(0));
        $this->assertSame($s->toArray(), $copy->toArray());
        $this->assertSame($this->types($r->events), $this->types($r2->events));
        $this->assertSame(19, $s->player(2)->life);
        $this->assertSame([], $this->of($r->events, 'CardDiscarded'), 'la défausse n\'est pas rejouée');

        // Dernier trigger résolu, ronde close : nouvelle étape de cleanup, puis tour suivant.
        $r = $engine->advance($s, new PassPriority(0));
        $this->assertSame(19, $s->player(1)->life);
        $r = $engine->advance($s, new PassPriority(0));
        $this->assertFalse($s->cleanupRound);
        $phases = array_map(static fn (GameEvent $e) => $e->payload['phase'], $this->of($r->events, 'PhaseChanged'));
        $this->assertSame('cleanup', $phases[0]);
        $this->assertContains('untap', $phases);
        $this->assertSame(1, $s->activePlayer);
        $this->assertCount(7, $s->player(0)->hand);
    }
}
