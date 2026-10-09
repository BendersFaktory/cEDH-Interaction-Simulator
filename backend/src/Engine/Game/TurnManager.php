<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Rules\StateBasedActions;

/** Enchaîne les étapes et les tours. TODO: untap/upkeep/combat/seconde main/cleanup. */
final class TurnManager
{
    public function __construct(private readonly StateBasedActions $sba)
    {
    }

    /** Effets d'entrée dans l'étape courante, puis ouverture de la première ronde de priorité. */
    public function beginStep(GameState $s): void
    {
        $s->emit('PhaseChanged', ['turn' => $s->turn, 'phase' => $s->phase->value, 'activePlayer' => $s->activePlayer]);

        if ($s->phase === Phase::Draw && !$s->drawCard($s->activePlayer)) {
            $this->sba->eliminate($s, $s->activePlayer, 'decked');
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

        // Nouveau tour : joueur suivant (dans le sens du tour), il dégage ses permanents.
        $s->turn++;
        if ($s->turn > $s->maxTurns) {
            $s->over = true;
            $s->winner = null;
            $s->emit('GameOver', ['winner' => null, 'reason' => 'turn_limit']);

            return;
        }
        $s->activePlayer = $s->nextAlive($s->activePlayer);
        $untapped = $s->player($s->activePlayer)->untapAll();
        if ($untapped !== []) {
            $s->emit('PermanentsUntapped', ['player' => $s->activePlayer, 'cards' => $untapped]);
        }
        $s->landsPlayed = 0;
        $s->phase = Phase::Draw;
        $s->stepStarted = false;
    }
}
