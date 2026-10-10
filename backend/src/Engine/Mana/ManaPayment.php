<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Game\CardInstance;
use App\Engine\Game\PlayerState;

/**
 * Solveur de paiement (auto-paiement) : choisit quels terrains engager. Sans aléa, sans effet de bord.
 *
 * Modèle actuel : chaque terrain produit une seule couleur, donc c'est un problème de comptage.
 * Les symboles colorés (et {C}) exigent un terrain de cette couleur, chaque hybride {A/B} un terrain
 * de A ou de B (les 2^h affectations sont essayées dans un ordre stable, la première faisable gagne),
 * puis le générique et les {X} sont payés par n'importe quel terrain restant.
 *
 * Point d'extension (terrains multicolores) : `takeLand()` et `pickGeneric()` sont les seuls endroits qui
 * lisent `producedColors()`. Aujourd'hui un terrain multicolore est affecté glouton à la première couleur
 * encore nécessaire, sans affectation optimale (flot/couplage) ; un vrai couplage se brancherait ici.
 */
final class ManaPayment
{
    /**
     * @return list<array{land: string, color: Color}>|null terrains à engager (id) et mana qu'ils produisent ; null = impayable
     */
    public static function plan(PlayerState $p, ManaCost $cost, int $x = 0): ?array
    {
        $lands = [];
        foreach ($p->battlefield as $card) {
            if ($card->isLand() && !$card->tapped) {
                $lands[] = $card;
            }
        }

        $generic = $cost->generic + $x * $cost->x;
        if ($generic + array_sum($cost->colored) + count($cost->hybrid) > count($lands)) {
            return null;
        }

        $h = count($cost->hybrid);
        for ($mask = 0; $mask < (1 << $h); $mask++) {
            $required = $cost->colored;
            foreach ($cost->hybrid as $i => $pair) {
                $symbol = $pair[($mask >> $i) & 1]->symbol();
                $required[$symbol] = ($required[$symbol] ?? 0) + 1;
            }
            $plan = self::tryPay($lands, $required, $generic);
            if ($plan !== null) {
                return $plan;
            }
        }

        return null;
    }

    /** Plus grand X payable avec les terrains non engagés (0 si le sort n'a pas de {X}). */
    public static function maxX(PlayerState $p, ManaCost $cost): int
    {
        if (!$cost->hasX()) {
            return 0;
        }
        $untapped = 0;
        foreach ($p->battlefield as $card) {
            if ($card->isLand() && !$card->tapped) {
                $untapped++;
            }
        }
        // Le plan n'est faisable que de façon monotone en X : on monte tant que ça passe.
        $best = 0;
        for ($x = 1; $x * $cost->x <= $untapped; $x++) {
            if (self::plan($p, $cost, $x) === null) {
                break;
            }
            $best = $x;
        }

        return $best;
    }

    /**
     * @param list<CardInstance> $lands    terrains non engagés, ordre du champ de bataille
     * @param array<string, int> $required symbole => nombre de symboles colorés à payer
     * @return list<array{land: string, color: Color}>|null
     */
    private static function tryPay(array $lands, array $required, int $generic): ?array
    {
        $plan = [];
        $free = $lands; // clés = positions d'origine, ordre conservé

        foreach (Color::cases() as $color) {
            for ($n = $required[$color->symbol()] ?? 0; $n > 0; $n--) {
                $i = self::takeLand($free, $color);
                if ($i === null) {
                    return null;
                }
                $plan[] = ['land' => $free[$i]->id, 'color' => $color];
                unset($free[$i]);
            }
        }

        for (; $generic > 0; $generic--) {
            $pick = self::pickGeneric($free);
            if ($pick === null) {
                return null;
            }
            [$i, $color] = $pick;
            $plan[] = ['land' => $free[$i]->id, 'color' => $color];
            unset($free[$i]);
        }

        return $plan;
    }

    /**
     * Premier terrain libre pouvant produire `$color` (en préférant ceux qui ont le moins de couleurs, pour
     * garder les multicolores disponibles).
     *
     * @param array<int, CardInstance> $free
     */
    private static function takeLand(array $free, Color $color): ?int
    {
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($free as $i => $land) {
            $colors = $land->producedColors();
            foreach ($colors as $c) {
                if ($c === $color && count($colors) < $bestCount) {
                    $best = $i;
                    $bestCount = count($colors);
                }
            }
        }

        return $best;
    }

    /**
     * Terrain pour un coût générique : on prend dans la couleur la plus abondante parmi les terrains libres
     * (égalité : ordre WUBRGC) pour laisser les couleurs rares non engagées. Premier terrain dans l'ordre
     * du champ de bataille ; sa première couleur est celle produite.
     *
     * @param array<int, CardInstance> $free
     * @return array{0: int, 1: Color}|null
     */
    private static function pickGeneric(array $free): ?array
    {
        $count = [];
        foreach ($free as $land) {
            $c = $land->producedColors()[0] ?? null;
            if ($c !== null) {
                $count[$c->symbol()] = ($count[$c->symbol()] ?? 0) + 1;
            }
        }
        if ($count === []) {
            return null;
        }

        $bestColor = null;
        $bestCount = 0;
        foreach (Color::cases() as $color) {
            if (($count[$color->symbol()] ?? 0) > $bestCount) {
                $bestColor = $color;
                $bestCount = $count[$color->symbol()];
            }
        }
        foreach ($free as $i => $land) {
            if (($land->producedColors()[0] ?? null) === $bestColor) {
                return [$i, $bestColor];
            }
        }

        return null;
    }
}
