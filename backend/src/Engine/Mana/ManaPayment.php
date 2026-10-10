<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Game\PlayerState;

/**
 * Solveur de paiement (auto-paiement) : recherche exacte, déterministe, sans effet de bord.
 *
 * Sources : la réserve (`$p->pool`) et, pour chaque permanent non engagé, ses capacités de mana `{T}`. Un permanent
 * ne s'engage qu'une fois : il choisit UNE capacité et UNE alternative (`choices`).
 *
 * Étapes :
 *  1. Pour chacune des 2^h affectations des hybrides (ordre stable), le coût devient « colorés + générique ».
 *  2. La réserve paie d'abord (colorés, puis générique : incolore d'abord, puis WUBRG) ; reste un besoin résiduel.
 *  3. Backtracking sur les permanents (ordre du champ de bataille) pour couvrir ce besoin résiduel.
 *
 * Critère (lexicographique, plus petit = mieux) : plus de réserve dépensée, moins de mana gaspillé (produit non dépensé),
 * moins d'engagements, moins de sources à plusieurs alternatives engagées, puis ordre du champ de bataille / WUBRGC / masque
 * d'hybrides (le premier trouvé gagne). Élagage par bornes ; au plus NODE_LIMIT nœuds explorés par appel, au-delà on renvoie
 * le meilleur plan trouvé (ou null).
 */
final class ManaPayment
{
    private const NODE_LIMIT = 50000;

    public static function plan(PlayerState $p, ManaCost $cost, int $x = 0): ?ManaPlan
    {
        $sources = self::sources($p);
        $pool = $p->pool->toArray();
        $state = ['nodes' => 0];

        return self::solve($sources, $pool, $cost, $x, $state);
    }

    /** Plus grand X payable avec la réserve et les sources non engagées (0 si le sort n'a pas de {X}). */
    public static function maxX(PlayerState $p, ManaCost $cost): int
    {
        if (!$cost->hasX()) {
            return 0;
        }
        $sources = self::sources($p);
        $pool = $p->pool->toArray();

        // Borne haute : tout le mana potentiel, divisé par le nombre de {X}. Le coût croît avec X, donc faisable est monotone.
        $potential = array_sum($pool);
        foreach ($sources as $s) {
            $potential += max(array_column($s['choices'], 'total'));
        }
        $fixed = $cost->generic + array_sum($cost->colored) + count($cost->hybrid);
        $limit = intdiv(max(0, $potential - $fixed), $cost->x);

        $best = 0;
        for ($x = 1; $x <= $limit; $x++) {
            $state = ['nodes' => 0];
            if (self::solve($sources, $pool, $cost, $x, $state) === null) {
                break;
            }
            $best = $x;
        }

        return $best;
    }

    /**
     * Permanents non engagés avec au moins une capacité de mana `{T}`.
     *
     * @return list<array{card: string, multi: bool, choices: list<array{ability: int, option: int, prod: array<string,int>, total: int}>}>
     */
    private static function sources(PlayerState $p): array
    {
        $order = [];
        foreach (Color::cases() as $i => $color) {
            $order[$color->symbol()] = $i;
        }

        $sources = [];
        foreach ($p->battlefield as $card) {
            if ($card->tapped) {
                continue;
            }
            $choices = [];
            foreach ($card->abilities() as $ai => $ability) {
                if (!$ability->isManaAbility || $ability->effect !== 'mana' || !$ability->tap) {
                    continue;
                }
                foreach ($ability->options as $oi => $_) {
                    $prod = $ability->produced($oi);
                    $total = array_sum($prod);
                    if ($total === 0) {
                        continue;
                    }
                    $first = $ability->options[$oi][0]->symbol();
                    $choices[] = ['ability' => $ai, 'option' => $oi, 'prod' => $prod, 'total' => $total, 'k' => $order[$first]];
                }
            }
            if ($choices === []) {
                continue;
            }
            usort($choices, static fn (array $a, array $b) => $a['k'] <=> $b['k']); // stable (PHP 8) : WUBRGC puis ordre déclaré
            $sources[] = ['card' => $card->id, 'multi' => count($choices) > 1, 'choices' => $choices];
        }

        return $sources;
    }

    /**
     * @param list<array{card: string, multi: bool, choices: list<array{ability: int, option: int, prod: array<string,int>, total: int}>}> $sources
     * @param array<string, int> $pool
     * @param array{nodes: int} $state
     */
    private static function solve(array $sources, array $pool, ManaCost $cost, int $x, array &$state): ?ManaPlan
    {
        $generic = $cost->generic + $x * $cost->x;
        $h = count($cost->hybrid);
        $best = null; // ['key' => list<int>, 'plan' => ManaPlan]

        for ($mask = 0; $mask < (1 << $h); $mask++) {
            $colored = $cost->colored;
            foreach ($cost->hybrid as $i => $pair) {
                $sym = $pair[($mask >> $i) & 1]->symbol();
                $colored[$sym] = ($colored[$sym] ?? 0) + 1;
            }

            // 2. La réserve paie d'abord.
            $poolSpend = [];
            $poolLeft = $pool;
            $residual = [];
            foreach (Color::cases() as $color) {
                $s = $color->symbol();
                $need = $colored[$s] ?? 0;
                $use = min($need, $poolLeft[$s] ?? 0);
                if ($use > 0) {
                    $poolSpend[$s] = $use;
                    $poolLeft[$s] -= $use;
                }
                if ($need - $use > 0) {
                    $residual[$s] = $need - $use;
                }
            }
            $g = $generic;
            foreach (self::genericOrder() as $s) {
                $use = min($g, $poolLeft[$s] ?? 0);
                if ($use > 0) {
                    $poolSpend[$s] = ($poolSpend[$s] ?? 0) + $use;
                    $g -= $use;
                }
            }
            $poolUsed = array_sum($poolSpend);

            // 3. Couverture du besoin résiduel par des engagements.
            $found = self::cover($sources, $residual, $g, $state);
            if ($found === null) {
                if ($state['nodes'] >= self::NODE_LIMIT) {
                    break;
                }
                continue;
            }

            $key = [-$poolUsed, $found['waste'], $found['taps'], $found['multi'], $mask];
            if ($best === null || self::less($key, $best['key'])) {
                $best = ['key' => $key, 'plan' => self::build($found['picks'], $residual, $g, $poolSpend)];
            }
            if ($state['nodes'] >= self::NODE_LIMIT) {
                break;
            }
        }

        return $best['plan'] ?? null;
    }

    /**
     * Meilleure couverture de `$residual` (colorés) + `$generic` par des engagements.
     *
     * @return array{waste: int, taps: int, multi: int, picks: list<array{card: string, ability: int, option: int, prod: array<string,int>}>}|null
     */
    private static function cover(array $sources, array $residual, int $generic, array &$state): ?array
    {
        $need = array_sum($residual) + $generic;
        if ($need === 0) {
            return ['waste' => 0, 'taps' => 0, 'multi' => 0, 'picks' => []];
        }

        $n = count($sources);
        // Bornes de suffixe : production maximale restante (totale, par couleur, par engagement).
        $sufTotal = array_fill(0, $n + 1, 0);
        $sufMax = array_fill(0, $n + 1, 0);
        $sufColor = array_fill(0, $n + 1, []);
        for ($i = $n - 1; $i >= 0; $i--) {
            $sufTotal[$i] = $sufTotal[$i + 1] + max(array_column($sources[$i]['choices'], 'total'));
            $sufMax[$i] = max($sufMax[$i + 1], max(array_column($sources[$i]['choices'], 'total')));
            $sufColor[$i] = $sufColor[$i + 1];
            $per = [];
            foreach ($sources[$i]['choices'] as $c) {
                foreach ($c['prod'] as $s => $v) {
                    $per[$s] = max($per[$s] ?? 0, $v);
                }
            }
            foreach ($per as $s => $v) {
                $sufColor[$i][$s] = ($sufColor[$i][$s] ?? 0) + $v;
            }
        }

        $best = null;
        $picks = [];
        $have = [];

        $search = function (int $i, int $total, int $multi) use (&$search, &$best, &$picks, &$have, &$state, $sources, $residual, $need, $n, $sufTotal, $sufMax, $sufColor): void {
            if ($state['nodes']++ >= self::NODE_LIMIT) {
                return;
            }
            $deficit = 0;
            foreach ($residual as $s => $r) {
                $d = $r - ($have[$s] ?? 0);
                if ($d > 0) {
                    $deficit += $d;
                    if (($sufColor[$i][$s] ?? 0) < $d) {
                        return;
                    }
                }
            }
            $totalDeficit = max($deficit, $need - $total);
            $taps = count($picks);

            if ($totalDeficit === 0) {
                $key = [$total - $need, $taps, $multi];
                if ($best === null || self::less($key, $best['key'])) {
                    $best = ['key' => $key, 'waste' => $total - $need, 'taps' => $taps, 'multi' => $multi, 'picks' => $picks];
                }

                return;
            }
            if ($i >= $n || $need - $total > $sufTotal[$i] || $sufMax[$i] === 0) {
                return;
            }
            if ($best !== null) {
                $lb = [max($total, $need) - $need, $taps + intdiv($totalDeficit + $sufMax[$i] - 1, $sufMax[$i]), $multi];
                if (!self::less($lb, $best['key'])) {
                    return;
                }
            }

            $src = $sources[$i];
            foreach ($src['choices'] as $c) {
                $picks[] = ['card' => $src['card'], 'ability' => $c['ability'], 'option' => $c['option'], 'prod' => $c['prod']];
                foreach ($c['prod'] as $s => $v) {
                    $have[$s] = ($have[$s] ?? 0) + $v;
                }
                $search($i + 1, $total + $c['total'], $multi + ($src['multi'] ? 1 : 0));
                foreach ($c['prod'] as $s => $v) {
                    $have[$s] -= $v;
                }
                array_pop($picks);
            }
            $search($i + 1, $total, $multi);
        };
        $search(0, 0, 0);

        return $best;
    }

    /** @param list<array{card: string, ability: int, option: int, prod: array<string,int>}> $picks */
    private static function build(array $picks, array $residual, int $generic, array $poolSpend): ManaPlan
    {
        $taps = [];
        $produced = [];
        foreach ($picks as $pick) {
            $taps[] = ['card' => $pick['card'], 'ability' => $pick['ability'], 'option' => $pick['option']];
            foreach ($pick['prod'] as $s => $v) {
                $produced[$s] = ($produced[$s] ?? 0) + $v;
            }
        }
        $spend = $poolSpend;
        foreach ($residual as $s => $r) {
            $spend[$s] = ($spend[$s] ?? 0) + $r;
            $produced[$s] -= $r;
        }
        foreach (self::genericOrder() as $s) {
            $use = min($generic, $produced[$s] ?? 0);
            if ($use > 0) {
                $spend[$s] = ($spend[$s] ?? 0) + $use;
                $generic -= $use;
            }
        }
        // Ordre stable des symboles dans `spend`.
        $ordered = [];
        foreach (Color::cases() as $color) {
            if (($spend[$color->symbol()] ?? 0) > 0) {
                $ordered[$color->symbol()] = $spend[$color->symbol()];
            }
        }

        return new ManaPlan($taps, $ordered);
    }

    /** Ordre dans lequel on dépense le mana pour du générique : incolore d'abord (garde les couleurs), puis WUBRG. */
    private static function genericOrder(): array
    {
        return ['C', 'W', 'U', 'B', 'R', 'G'];
    }

    /** @param list<int> $a @param list<int> $b */
    private static function less(array $a, array $b): bool
    {
        foreach ($a as $i => $v) {
            if ($v !== $b[$i]) {
                return $v < $b[$i];
            }
        }

        return false;
    }
}
