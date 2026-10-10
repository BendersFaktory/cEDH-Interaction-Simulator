<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * Dégâts de combat, simultanés : tout est calculé sur l'état d'avant les dégâts, puis appliqué.
 * Sans piétinement : un attaquant bloqué ne blesse pas le joueur. Les dégâts d'un attaquant aux bloqueurs
 * suivent l'ordre de déclaration (létal au premier, le reste au suivant, le surplus au dernier).
 * Les créatures létalement blessées meurent ensuite via les actions basées sur l'état.
 */
final class CombatResolver
{
    public function resolve(GameState $s): void
    {
        $attackerOwner = $s->activePlayer;
        /** @var array<string, int> $toCreatures id de créature => dégâts à marquer */
        $toCreatures = [];
        /** @var list<array{CardInstance, int, int}> $toPlayers [attaquant, défenseur, dégâts] */
        $toPlayers = [];

        foreach ($s->combat->attackers as $attackerId => $defender) {
            $attacker = $this->find($s, $attackerOwner, $attackerId);
            if ($attacker === null) {
                continue;
            }
            $blockers = [];
            foreach ($s->combat->blockers[$attackerId] ?? [] as $blockerId) {
                $blocker = $this->find($s, $defender, $blockerId);
                if ($blocker !== null) {
                    $blockers[] = $blocker;
                }
            }

            if (($s->combat->blockers[$attackerId] ?? []) === []) {
                if ($attacker->power > 0 && !$s->player($defender)->lost) {
                    $toPlayers[] = [$attacker, $defender, $attacker->power];
                }
                continue;
            }

            $remaining = $attacker->power;
            foreach ($blockers as $i => $blocker) {
                $isLast = $i === array_key_last($blockers);
                $assign = $isLast ? $remaining : min($remaining, max(0, $blocker->toughness - $blocker->damage));
                $remaining -= $assign;
                if ($assign > 0) {
                    $toCreatures[$blocker->id] = ($toCreatures[$blocker->id] ?? 0) + $assign;
                }
                if ($blocker->power > 0) {
                    $toCreatures[$attacker->id] = ($toCreatures[$attacker->id] ?? 0) + $blocker->power;
                }
            }
        }

        foreach ($toPlayers as [$attacker, $defender, $amount]) {
            $victim = $s->player($defender);
            $victim->life -= $amount;
            $payload = ['source' => $attacker->name, 'target' => 'player:' . $defender, 'amount' => $amount, 'life' => $victim->life, 'combat' => true];
            if ($attacker->commander) {
                $key = $attackerOwner . ':' . $attacker->id;
                $victim->commanderDamage[$key] = ($victim->commanderDamage[$key] ?? 0) + $amount;
                $payload['commanderDamage'] = ['key' => $key, 'total' => $victim->commanderDamage[$key]];
            }
            $s->emit('DamageDealt', $payload);
        }

        foreach ($toCreatures as $cardId => $amount) {
            $card = $this->findAnywhere($s, $cardId);
            if ($card === null) {
                continue;
            }
            $card->damage += $amount;
            $s->emit('CreatureDamaged', ['card' => $cardId, 'amount' => $amount, 'damage' => $card->damage]);
        }
    }

    private function find(GameState $s, int $playerId, string $cardId): ?CardInstance
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function findAnywhere(GameState $s, string $cardId): ?CardInstance
    {
        foreach ($s->players as $p) {
            $card = $this->find($s, $p->id, $cardId);
            if ($card !== null) {
                return $card;
            }
        }

        return null;
    }
}
