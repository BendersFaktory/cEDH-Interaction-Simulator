<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;

/** Vérifiées avant chaque octroi de priorité. TODO: créatures létales, légendes, jetons, etc. */
final class StateBasedActions
{
    public function check(GameState $s): void
    {
        foreach ($s->players as $p) {
            if ($p->lost) {
                continue;
            }
            if ($p->life <= 0) {
                $this->eliminate($s, $p->id, 'life');
            } elseif ($p->commanderDamage !== [] && max($p->commanderDamage) >= PlayerState::COMMANDER_DAMAGE_LIMIT) {
                // Les dégâts de commandant ne sont alimentés que par le combat (pas encore implémenté).
                $this->eliminate($s, $p->id, 'commander_damage');
            }
        }

        $alive = $s->alivePlayerIds();
        if (!$s->over && count($alive) <= 1) {
            $s->over = true;
            $s->winner = $alive[0] ?? null;
            $s->emit('GameOver', ['winner' => $s->winner, 'reason' => 'last_player_standing']);
        }
    }

    public function eliminate(GameState $s, int $playerId, string $reason): void
    {
        $s->player($playerId)->lost = true;
        $s->emit('PlayerLost', ['player' => $playerId, 'reason' => $reason]);
    }
}
