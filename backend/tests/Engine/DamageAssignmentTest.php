<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\AssignCombatDamage;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Rules\DamageAssignment;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Répartition libre des dégâts de combat par l'attaquant humain (CR 510.1c, 702.19b, 702.2c). */
final class DamageAssignmentTest extends TestCase
{
    private const HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    /** État à l'entrée de l'étape de dégâts : 'atk' (J0) attaque J1, bloqué par $blockers dans cet ordre. */
    private function atDamage(ScenarioBuilder $b, array $blockers, array $controllers = self::HUMAN, Phase $phase = Phase::CombatDamage): GameState
    {
        $s = $b->build($controllers);
        $s->phase = $phase;
        $s->stepStarted = false;
        $s->combat = new CombatState(null, ['atk' => 1], $blockers === [] ? [] : ['atk' => $blockers]);

        return $s;
    }

    /** @param list<Action> $actions @return list<string> */
    private function vectors(array $actions): array
    {
        return array_map(static fn (AssignCombatDamage $a) => DamageAssignment::key($a->assignment), $actions);
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function died(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->payload['card']['id'],
            array_values(array_filter($events, static fn (GameEvent $e) => $e->type === 'CreatureDied')));
    }

    /** @param list<GameEvent> $events @return array<string, int> dégâts marqués par créature */
    private function damaged(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            if ($e->type === 'CreatureDamaged') {
                $out[$e->payload['card']] = $e->payload['amount'];
            }
        }

        return $out;
    }

    private function actionsFor(GameState $s): array
    {
        $s->combat->declaring = CombatState::DAMAGE;

        return (new LegalActionGenerator())->for($s, 0);
    }

    public function testExampleOf510_1cListsEveryDivisionWithTheDefaultFirst(): void
    {
        // CR 510.1c : un 4/3 bloqué par un 2/3 et un 1/1 peut tout répartir librement (5 répartitions).
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 4, 3)->creature(1, 'spawn', 2, 3)->creature(1, 'hunter', 1, 1), ['spawn', 'hunter']);
        $actions = $this->actionsFor($s);

        $this->assertSame(['spawn=3,hunter=1', 'spawn=4,hunter=0', 'spawn=2,hunter=2', 'spawn=1,hunter=3', 'spawn=0,hunter=4'], $this->vectors($actions));
        $this->assertTrue($actions[0]->isDefault);
        $this->assertSame('assign:0:atk:spawn=3,hunter=1', $actions[0]->id());
        $this->assertStringContainsString('(par défaut)', $actions[0]->describe($s));
        $this->assertSame([], (new LegalActionGenerator())->for($s, 1), 'seul le contrôleur de l\'attaquant choisit');
    }

    public function testTrampleNeedsLethalOnEveryBlockerBeforeThePlayer(): void
    {
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 5, 5, false, 0, [CardInstance::TRAMPLE])
            ->creature(1, 'b1', 2, 2)->creature(1, 'b2', 1, 1), ['b1', 'b2']);
        $vectors = $this->vectors($this->actionsFor($s));

        $this->assertSame('b1=2,b2=1,player:1=2', $vectors[0], 'défaut : létal à chacun, le reste au joueur');
        $this->assertContains('b1=3,b2=1,player:1=1', $vectors);
        $this->assertContains('b1=4,b2=1,player:1=0', $vectors);
        $this->assertContains('b1=0,b2=5,player:1=0', $vectors, 'sans létal partout, tout reste sur les bloqueurs');
        $this->assertNotContains('b1=1,b2=1,player:1=3', $vectors);
        $this->assertNotContains('b1=2,b2=0,player:1=3', $vectors);
    }

    public function testTrampleWithASingleBlockerIsAChoiceToo(): void
    {
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 4, 4, false, 0, [CardInstance::TRAMPLE])->creature(1, 'b1', 2, 2), ['b1']);
        $this->assertSame(['b1=2,player:1=2', 'b1=4,player:1=0', 'b1=3,player:1=1'], $this->vectors($this->actionsFor($s)));
    }

    public function testDeathtouchAndMarkedDamageLowerTheLethalThreshold(): void
    {
        $b = (new ScenarioBuilder())->creature(0, 'atk', 3, 3, false, 0, [CardInstance::TRAMPLE, CardInstance::DEATHTOUCH])
            ->creature(1, 'b1', 4, 4)->creature(1, 'b2', 5, 5);
        $vectors = $this->vectors($this->actionsFor($this->atDamage($b, ['b1', 'b2'])));
        $this->assertSame('b1=1,b2=1,player:1=1', $vectors[0], 'contact mortel : 1 dégât est létal');

        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 4, 4, false, 0, [CardInstance::TRAMPLE])
            ->creature(1, 'b1', 3, 3)->creature(1, 'b2', 3, 3), ['b1', 'b2']);
        $s->player(1)->battlefield[0]->damage = 2;
        $s->player(1)->battlefield[1]->damage = 2;
        $this->assertSame('b1=1,b2=1,player:1=2', $this->vectors($this->actionsFor($s))[0], 'dégâts déjà marqués pris en compte');
    }

    public function testNoChoiceWithoutAlternative(): void
    {
        // Un seul bloqueur, sans piétinement : tout lui est assigné (510.1c), aucune décision.
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 4, 4)->creature(1, 'b1', 2, 2), ['b1']);
        $this->assertSame([], $this->actionsFor($s));
    }

    public function testLargeAssignmentsStayBounded(): void
    {
        $b = (new ScenarioBuilder())->creature(0, 'atk', 20, 20, false, 0, [CardInstance::TRAMPLE])
            ->creature(1, 'b1', 2, 2)->creature(1, 'b2', 3, 3)->creature(1, 'b3', 4, 4);
        $vectors = $this->vectors($this->actionsFor($this->atDamage($b, ['b1', 'b2', 'b3'])));

        $this->assertLessThanOrEqual(40, count($vectors));
        $this->assertSame('b1=2,b2=3,b3=4,player:1=11', $vectors[0]);
        $this->assertContains('b1=0,b2=0,b3=20,player:1=0', $vectors);
        $this->assertContains('b1=2,b2=0,b3=18,player:1=0', $vectors);
        $this->assertSame(count($vectors), count(array_unique($vectors)));
    }

    public function testHumanAttackerChoosesAndTheChoiceIsApplied(): void
    {
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 3, 5)->creature(1, 'b1', 2, 2)->creature(1, 'b2', 3, 3), ['b1', 'b2']);
        $engine = new GameEngine(new AgentRegistry());

        $paused = $engine->advance($s);
        $this->assertNotNull($paused->pending);
        $this->assertSame([], $this->damaged($paused->events), 'aucun dégât avant la décision');
        $request = $paused->pending->toArray();
        $this->assertSame('damage', $request['declaring']);
        $this->assertSame('assign', $request['actions'][0]['type']);
        $this->assertSame([['target' => 'b1', 'amount' => 2], ['target' => 'b2', 'amount' => 1]], $request['actions'][0]['assignment']);

        // Reprise comme le serveur : état sérialisé, action retrouvée par son id parmi les actions recalculées.
        $s = GameState::fromArray($s->toArray());
        $choice = null;
        foreach ((new LegalActionGenerator())->for($s, 0) as $a) {
            if ($a->id() === 'assign:0:atk:b1=0,b2=3') {
                $choice = $a;
            }
        }
        $this->assertNotNull($choice);
        $resumed = $engine->advance($s, $choice);

        $this->assertSame(['b2' => 3, 'atk' => 5], $this->damaged($resumed->events));
        $this->assertEqualsCanonicalizing(['b2', 'atk'], $this->died($resumed->events));
        $this->assertSame([], $s->combat->assignments, 'choix oublié une fois les dégâts infligés');
        $this->assertNull($s->combat->declaring);
    }

    public function testAutoPassHumanStopsOnTheAssignmentToo(): void
    {
        $controllers = [0 => 'human:auto'] + self::HUMAN;
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 3, 5)->creature(1, 'b1', 2, 2)->creature(1, 'b2', 3, 3), ['b1', 'b2'], $controllers);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame('damage', $result->pending->toArray()['declaring']);
    }

    public function testAnIllegalAssignmentIsRejected(): void
    {
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 5, 5, false, 0, [CardInstance::TRAMPLE])
            ->creature(1, 'b1', 2, 2)->creature(1, 'b2', 1, 1), ['b1', 'b2']);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $this->expectException(IllegalActionException::class);
        $engine->advance($s, new AssignCombatDamage(0, 'atk', ['b1' => 0, 'b2' => 0, 'player:1' => 5]));
    }

    public function testFirstStrikeStepAsksOnlyForCreaturesThatStrikeThen(): void
    {
        $b = (new ScenarioBuilder())->creature(0, 'atk', 3, 5, false, 0, [CardInstance::FIRST_STRIKE])
            ->creature(1, 'b1', 2, 2)->creature(1, 'b2', 3, 3);
        $s = $this->atDamage($b, ['b1', 'b2'], self::HUMAN, Phase::FirstStrikeDamage);
        $this->assertCount(4, $this->actionsFor($s));

        $s = $this->atDamage($b, ['b1', 'b2'], self::HUMAN, Phase::CombatDamage);
        $this->assertSame([], $this->actionsFor($s), 'l\'initiative seule ne frappe pas à l\'étape normale');
    }

    public function testAiAttackerKeepsTheDefaultWithoutDecision(): void
    {
        $controllers = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];
        $s = $this->atDamage((new ScenarioBuilder())->creature(0, 'atk', 3, 5)->creature(1, 'b1', 2, 2)->creature(1, 'b2', 3, 3), ['b1', 'b2'], $controllers);
        $s->maxTurns = 1;
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $this->assertNull($result->pending);
        $this->assertSame(['b1' => 2, 'b2' => 1, 'atk' => 5], $this->damaged($result->events), 'létal au premier, le reste au suivant');
    }
}
