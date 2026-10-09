<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Rules\StateBasedActions;

/** Enchaîne les étapes et les tours. TODO: défausse à 7 au cleanup, étapes de combat détaillées. */
final class TurnManager
{
    public function __construct(private readonly StateBasedActions $sba)
    {
    }

    /** Effets d'entrée dans l'étape courante, puis ouverture de la première ronde de priorité. */
    public function beginStep(GameState $s): void
    {
        $s->emit('PhaseChanged', ['turn' => $s->turn, 'phase' => $s->phase->value, 'activePlayer' => $s->activePlayer]);

        if ($s->phase === Phase::Untap) {
            $untapped = $s->player($s->activePlayer)->untapAll();
            if ($untapped !== []) {
                $s->emit('PermanentsUntapped', ['player' => $s->activePlayer, 'cards' => $untapped]);
            }
        }

        if ($s->phase === Phase::Draw && !$s->drawCard($s->activePlayer)) {
            $this->sba->eliminate($s, $s->activePlayer, 'decked');
        }

        if (!$s->phase->hasPriority()) {
            // Untap / Cleanup : aucune ronde de priorité.
            $s->stepStarted = true;

            return;
        }

        $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
        $s->priority = new PriorityState($first);
        $s->stepStarted = true;
    }

    public function endStep(GameState $s): void
    {
        $next = $s->phase->next();
        if ($next !== null) {
            $s->phase = $next;
            $s->stepStarted = false;

            return;
        }

        // Nouveau tour : joueur suivant (dans le sens du tour) ; il dégage ses permanents à l'étape Untap.
        $s->turn++;
        if ($s->turn > $s->maxTurns) {
            $s->over = true;
            $s->winner = null;
            $s->emit('GameOver', ['winner' => null, 'reason' => 'turn_limit']);

            return;
        }
        $s->activePlayer = $s->nextAlive($s->activePlayer);
        $s->landsPlayed = 0;
        $s->phase = Phase::Untap;
        $s->stepStarted = false;
    }
}
