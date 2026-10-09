<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\PassPriority;
use App\Engine\Game\GameState;

/** Passe toujours. La décision est quand même prise et journalisée (PriorityPassed). */
final class AutoPassAgent implements PlayerAgent
{
    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        return self::pass($legal, $playerId);
    }

    /** @param list<Action> $legal */
    public static function pass(array $legal, int $playerId): Action
    {
        foreach ($legal as $action) {
            if ($action instanceof PassPriority) {
                return $action;
            }
        }

        return new PassPriority($playerId);
    }
}
