<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PendingTrigger;
use App\Engine\Game\Phase;
use App\Engine\Game\StackItem;
use App\Engine\Game\TurnManager;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Rules\CombatManager;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/** Détection des triggers : mise en file à l'entrée en jeu, à la mort et au début de l'entretien. */
final class TriggerQueueTest extends TestCase
{
    private const AI = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    public static function visionary(string $id): CardInstance
    {
        return new CardInstance($id, 'Elvish Visionary', CardInstance::CREATURE, 2, power: 1, toughness: 1, manaCost: '{1}{G}',
            triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)]);
    }

    public static function myr(string $id, bool $token = false): CardInstance
    {
        return new CardInstance($id, 'Perilous Myr', CardInstance::CREATURE, 2, power: 1, toughness: 1, manaCost: '{2}', token: $token,
            triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'damage', 2)]);
    }

    public static function idol(string $id): CardInstance
    {
        return new CardInstance($id, "Idole de l'Érudit", CardInstance::ARTIFACT, 3, manaCost: '{3}',
            triggers: [new TriggeredAbility(TriggeredAbility::UPKEEP, 'draw', 1)]);
    }

    /** @return list<array{string, int, string, int}> [id, contrôleur, nom de la source, index] */
    private function pending(GameState $s): array
    {
        return array_map(static fn (PendingTrigger $t) => [$t->id, $t->controller, $t->source->name, $t->index], $s->pendingTriggers);
    }

    public function testResolvedPermanentQueuesItsEtbTrigger(): void
    {
        $s = (new ScenarioBuilder())->build(self::AI);
        $s->stack[] = new StackItem('s1', self::visionary('v1'), 0);

        (new StackResolver())->resolveTop($s);

        $this->assertSame([['tr1', 0, 'Elvish Visionary', 0]], $this->pending($s));
        $this->assertSame($s->turn, $s->player(0)->findOnBattlefield('v1')->enteredTurn);
    }

    public function testOnlyMatchingEventsAreQueued(): void
    {
        $s = (new ScenarioBuilder())->build(self::AI);
        $s->enterBattlefield(1, self::myr('m1'));
        $s->enterBattlefield(1, self::idol('i1'));

        $this->assertSame([], $s->pendingTriggers);
    }

    public function testLethallyDamagedCreatureQueuesItsDiesTrigger(): void
    {
        $myr = self::myr('m1');
        $myr->damage = 1;
        $s = (new ScenarioBuilder())->permanent(2, $myr)->build(self::AI);

        (new StateBasedActions())->check($s);

        $this->assertSame([['tr1', 2, 'Perilous Myr', 0]], $this->pending($s));
        $this->assertSame('Perilous Myr', $s->player(2)->graveyard[0]->name);
    }

    public function testSacrificedTokenCreatureStillDies(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::myr('t1', token: true))->build(self::AI);

        $s->sacrifice(0, ['t1']);

        $this->assertSame([['tr1', 0, 'Perilous Myr', 0]], $this->pending($s));
        $this->assertSame([], $s->player(0)->graveyard, 'le jeton cesse d\'exister');
    }

    public function testSacrificedNonCreatureDoesNotDie(): void
    {
        $idol = new CardInstance('a1', 'Artefact mortel', CardInstance::ARTIFACT,
            triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'draw', 1)]);
        $s = (new ScenarioBuilder())->permanent(0, $idol)->build(self::AI);

        $s->sacrifice(0, ['a1']);

        $this->assertSame([], $s->pendingTriggers);
    }

    public function testUpkeepQueuesOnlyTheActivePlayersTriggers(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::idol('i0'))->permanent(1, self::idol('i1'))->build(self::AI);
        $s->phase = Phase::Upkeep;
        $s->stepStarted = false;

        $registry = new AgentRegistry();
        (new TurnManager(new StateBasedActions(), new CombatManager($registry, new LegalActionGenerator())))->beginStep($s);

        $this->assertSame([['tr1', 0, "Idole de l'Érudit", 0]], $this->pending($s));
    }

    public function testPendingTriggersSurviveSerialization(): void
    {
        $myr = self::myr('m1');
        $myr->damage = 1;
        $s = (new ScenarioBuilder())->permanent(3, $myr)->build(self::AI);
        (new StateBasedActions())->check($s);
        $s->triggerResumeHolder = 1;

        $copy = GameState::fromArray($s->toArray());

        $this->assertSame($s->toArray(), $copy->toArray());
        $this->assertSame('damage', $copy->pendingTriggers[0]->ability()->effect);
        $this->assertSame(1, $copy->triggerResumeHolder);
        $this->assertSame(2, $copy->nextTriggerId);
    }

    public function testCardsWithoutTriggersKeepTheirShape(): void
    {
        $plain = new CardInstance('c1', 'Grizzly Bears', CardInstance::CREATURE, 2, power: 2, toughness: 2);

        $this->assertArrayNotHasKey('triggers', $plain->toPublic());
        $this->assertSame([['event' => 'dies', 'effect' => 'damage', 'amount' => 2]], self::myr('m1')->toPublic()['triggers']);
        $this->assertSame('Quand elle meurt : 2 dégâts', self::myr('m1')->triggers[0]->label());
    }
}
