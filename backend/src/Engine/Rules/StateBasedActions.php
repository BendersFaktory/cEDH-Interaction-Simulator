<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;

/** Vérifiées avant chaque octroi de priorité. Créatures létalement blessées incluses. TODO: légendes, jetons, etc. */
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
                $this->eliminate($s, $p->id, 'commander_damage');
            }
        }

        $this->destroyLethallyDamagedCreatures($s);

        $alive = $s->alivePlayerIds();
        if (!$s->over && count($alive) <= 1) {
            $s->over = true;
            $s->winner = $alive[0] ?? null;
            $s->emit('GameOver', ['winner' => $s->winner, 'reason' => 'last_player_standing']);
        }
    }

    /** Une créature marquée d'au moins autant de dégâts que son endurance meurt (un commandant retourne en zone de commandement). */
    private function destroyLethallyDamagedCreatures(GameState $s): void
    {
        foreach ($s->players as $p) {
            foreach ($p->battlefield as $card) {
                if ($card->isCreature() && $card->damage >= $card->currentToughness() && $card->damage > 0) {
                    $p->takeFromBattlefield($card->id);
                    $card->damage = 0;
                    $card->tapped = false;
                    $destination = $s->sendToGraveyard($p->id, $card);
                    $s->emit('CreatureDied', ['player' => $p->id, 'card' => $card->toPublic(), 'destination' => $destination]);
                    $s->queueTriggers($p->id, $card, TriggeredAbility::DIES);
                }
            }
        }
    }

    public function eliminate(GameState $s, int $playerId, string $reason): void
    {
        $s->player($playerId)->lost = true;
        $s->emit('PlayerLost', ['player' => $playerId, 'reason' => $reason]);
    }
}
