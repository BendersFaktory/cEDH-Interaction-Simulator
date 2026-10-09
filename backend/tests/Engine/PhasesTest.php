<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Action\PlayLand;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\Phase;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Structure complète du tour : untap, upkeep, pioche, main 1, combat, main 2, fin, cleanup. */
final class PhasesTest extends TestCase
{
    private const FOUR_PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    public function testATurnWalksThroughEveryStepInOrder(): void
    {
        $s = GameFactory::create(3, self::FOUR_PASS);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $phases = [];
        foreach ($result->events as $e) {
            if ($e->type === 'PhaseChanged' && $e->payload['turn'] === 1) {
                $phases[] = $e->payload['phase'];
            }
        }

        $this->assertSame(
            ['untap', 'upkeep', 'draw', 'main1', 'begin_combat', 'end_combat', 'main2', 'end', 'cleanup'],
            $phases,
        );
    }

    public function testUntapAndCleanupNeverOpenAPriorityRound(): void
    {
        $s = GameFactory::create(3, self::FOUR_PASS);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $phase = null;
        $granted = [];
        foreach ($result->events as $e) {
            if ($e->type === 'PhaseChanged') {
                $phase = $e->payload['phase'];
            } elseif ($e->type === 'PriorityGranted') {
                $granted[$phase] = true;
            }
        }

        $this->assertArrayNotHasKey('untap', $granted);
        $this->assertArrayNotHasKey('cleanup', $granted);
        foreach (['upkeep', 'draw', 'main1', 'begin_combat', 'end_combat', 'main2', 'end'] as $step) {
            $this->assertArrayHasKey($step, $granted, "$step doit ouvrir une ronde de priorité");
        }
    }

    public function testThePlayerUntapsDuringHisUntapStep(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(1, 2)->build([0 => 'ai:pass', 1 => 'ai:pass']);
        foreach ($s->player(1)->battlefield as $land) {
            $land->tapped = true;
        }
        // Fin du tour du joueur 0 : le joueur 1 est le suivant.
        $s->phase = Phase::Cleanup;

        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $untapped = array_values(array_filter($result->events, static fn ($e) => $e->type === 'PermanentsUntapped'));
        $this->assertNotEmpty($untapped);
        $this->assertSame(1, $untapped[0]->payload['player']);
        $this->assertSame(['p1_land0', 'p1_land1'], $untapped[0]->payload['cards']);
    }

    public function testOnlyOneLandPerTurnAcrossBothMainPhases(): void
    {
        $land = new CardInstance('l1', 'Land', CardInstance::LAND);
        $s = (new ScenarioBuilder())->hand(0, $land)->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $legal = new LegalActionGenerator();

        foreach ([Phase::Main1, Phase::Main2] as $phase) {
            $s->phase = $phase;
            $s->landsPlayed = 0;
            $this->assertNotEmpty(array_filter($legal->for($s, 0), static fn ($a) => $a instanceof PlayLand));
            $s->landsPlayed = 1;
            $this->assertEmpty(array_filter($legal->for($s, 0), static fn ($a) => $a instanceof PlayLand));
        }
    }

    public function testSorceryTimingOnlyInMainButInstantsAnywhere(): void
    {
        $bear = new CardInstance('g1', 'Grizzly Bears', CardInstance::CREATURE, 1);
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'), $bear)->untappedLands(0, 2)
            ->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $legal = new LegalActionGenerator();
        $names = fn () => array_unique(array_map(
            static fn ($a) => $s->player(0)->findCastable($a->cardId)->name,
            array_filter($legal->for($s, 0), static fn ($a) => $a instanceof CastSpell),
        ));

        $s->phase = Phase::Main2;
        $this->assertContains('Grizzly Bears', $names());

        foreach ([Phase::Upkeep, Phase::BeginCombat, Phase::End] as $phase) {
            $s->phase = $phase;
            $this->assertNotContains('Grizzly Bears', $names(), $phase->value);
            $this->assertContains('Lightning Bolt', $names(), $phase->value);
        }
    }

    public function testASpellCastInUpkeepStillGivesPriorityToTheWholeTable(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b1'))->untappedLands(0, 1)
            ->build([0 => 'scripted:bolt', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->phase = Phase::Upkeep;

        $registry = new AgentRegistry(['scripted:bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')]);
        $result = (new GameEngine($registry))->advance($s);

        $this->assertSame(17, $s->player(2)->life);
        $holders = [];
        $afterCast = false;
        foreach ($result->events as $e) {
            if ($e->type === 'SpellCast') {
                $afterCast = true;
            } elseif ($afterCast && $e->type === 'PriorityGranted') {
                $holders[] = $e->payload['holder'];
                if (count($holders) === 4) {
                    break;
                }
            }
        }
        $this->assertSame([0, 1, 2, 3], $holders);
    }
}
