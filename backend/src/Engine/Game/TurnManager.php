<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Rules\CombatManager;
use App\Engine\Rules\StateBasedActions;

/** Enchaîne les étapes et les tours. */
final class TurnManager
{
    public function __construct(
        private readonly StateBasedActions $sba,
        private readonly CombatManager $combat,
    ) {
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

        $this->combat->beginStep($s);

        if ($s->phase === Phase::Cleanup) {
            foreach ($s->players as $p) {
                foreach ($p->battlefield as $card) {
                    $card->damage = 0;
                }
            }
        }

        // CR 103.8 : seul le premier joueur d'une partie à 2 saute sa pioche ; en multijoueur, tout le monde pioche dès le tour 1.
        if ($s->phase === Phase::Draw &&!$s->drawCard($s->activePlayer)) {
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
        // Règle 500.4 : le mana non dépensé disparaît à la fin de chaque étape.
        foreach ($s->players as $p) {
            if (!$p->pool->isEmpty()) {
                $s->emit('ManaPoolEmptied', ['player' => $p->id, 'lost' => $p->pool->toArray(), 'pool' => []]);
                $p->pool->clear();
            }
        }

        $next = $s->phase->next();
        while ($next !== null && $this->combat->skips($next, $s)) {
            $next = $next->next();
        }
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
