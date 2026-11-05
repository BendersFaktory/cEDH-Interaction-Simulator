<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\KeepLegend;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\CombatResolver;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/**
 * Actions basées sur l'état (CR 704) : boucle 704.3, pioche sur bibliothèque vide (704.5b), endurance nulle (704.5f),
 * règle des légendes (704.5j), commandant renvoyé en zone de commandement (903.9a), joueur qui quitte la partie (800.4a).
 */
final class StateBasedActionsTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

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

    private static function legend(string $id, bool $tapped = false, int $enteredTurn = 0): CardInstance
    {
        return new CardInstance($id, 'Isamaru', CardInstance::CREATURE, 1, null, 0, $tapped, false, 2, 2, 0, $enteredTurn, '{W}', supertypes: [CardInstance::LEGENDARY]);
    }

    private function sba(): StateBasedActions
    {
        return new StateBasedActions(new AgentRegistry(), new LegalActionGenerator());
    }

    public function testDrawingFromAnEmptyLibraryLosesOnlyAtTheNextCheck(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(1)->library = [new CardInstance('last', 'Land', CardInstance::LAND)];
        $source = new CardInstance('src', 'Source', CardInstance::ARTIFACT);
        $s->stack[] = new StackItem('s1', $source, 1, [], 0, null, 0, new TriggeredAbility(TriggeredAbility::ETB, 'draw', 2));

        (new StackResolver())->resolveTop($s);
        $this->assertFalse($s->player(1)->lost, 'la résolution va au bout : pas d\'élimination immédiate');
        $this->assertTrue($s->player(1)->drewFromEmpty);
        $this->assertSame('last', $s->player(1)->hand[0]->id);
        $copy = GameState::fromArray($s->toArray());
        $this->assertTrue($copy->player(1)->drewFromEmpty, 'sérialisé');

        $s->drainEvents();
        $this->sba()->check($s);
        $lost = $this->of($s->drainEvents(), 'PlayerLost');
        $this->assertSame(['player' => 1, 'reason' => 'decked'], $lost[0]->payload);
        $this->assertFalse($s->player(1)->drewFromEmpty);
        $this->assertArrayNotHasKey('drewFromEmpty', $s->player(0)->toArray(), 'omis à faux');
    }

    public function testDrawStepWithAnEmptyLibraryLosesBeforePriority(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->phase = Phase::Draw;
        $s->stepStarted = false;
        $s->player(0)->library = [];

        $events = (new GameEngine(new AgentRegistry()))->advance($s)->events;
        $types = $this->types($events);
        $lost = array_search('PlayerLost', $types, true);
        $this->assertNotFalse($lost);
        $this->assertSame('decked', $events[$lost]->payload['reason']);
        $this->assertSame(0, $events[$lost]->payload['player']);
        $this->assertSame('PhaseChanged', $types[$lost - 1], 'perdu à la première vérification de l\'étape, avant tout octroi');
    }

    public function testSimultaneousLossesEndInADraw(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $s->player(0)->life = 0;
        $s->player(1)->life = -3;

        $this->sba()->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['PlayerLost', 'PlayerLost', 'GameOver'], $this->types($events), 'sans permanent ni objet sur la pile : pas de PlayerLeftGame');
        $this->assertTrue($s->over);
        $this->assertNull($s->winner);
    }

    public function testCreatureWithZeroToughnessIsPutIntoTheGraveyard(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'zero', 1, 0)->creature(0, 'ok', 1, 1)->build(self::PASS);

        $this->sba()->check($s);
        $died = $this->of($s->drainEvents(), 'CreatureDied');
        $this->assertCount(1, $died);
        $this->assertSame('zero', $died[0]->payload['card']['id']);
        $this->assertSame('toughness', $died[0]->payload['reason']);
        $this->assertSame('zero', $s->player(0)->graveyard[0]->id);
        $this->assertNotNull($s->player(0)->findOnBattlefield('ok'));
    }

    public function testDyingCommanderTriggersThenReturnsOnTheNextPass(): void
    {
        $commander = new CardInstance('cmd', 'Test Commander', CardInstance::CREATURE, 3, null, 0, false, true, 3, 3,
            supertypes: [CardInstance::LEGENDARY], triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'draw', 1)]);
        $commander->damage = 3;
        $s = (new ScenarioBuilder())->permanent(0, $commander)->build(self::PASS);

        $this->sba()->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['CreatureDied', 'CommanderReturned'], $this->types($events), 'deux passages en un seul appel (CR 704.3)');
        $this->assertSame('graveyard', $events[0]->payload['destination']);
        $this->assertSame('damage', $events[0]->payload['reason']);
        $this->assertSame(['player' => 0, 'card' => $commander->toPublic(), 'from' => 'graveyard'], $events[1]->payload);
        $this->assertSame('cmd', $s->player(0)->command[0]->id);
        $this->assertSame([], $s->player(0)->graveyard);
        $this->assertCount(1, $s->pendingTriggers, 'son trigger « meurt » s\'est déclenché');
        $this->assertSame('cmd', $s->pendingTriggers[0]->source->id);
    }

    public function testAiKeepsTheUntappedLegendAndPriorityIsRestored(): void
    {
        $s = (new ScenarioBuilder())
            ->permanent(1, self::legend('old', tapped: true))
            ->permanent(1, self::legend('new', enteredTurn: 1))
            ->build([0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic']);
        $s->priority = new PriorityState(2, [0, 1]);

        $this->sba()->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['LegendRuleApplied'], $this->types($events));
        $applied = $events[0]->payload;
        $this->assertSame(1, $applied['player']);
        $this->assertSame('new', $applied['kept']);
        $this->assertSame('old', $applied['removed'][0]['card']['id']);
        $this->assertSame('graveyard', $applied['removed'][0]['destination']);
        $this->assertNull($s->player(1)->findOnBattlefield('old'));
        $this->assertSame('old', $s->player(1)->graveyard[0]->id);
        $this->assertSame(['holder' => 2, 'passed' => [0, 1], 'announced' => false], $s->priority->toArray());
        $this->assertNull($s->sbaResume);
    }

    public function testHumanChoosesWhichLegendToKeepAndTheGameResumes(): void
    {
        $s = (new ScenarioBuilder())
            ->permanent(0, self::legend('l1'))
            ->permanent(0, self::legend('l2', enteredTurn: 1))
            ->build([0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass']);
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $this->assertNotNull($r->pending);
        $request = $r->pending->toArray();
        $this->assertSame(['keepLegend', 'keepLegend'], array_column($request['actions'], 'type'));
        $this->assertSame(['legend:0:l1', 'legend:0:l2'], array_column($request['actions'], 'id'));
        $this->assertSame([], $this->of($r->events, 'PriorityGranted'), 'aucune priorité avant la règle des légendes');

        $copy = GameState::fromArray($s->toArray());
        $this->assertNotNull($copy->sbaResume, 'la priorité d\'avant est sérialisée');
        $r = $engine->advance($copy, new KeepLegend(0, 'l2'));
        $types = $this->types($r->events);
        $this->assertSame('LegendRuleApplied', $types[0]);
        $this->assertSame('l2', $r->events[0]->payload['kept']);
        $this->assertSame('PriorityGranted', $types[1]);
        $this->assertSame(0, $r->events[1]->payload['holder']);
        $this->assertNotNull($copy->player(0)->findOnBattlefield('l2'));
        $this->assertNull($copy->player(0)->findOnBattlefield('l1'));
        $this->assertNull($copy->sbaResume);
    }

    public function testEliminatedPlayerObjectsLeaveTheGame(): void
    {
        $s = (new ScenarioBuilder())
            ->creature(1, 'c1', 2, 2)
            ->creature(2, 'c2', 2, 2)
            ->build(self::PASS);
        $s->stack[] = new StackItem('s1', ScenarioBuilder::bolt('b1'), 1, ['player:2']);
        $s->stack[] = new StackItem('s2', ScenarioBuilder::bolt('b2'), 2, ['player:1']);
        $s->player(1)->life = 0;

        $this->sba()->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['PlayerLost', 'PlayerLeftGame'], $this->types($events));
        $this->assertSame(['player' => 1, 'battlefield' => ['c1'], 'stack' => ['s1']], $events[1]->payload);
        $this->assertSame([], $s->player(1)->battlefield);
        $this->assertSame(['s2'], array_map(static fn (StackItem $i) => $i->id, $s->stack));
        $this->assertNotNull($s->player(2)->findOnBattlefield('c2'));
        $this->assertFalse($s->over);
    }

    public function testAttackerOfAnEliminatedActivePlayerDealsNoDamage(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'a', 3, 3)->build(self::PASS);
        $s->combat->attackers = ['a' => 1];
        $s->player(0)->life = 0;

        $this->sba()->check($s);
        $s->drainEvents();
        (new CombatResolver())->resolve($s);
        $this->assertSame([], $this->of($s->drainEvents(), 'DamageDealt'));
        $this->assertSame(20, $s->player(1)->life);
    }
}
