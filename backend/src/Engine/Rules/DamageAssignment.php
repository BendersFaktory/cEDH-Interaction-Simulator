<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * Répartitions légales des dégâts de combat d'un attaquant bloqué (règles en vigueur depuis Foundations ; texte des
 * Comprehensive Rules du 25/09/2026).
 *
 * - CR 510.1c : « A blocked creature assigns its combat damage to the creatures blocking it. If no creatures are
 *   currently blocking it (if, for example, they were destroyed or removed from combat), it assigns no combat damage.
 *   If exactly one creature is blocking it, it assigns all its combat damage to that creature. If two or more creatures
 *   are blocking it, it assigns its combat damage to those creatures divided as its controller chooses among them. »
 *   Plus d'ordre d'assignation des dégâts : la répartition entre bloqueurs est libre.
 * - CR 702.19b (piétinement) : « Once all those blocking creatures are assigned lethal damage, any excess damage is
 *   assigned as its controller chooses among those blocking creatures and the player, planeswalker, or battle the
 *   creature is attacking. When checking for assigned lethal damage, take into account damage already marked on the
 *   creature and damage from other creatures that's being assigned during the same combat damage step, but not any
 *   abilities or effects that might change the amount of damage that's actually dealt. The attacking creature's
 *   controller need not assign lethal damage to all those blocking creatures but in that case can't assign any damage
 *   to the player or planeswalker it's attacking. »
 * - CR 702.2c (contact mortel) : « Any nonzero amount of combat damage assigned to a creature by a source with
 *   deathtouch is considered to be lethal damage for the purposes of determining if excess damage is being dealt. »
 * - CR 704.5g : létal = endurance moins les dégâts déjà marqués.
 *
 * Ici un bloqueur ne bloque qu'un attaquant : aucun autre attaquant ne lui assigne de dégâts à la même étape, seul le
 * marqué compte. Un vecteur de répartition est un `array<string, int>` : id de bloqueur (ordre de déclaration) puis
 * `player:N` (piétinement, joueur en vie) => dégâts ; il couvre toute la force de l'attaquant, zéros compris.
 *
 * Énumération finie (les actions légales sont une liste recalculée par le serveur, règle d'or 10) : toutes les
 * répartitions tant qu'elles sont peu nombreuses (MAX_FULL), sinon un jeu réduit qui couvre toutes les issues
 * distinctes dans ce moteur (aucun effet de prévention ni de « blessé par » : seuls comptent les bloqueurs qui
 * reçoivent un létal et ce qui va au joueur ; le lien de vie rend toujours la force entière) : pour chaque
 * sous-ensemble de bloqueurs tués, le létal à chacun et le reste en bloc sur un seul destinataire.
 */
final class DamageAssignment
{
    /** Au-delà, jeu réduit (ex. 5 de force sur 3 bloqueurs = 21 compositions, toutes listées). */
    private const MAX_FULL = 64;

    /** Le contrôleur de l'attaquant choisit-il lui-même (siège humain) ? Les IA gardent la répartition par défaut. */
    public static function chooses(GameState $s): bool
    {
        return str_starts_with($s->controllers[$s->activePlayer] ?? '', 'human');
    }

    /** Dégâts létaux pour $blocker venant de $attacker (marqué déduit ; 1 suffit avec le contact mortel). */
    public static function lethal(CardInstance $attacker, CardInstance $blocker): int
    {
        $lethal = max(0, $blocker->currentToughness() - $blocker->damage);

        return $lethal > 0 && $attacker->hasKeyword(CardInstance::DEATHTOUCH) ? 1 : $lethal;
    }

    /**
     * Répartitions légales de l'attaquant à cette étape, la répartition par défaut en tête (létal dans l'ordre de
     * déclaration, le reste au joueur avec piétinement, sinon au dernier bloqueur). Vide si la question ne se pose pas
     * (non bloqué, plus aucun bloqueur, force nulle, ne frappe pas à cette étape).
     *
     * @return list<array<string, int>>
     */
    public static function options(GameState $s, string $attackerId, bool $firstStrike): array
    {
        $attacker = self::find($s, $s->activePlayer, $attackerId);
        $defender = $s->combat->attackers[$attackerId] ?? null;
        $ids = $s->combat->blockers[$attackerId] ?? [];
        if ($attacker === null || $defender === null || $ids === [] || $attacker->currentPower() <= 0
            || !CombatResolver::strikes($attacker, $firstStrike)) {
            return [];
        }
        $blockers = [];
        foreach ($ids as $id) {
            $b = self::find($s, $defender, $id);
            if ($b !== null) {
                $blockers[] = $b;
            }
        }
        if ($blockers === []) {
            return [];
        }

        $power = $attacker->currentPower();
        $playerKey = $attacker->hasKeyword(CardInstance::TRAMPLE) && !$s->player($defender)->lost ? 'player:' . $defender : null;
        $keys = array_map(static fn (CardInstance $b) => $b->id, $blockers);
        $lethal = array_map(static fn (CardInstance $b) => self::lethal($attacker, $b), $blockers);
        $zero = array_fill_keys($playerKey !== null ? [...$keys, $playerKey] : $keys, 0);

        // Par défaut : létal à chacun dans l'ordre, le reste au joueur (piétinement) ou au dernier bloqueur.
        $default = $zero;
        $remaining = $power;
        foreach ($keys as $i => $key) {
            $default[$key] = min($remaining, $lethal[$i]);
            $remaining -= $default[$key];
        }
        $default[$playerKey ?? $keys[array_key_last($keys)]] += $remaining;

        $n = count($zero);
        $all = self::binomial($power + $n - 1, $n - 1) <= self::MAX_FULL
            ? self::full($zero, $power, $keys, $lethal, $playerKey)
            : self::reduced($zero, $power, $keys, $lethal, $playerKey);

        $options = [self::key($default) => $default];
        foreach ($all as $vector) {
            $options[self::key($vector)] ??= $vector;
        }

        return array_values($options);
    }

    /**
     * Clé canonique d'un vecteur (sert aussi dans l'id d'action) : `b1=2,b2=1,player:1=0`.
     *
     * @param array<string, int> $vector
     */
    public static function key(array $vector): string
    {
        $parts = [];
        foreach ($vector as $target => $amount) {
            $parts[] = $target . '=' . $amount;
        }

        return implode(',', $parts);
    }

    /**
     * Toutes les compositions de $power entre les destinataires, filtrées par la contrainte du piétinement.
     *
     * @param array<string, int> $zero
     * @param list<string>       $keys
     * @param list<int>          $lethal
     *
     * @return list<array<string, int>>
     */
    private static function full(array $zero, int $power, array $keys, array $lethal, ?string $playerKey): array
    {
        $targets = array_keys($zero);
        $last = count($targets) - 1;
        $out = [];
        $walk = static function (int $i, int $remaining, array $vector) use (&$walk, &$out, $targets, $last, $keys, $lethal, $playerKey): void {
            if ($i === $last) {
                $vector[$targets[$i]] = $remaining;
                if ($playerKey === null || $vector[$playerKey] === 0 || self::allLethal($vector, $keys, $lethal)) {
                    $out[] = $vector;
                }

                return;
            }
            for ($a = $remaining; $a >= 0; $a--) {
                $vector[$targets[$i]] = $a;
                $walk($i + 1, $remaining - $a, $vector);
            }
        };
        $walk(0, $power, $zero);

        return $out;
    }

    /**
     * Pour chaque sous-ensemble de bloqueurs tués : le létal à chacun, le reste en bloc sur un seul destinataire.
     *
     * @param array<string, int> $zero
     * @param list<string>       $keys
     * @param list<int>          $lethal
     *
     * @return list<array<string, int>>
     */
    private static function reduced(array $zero, int $power, array $keys, array $lethal, ?string $playerKey): array
    {
        $out = [];
        $k = min(count($keys), 10); // garde-fou : au-delà de 10 bloqueurs, les suivants ne sont pas « tués » en bloc
        for ($mask = (1 << $k) - 1; $mask >= 0; $mask--) {
            $base = $zero;
            $sum = 0;
            for ($i = 0; $i < $k; $i++) {
                if (($mask & (1 << $i)) !== 0) {
                    $base[$keys[$i]] = $lethal[$i];
                    $sum += $lethal[$i];
                }
            }
            if ($sum > $power) {
                continue;
            }
            $recipients = $keys;
            if ($playerKey !== null && self::allLethal($base, $keys, $lethal)) {
                $recipients[] = $playerKey;
            }
            foreach ($recipients as $r) {
                $vector = $base;
                $vector[$r] += $power - $sum;
                $out[] = $vector;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $vector
     * @param list<string>       $keys
     * @param list<int>          $lethal
     */
    private static function allLethal(array $vector, array $keys, array $lethal): bool
    {
        foreach ($keys as $i => $key) {
            if ($vector[$key] < $lethal[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function binomial(int $n, int $k): int
    {
        $r = 1;
        for ($i = 1; $i <= $k; $i++) {
            $r = intdiv($r * ($n - $k + $i), $i);
            if ($r > self::MAX_FULL * 1000) {
                return PHP_INT_MAX;
            }
        }

        return $r;
    }

    private static function find(GameState $s, int $playerId, string $cardId): ?CardInstance
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }
}
