<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PlayLand;
use App\Engine\Game\GameState;

/**
 * IA de départ, volontairement simple :
 *  1. contre le sort adverse au dessus de la pile s'il le peut ;
 *  2. joue un terrain ;
 *  3. à son tour, pile vide : lance un sort au hasard (jamais sur soi-même) ;
 *  4. sinon passe.
 * TODO: évaluateur de menace (ne pas contrer n'importe quoi), gestion du mana, etc.
 */
final class HeuristicAgent implements PlayerAgent
{
    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        $top = $state->topOfStack();

        if ($top !== null && $top->controller !== $playerId) {
            foreach ($legal as $action) {
                if ($action instanceof CastSpell && $this->isCounter($state, $action)
                    && $action->targets === ['stack:' . $top->id]) {
                    return $action;
                }
            }
        }

        foreach ($legal as $action) {
            if ($action instanceof PlayLand) {
                return $action;
            }
        }

        if ($top === null && $state->activePlayer === $playerId) {
            $casts = [];
            foreach ($legal as $action) {
                if ($action instanceof CastSpell
                    && !$this->isCounter($state, $action)
                    && !in_array('player:' . $playerId, $action->targets, true)) {
                    $casts[] = $action;
                }
            }
            if ($casts !== []) {
                return $casts[$state->rng->int(0, count($casts) - 1)];
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }

    private function isCounter(GameState $state, CastSpell $action): bool
    {
        return $state->player($action->player())->findCastable($action->cardId)?->effect === 'counter';
    }
}
