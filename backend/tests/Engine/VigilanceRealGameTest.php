<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use PHPUnit\Framework\TestCase;

/** Vigilance et initiative (première frappe) en PARTIE RÉELLE (4 IA) : graine 3, Serra Angel (deck J3) attaque sans s'engager ; graine 80, Black Knight (J3) ouvre l'étape de première frappe. */
final class VigilanceRealGameTest extends TestCase
{
    public function testSerraAngelAttacksWithoutTapping(): void
    {
        $s = GameFactory::create(3, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $serraIds = [];
        $attacks = 0;
        foreach ($result->events as $e) {
            if (($e->payload['card']['name'] ?? null) === 'Serra Angel' && isset($e->payload['card']['id'])) {
                $serraIds[$e->payload['card']['id']] = true;
            }
            if ($e->type === 'AttackerDeclared' && isset($serraIds[$e->payload['card']])) {
                $attacks++;
                $this->assertFalse($e->payload['tapped'], 'Serra Angel (vigilance) ne doit pas s\'engager en attaquant.');
            }
        }
        $this->assertGreaterThan(0, $attacks, 'Serra Angel doit attaquer au moins une fois sur la graine 3.');
    }

    public function testBlackKnightOpensFirstStrikeStep(): void
    {
        $s = GameFactory::create(80, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $knightIds = [];
        $armed = false;
        $steps = 0;
        foreach ($result->events as $e) {
            if (($e->payload['card']['name'] ?? null) === 'Black Knight' && isset($e->payload['card']['id'])) {
                $knightIds[$e->payload['card']['id']] = true;
            }
            if ($e->type === 'AttackerDeclared' && isset($knightIds[$e->payload['card']])) {
                $armed = true;
            }
            if ($armed && $e->type === 'PhaseChanged' && $e->payload['phase'] === 'first_strike_damage') {
                $steps++;
                $armed = false;
            }
        }
        $this->assertGreaterThan(0, $steps, 'Black Knight doit ouvrir une étape de première frappe sur la graine 80.');
    }
}
