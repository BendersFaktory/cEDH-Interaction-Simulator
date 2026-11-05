<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Discard;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\StopConfig;
use PHPUnit\Framework\TestCase;

/** Stops configurables de `human:auto` : la config vit dans GameState, l'agent reste sans état. */
final class StopsTest extends TestCase
{
    private function engine(): GameEngine
    {
        return new GameEngine(new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')]));
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    private function humanWithBolt(): GameState
    {
        return (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass']);
    }

    public function testWithoutStopsTheAgentStopsAtTheFirstUsefulAction(): void
    {
        $s = $this->humanWithBolt();
        $result = $this->engine()->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame('main1', $result->pending->toArray()['phase']);
        $this->assertArrayNotHasKey('stops', $s->toArray());
    }

    public function testUncheckedStepsArePassedButStillJournaled(): void
    {
        $s = $this->humanWithBolt();
        $s->setStops(0, new StopConfig([Phase::End]));
        $result = $this->engine()->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame(0, $result->pending->player);
        $this->assertSame('end', $result->pending->toArray()['phase']);
        $this->assertSame(1, $s->turn);
        // Règle 4 : les passes automatiques des étapes non cochées sont journalisées.
        $passesByHuman = array_filter($this->of($result->events, 'PriorityPassed'), static fn (GameEvent $e) => $e->payload['player'] === 0);
        $this->assertNotEmpty($passesByHuman);
        $this->assertCount(1, $s->player(0)->hand);
    }

    public function testOpponentSpellStopsEvenOutsideCheckedSteps(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)
            ->build([0 => 'scripted:bolt', 1 => 'human:auto', 2 => 'ai:pass']);
        $s->setStops(1, new StopConfig([], onOpponentSpell: true));

        $result = $this->engine()->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame(1, $result->pending->player);
        $this->assertCount(1, $s->stack);
        $this->assertSame(0, $s->stack[0]->controller);
    }

    public function testWithoutOpponentSpellStopTheSpellResolves(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->hand(1, ScenarioBuilder::counterspell('cs1'))->untappedLands(1, 2)
            ->build([0 => 'scripted:bolt', 1 => 'human:auto', 2 => 'ai:pass']);
        $s->setStops(1, new StopConfig([Phase::Upkeep, Phase::End]));
        $s->maxTurns = 2;

        $result = $this->engine()->advance($s);

        $this->assertNull($result->pending, 'Counterspell n\'est jouable que sort adverse sur la pile : jamais d\'arrêt');
        $this->assertSame(17, $s->player(2)->life);
        $this->assertSame('Counterspell', $s->player(1)->hand[0]->name);
    }

    public function testDeclareAttackersStop(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2)->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->phase = Phase::BeginCombat;
        $s->setStops(0, new StopConfig([], onDeclareAttackers: true));

        $result = $this->engine()->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame(CombatState::ATTACKERS, $s->combat->declaring);
    }

    public function testWithoutDeclareAttackersStopTheAgentDoesNotAttack(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'c1', 2, 2)->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->phase = Phase::BeginCombat;
        $s->setStops(0, new StopConfig([], onDeclareBlockers: true));
        $s->maxTurns = 2;

        $result = $this->engine()->advance($s);

        $this->assertNull($result->pending);
        $this->assertSame([], $this->of($result->events, 'AttackerDeclared'));
    }

    public function testMandatoryDiscardAlwaysStops(): void
    {
        $hand = [];
        for ($i = 0; $i < 9; $i++) {
            $hand[] = new CardInstance("h$i", 'Land', CardInstance::LAND);
        }
        $s = (new ScenarioBuilder())->hand(0, ...$hand)->build([0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->setStops(0, new StopConfig());

        $result = $this->engine()->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame('cleanup', $result->pending->toArray()['phase']);
        $this->assertContainsOnlyInstancesOf(Discard::class, $result->pending->legalActions);
    }

    public function testSetStopsNormalizesTheDefault(): void
    {
        $s = $this->humanWithBolt();
        $s->setStops(0, new StopConfig([Phase::Upkeep]));
        $this->assertArrayHasKey(0, $s->stops);

        $s->setStops(0, StopConfig::all());
        $this->assertSame([], $s->stops, 'une config équivalente au défaut est omise');

        $s->setStops(0, new StopConfig([Phase::Upkeep]));
        $s->setStops(0, null);
        $this->assertSame([], $s->stops);

        $this->expectException(\InvalidArgumentException::class);
        $s->setStops(9, new StopConfig());
    }

    public function testStateRoundTripKeepsStops(): void
    {
        $s = $this->humanWithBolt();
        $s->setStops(2, new StopConfig([Phase::End, Phase::Upkeep, Phase::End], onDeclareBlockers: true));
        $s->setStops(0, new StopConfig([Phase::Main1], onOpponentSpell: true, onDeclareAttackers: true));

        $array = $s->toArray();
        $this->assertSame([
            0 => ['steps' => ['main1'], 'onOpponentSpell' => true, 'onDeclareAttackers' => true, 'onDeclareBlockers' => false],
            2 => ['steps' => ['upkeep', 'end'], 'onOpponentSpell' => false, 'onDeclareAttackers' => false, 'onDeclareBlockers' => true],
        ], $array['stops']);

        // Aller-retour par JSON, comme dans les snapshots (clés entières devenues chaînes).
        $restored = GameState::fromArray(json_decode(json_encode($array), true));
        $this->assertSame($array, $restored->toArray());
        $this->assertEquals($s->stops, $restored->stops);
    }

    public function testStateWithoutStopsHasNoStopsKey(): void
    {
        $restored = GameState::fromArray($this->humanWithBolt()->toArray());
        $this->assertSame([], $restored->stops);
        $this->assertArrayNotHasKey('stops', $restored->toArray());
    }

    public function testUnknownStepIsRejected(): void
    {
        $this->expectException(\ValueError::class);
        StopConfig::fromArray(['steps' => ['nope']]);
    }
}
