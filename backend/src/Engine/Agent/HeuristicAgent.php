<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\PlayLand;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * IA de départ, volontairement simple :
 *  1. contre le sort adverse au dessus de la pile s'il le peut ;
 *  2. joue un terrain ;
 *  3. à son tour, en phase principale, pile vide : lance un sort au hasard (jamais sur soi-même) ;
 *  4. sinon passe.
 * Combat : attaque avec toutes ses créatures sur l'adversaire le plus faible ; bloque quand le bloqueur survit
 * ou tue l'attaquant (un seul bloqueur par attaquant), et chump-bloque seulement si l'attaque est létale.
 * TODO: évaluateur de menace (ne pas contrer n'importe quoi), gestion du mana, etc.
 */
final class HeuristicAgent implements PlayerAgent
{
    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if ($state->combat->declaring === 'attackers') {
            return $this->declareAttackers($state, $legal, $playerId);
        }
        if ($state->combat->declaring === 'blockers') {
            return $this->declareBlockers($state, $legal, $playerId);
        }

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

        if ($top === null && $state->activePlayer === $playerId && $state->phase->isMain()) {
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

    /** @param list<Action> $legal */
    private function declareAttackers(GameState $state, array $legal, int $playerId): Action
    {
        $attacks = array_values(array_filter($legal, static fn (Action $a) => $a instanceof DeclareAttacker));
        if ($attacks === []) {
            return $this->finish($legal, $playerId);
        }
        $target = null;
        foreach ($attacks as $a) {
            $life = $state->player($a->defender)->life;
            if ($target === null || $life < $state->player($target)->life
                || ($life === $state->player($target)->life && $a->defender < $target)) {
                $target = $a->defender;
            }
        }
        foreach ($attacks as $a) {
            if ($a->defender === $target) {
                return $a;
            }
        }

        return $this->finish($legal, $playerId);
    }

    /** @param list<Action> $legal */
    private function declareBlockers(GameState $state, array $legal, int $playerId): Action
    {
        $incoming = 0;
        foreach ($state->combat->attackers as $attackerId => $defender) {
            if ($defender === $playerId && ($attacker = $this->battlefieldCard($state, $state->activePlayer, $attackerId)) !== null) {
                $incoming += $attacker->power;
            }
        }
        $lethal = $incoming >= $state->player($playerId)->life;

        $chump = null;
        foreach ($legal as $a) {
            if (!$a instanceof DeclareBlocker || isset($state->combat->blockers[$a->attackerId])) {
                continue;
            }
            $attacker = $this->battlefieldCard($state, $state->activePlayer, $a->attackerId);
            $blocker = $this->battlefieldCard($state, $playerId, $a->cardId);
            if ($attacker === null || $blocker === null) {
                continue;
            }
            if ($blocker->toughness - $blocker->damage > $attacker->power || $blocker->power >= $attacker->toughness - $attacker->damage) {
                return $a;
            }
            if ($lethal && !$blocker->commander && ($chump === null || $attacker->power > $chump[1])) {
                $chump = [$a, $attacker->power];
            }
        }
        if ($chump !== null) {
            return $chump[0];
        }

        return $this->finish($legal, $playerId);
    }

    /** @param list<Action> $legal */
    private function finish(array $legal, int $playerId): Action
    {
        foreach ($legal as $a) {
            if ($a instanceof FinishDeclaration) {
                return $a;
            }
        }

        return new FinishDeclaration($playerId);
    }

    private function battlefieldCard(GameState $state, int $owner, string $cardId): ?CardInstance
    {
        foreach ($state->player($owner)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function isCounter(GameState $state, CastSpell $action): bool
    {
        return $state->player($action->player())->findCastable($action->cardId)?->effect === 'counter';
    }
}
