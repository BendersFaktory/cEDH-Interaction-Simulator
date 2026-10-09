<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Game\GameState;

/**
 * Appelé à CHAQUE octroi de priorité, sans exception : l'auto-pass est une politique
 * d'un agent, jamais un raccourci du moteur. Un agent est sans état ; toute son
 * aléatoire passe par $state->rng.
 */
interface PlayerAgent
{
    /**
     * @param list<Action> $legal actions légales pour $playerId à cet instant (contient toujours PassPriority)
     */
    public function decide(GameState $state, int $playerId, array $legal): Action;
}
