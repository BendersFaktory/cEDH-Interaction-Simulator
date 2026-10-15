<?php

declare(strict_types=1);

namespace App\Engine\Agent;

/**
 * Associe une clé stockée dans le GameState ('human', 'ai:heuristic'...) à une instance d'agent.
 * Forker = changer une clé dans GameState::$controllers.
 */
final class AgentRegistry
{
    /** @var array<string, PlayerAgent> */
    private array $agents;

    /** @param array<string, PlayerAgent> $extra agents supplémentaires (tests : agent scripté) */
    public function __construct(array $extra = [])
    {
        $this->agents = $extra + [
            'ai:pass' => new AutoPassAgent(),
            'ai:heuristic' => new HeuristicAgent(),
            'ai:aggro' => HeuristicAgent::aggro(),
            'ai:control' => HeuristicAgent::control(),
            'human' => new HumanAgent(false),
            'human:auto' => new HumanAgent(true),
        ];
    }

    public function get(string $key): PlayerAgent
    {
        return $this->agents[$key] ?? throw new \InvalidArgumentException("Agent inconnu : $key");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->agents);
    }
}
