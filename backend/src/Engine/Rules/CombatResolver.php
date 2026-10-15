<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * Dégâts de combat, simultanés : tout est calculé sur l'état d'avant les dégâts, puis appliqué.
 * Sans piétinement : un attaquant bloqué ne blesse pas le joueur. Les dégâts d'un attaquant aux bloqueurs
 * suivent par défaut l'ordre de déclaration (létal au premier, le reste au suivant, le surplus au dernier) ; un
 * contrôleur humain a pu choisir une autre répartition (`CombatState::$assignments`, voir DamageAssignment).
 * Les créatures létalement blessées meurent ensuite via les actions basées sur l'état.
 */
final class CombatResolver
{
    public function resolve(GameState $s, bool $firstStrike = false): void
    {
        $attackerOwner = $s->activePlayer;
        /** @var array<string, int> $toCreatures id de créature => dégâts à marquer */
        $toCreatures = [];
        /** @var array<string, true> $deathtouched créatures touchées par une source à contact mortel */
        $deathtouched = [];
        /** @var list<array{CardInstance, int, int}> $toPlayers [attaquant, défenseur, dégâts] */
        $toPlayers = [];
        /** @var array<string, array{CardInstance, int, int}> $lifelink id de source => [source, contrôleur, total] */
        $lifelink = [];

        $deal = function (CardInstance $source, int $controller, ?CardInstance $target, int $amount) use (&$toCreatures, &$deathtouched, &$lifelink): void {
            if ($amount <= 0) {
                return;
            }
            if ($target !== null) {
                $toCreatures[$target->id] = ($toCreatures[$target->id] ?? 0) + $amount;
                if ($source->hasKeyword(CardInstance::DEATHTOUCH)) {
                    $deathtouched[$target->id] = true;
                }
            }
            if ($source->hasKeyword(CardInstance::LIFELINK)) {
                $lifelink[$source->id] = [$source, $controller, ($lifelink[$source->id][2] ?? 0) + $amount];
            }
        };

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
            $power = $attacker->currentPower();
            $trample = $attacker->hasKeyword(CardInstance::TRAMPLE);
            $playerAlive = !$s->player($defender)->lost;

            $chosen = $s->combat->assignments[$attackerId] ?? null;
            if ($chosen !== null && $power > 0 && self::strikes($attacker, $firstStrike)) {
                $byId = [];
                foreach ($blockers as $blocker) {
                    $byId[$blocker->id] = $blocker;
                }
                foreach ($chosen as $target => $amount) {
                    $target = (string) $target;
                    if ($target === 'player:' . $defender) {
                        if ($playerAlive && $amount > 0) {
                            $toPlayers[] = [$attacker, $defender, $amount];
                            $deal($attacker, $attackerOwner, null, $amount);
                        }
                    } elseif (isset($byId[$target])) {
                        $deal($attacker, $attackerOwner, $byId[$target], $amount);
                    }
                }
            } elseif ($power > 0 && self::strikes($attacker, $firstStrike)) {
                if (($s->combat->blockers[$attackerId] ?? []) === [] || ($blockers === [] && $trample)) {
                    if ($playerAlive) {
                        $toPlayers[] = [$attacker, $defender, $power];
                        $deal($attacker, $attackerOwner, null, $power);
                    }
                } else {
                    $remaining = $power;
                    foreach ($blockers as $i => $blocker) {
                        $isLast = $i === array_key_last($blockers);
                        $lethal = max(0, $blocker->currentToughness() - $blocker->damage);
                        if ($lethal > 0 && $attacker->hasKeyword(CardInstance::DEATHTOUCH)) {
                            $lethal = 1;
                        }
                        $assign = ($isLast && !$trample) ? $remaining : min($remaining, $lethal);
                        $remaining -= $assign;
                        $deal($attacker, $attackerOwner, $blocker, $assign);
                    }
                    if ($trample && $remaining > 0 && $playerAlive) {
                        $toPlayers[] = [$attacker, $defender, $remaining];
                        $deal($attacker, $attackerOwner, null, $remaining);
                    }
                }
            }

            foreach ($blockers as $blocker) {
                if (self::strikes($blocker, $firstStrike)) {
                    $deal($blocker, $defender, $attacker, $blocker->currentPower());
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
            if (isset($deathtouched[$cardId])) {
                $card->deathtouchDamage = true;
            }
            $s->emit('CreatureDamaged', ['card' => $cardId, 'amount' => $amount, 'damage' => $card->damage]);
        }

        foreach ($lifelink as [$source, $controller, $total]) {
            $player = $s->player($controller);
            $player->life += $total;
            $s->emit('LifeGained', ['player' => $controller, 'amount' => $total, 'life' => $player->life, 'source' => $source->name]);
        }
    }

    /** Frappe-t-elle à cette étape ? (initiative : étape dédiée ; sinon celles sans initiative + double initiative.) */
    public static function strikes(CardInstance $card, bool $firstStrike): bool
    {
        $double = $card->hasKeyword(CardInstance::DOUBLE_STRIKE);
        if ($firstStrike) {
            return $double || $card->hasKeyword(CardInstance::FIRST_STRIKE);
        }

        return $double || !$card->hasKeyword(CardInstance::FIRST_STRIKE);
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
