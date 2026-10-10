<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Ability\AbilityManager;
use App\Engine\Game\PlayerState;

/**
 * Solveur de paiement (auto-paiement) : recherche exacte, déterministe, sans effet de bord.
 *
 * Sources : la réserve (`$p->pool`) et, pour chaque permanent prêt (non engagé ; pas une créature arrivée au tour `$turn`,
 * mal d'invocation), ses capacités de mana `{T}`. `$turn` null = mal d'invocation ignoré (tests du solveur). Un permanent
 * ne s'engage qu'une fois : il choisit UNE capacité et UNE alternative (`choices`). `$exclude` = permanent à ignorer
 * (la source d'une capacité de mana dont on paie le coût ne se paie pas elle-même).
 *
 * Convertisseurs : capacités de mana `{T}` qui ont aussi un coût de mana (Signet : `{1}, {T} : {U}{R}`). Leur coût est
 * payé par la réserve et les sources simples ; leur production ne paie que le coût demandé, jamais un autre coût de
 * convertisseur (ni autofinancement, ni chaînage). Au plus MAX_CONVERTERS convertisseurs (ordre du champ de bataille).
 *
 * Étapes, pour chaque combinaison de convertisseurs (aucun d'abord) :
 *  1. Pour chacune des 2^h affectations des hybrides (ordre stable), le coût devient « colorés + générique ».
 *  2. La production des convertisseurs paie d'abord le coût (colorés, puis générique) ; le reste du coût et les coûts des
 *     convertisseurs forment le besoin. La réserve paie ensuite (colorés, puis générique : incolore d'abord, puis WUBRG).
 *  3. Backtracking sur les permanents simples (ordre du champ de bataille) pour couvrir le besoin résiduel.
 *
 * Critère (lexicographique, plus petit = mieux) : plus de réserve dépensée, moins de mana gaspillé (produit non dépensé),
 * moins de sacrifices (un Treasure ne sert qu'en dernier recours), moins d'engagements (convertisseurs compris), moins de sources à plusieurs alternatives engagées, puis ordre du champ de
 * bataille / WUBRGC / masque d'hybrides (le premier trouvé gagne : un convertisseur n'est utilisé que s'il fait strictement
 * mieux). Élagage par bornes ; au plus NODE_LIMIT nœuds explorés par appel, au-delà on renvoie le meilleur plan trouvé (ou null).
 */
final class ManaPayment
{
    private const NODE_LIMIT = 50000;
    private const MAX_CONVERTERS = 3;

    public static function plan(PlayerState $p, ManaCost $cost, int $x = 0, ?int $turn = null, ?string $exclude = null): ?ManaPlan
    {
        $state = ['nodes' => 0];

        return self::solve(self::sources($p, $turn, $exclude), self::converters($p, $turn, $exclude), $p->pool->toArray(), $cost, $x, $state);
    }

    /** Plus grand X payable avec la réserve et les sources non engagées (0 si le sort n'a pas de {X}). */
    public static function maxX(PlayerState $p, ManaCost $cost, ?int $turn = null): int
    {
        if (!$cost->hasX()) {
            return 0;
        }
        $sources = self::sources($p, $turn, null);
        $converters = self::converters($p, $turn, null);
        $pool = $p->pool->toArray();

        // Borne haute : tout le mana potentiel, divisé par le nombre de {X}. Le coût croît avec X, donc faisable est monotone.
        $potential = array_sum($pool);
        foreach ($sources as $s) {
            $potential += max(array_column($s['choices'], 'total'));
        }
        foreach ($converters as $c) {
            $potential += max(0, max(array_map(static fn (array $ch) => $ch['total'] - $ch['costTotal'], $c['choices'])));
        }
        $fixed = $cost->generic + array_sum($cost->colored) + count($cost->hybrid);
        $limit = intdiv(max(0, $potential - $fixed), $cost->x);

        $best = 0;
        for ($x = 1; $x <= $limit; $x++) {
            $state = ['nodes' => 0];
            if (self::solve($sources, $converters, $pool, $cost, $x, $state) === null) {
                break;
            }
            $best = $x;
        }

        return $best;
    }

    /**
     * Permanents prêts (voir `AbilityManager::sourceReady`) avec au moins une capacité de mana `{T}` sans coût de mana.
     *
     * @return list<array{card: string, multi: bool, choices: list<array{ability: int, option: int, prod: array<string,int>, total: int}>}>
     */
    private static function sources(PlayerState $p, ?int $turn, ?string $exclude): array
    {
        return self::collect($p, $turn, $exclude, false);
    }

    /**
     * Convertisseurs prêts (capacité de mana `{T}` avec un coût de mana sans hybride ni X), au plus MAX_CONVERTERS.
     *
     * @return list<array{card: string, multi: bool, choices: list<array{ability: int, option: int, prod: array<string,int>, total: int, cost: ManaCost, costTotal: int}>}>
     */
    private static function converters(PlayerState $p, ?int $turn, ?string $exclude): array
    {
        return array_slice(self::collect($p, $turn, $exclude, true), 0, self::MAX_CONVERTERS);
    }

    private static function collect(PlayerState $p, ?int $turn, ?string $exclude, bool $withCost): array
    {
        $order = [];
        foreach (Color::cases() as $i => $color) {
            $order[$color->symbol()] = $i;
        }

        $sources = [];
        foreach ($p->battlefield as $card) {
            if ($card->id === $exclude) {
                continue;
            }
            $choices = [];
            foreach ($card->abilities() as $ai => $ability) {
                if (!$ability->isManaAbility || $ability->effect !== 'mana' || !$ability->tap
                    || ($ability->manaCost !== null) !== $withCost
                    || ($withCost && ($ability->manaCost->hybrid !== [] || $ability->manaCost->x > 0))
                    || !AbilityManager::sourceReady($card, $ability, $turn)) {
                    continue;
                }
                foreach ($ability->options as $oi => $_) {
                    $prod = $ability->produced($oi);
                    $total = array_sum($prod);
                    if ($total === 0) {
                        continue;
                    }
                    $first = $ability->options[$oi][0]->symbol();
                    $choice = ['ability' => $ai, 'option' => $oi, 'prod' => $prod, 'total' => $total, 'k' => $order[$first], 'sac' => $ability->sacrifice ? 1 : 0];
                    if ($withCost) {
                        $choice['cost'] = $ability->manaCost;
                        $choice['costTotal'] = $ability->manaCost->manaValue();
                    }
                    $choices[] = $choice;
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
     * Combinaisons de convertisseurs : la combinaison vide d'abord, puis dans l'ordre du champ de bataille.
     *
     * @return list<list<array{card: string, ability: int, option: int, prod: array<string,int>, cost: ManaCost}>>
     */
    private static function combinations(array $converters): array
    {
        $combos = [[]];
        foreach ($converters as $conv) {
            $next = [];
            foreach ($combos as $combo) {
                $next[] = $combo;
            }
            foreach ($combos as $combo) {
                foreach ($conv['choices'] as $c) {
                    $next[] = [...$combo, ['card' => $conv['card'], 'ability' => $c['ability'], 'option' => $c['option'], 'prod' => $c['prod'], 'cost' => $c['cost']]];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    /**
     * @param array<string, int> $pool
     * @param array{nodes: int} $state
     */
    private static function solve(array $sources, array $converters, array $pool, ManaCost $cost, int $x, array &$state): ?ManaPlan
    {
        $h = count($cost->hybrid);
        $best = null; // ['key' => list<int>, 'plan' => ManaPlan]

        foreach (self::combinations($converters) as $combo) {
            // Production des convertisseurs (réservée au coût demandé) et coût cumulé des convertisseurs.
            $out = [];
            $convColored = [];
            $convGeneric = 0;
            $used = [];
            foreach ($combo as $conv) {
                $used[$conv['card']] = true;
                foreach ($conv['prod'] as $sym => $n) {
                    $out[$sym] = ($out[$sym] ?? 0) + $n;
                }
                foreach ($conv['cost']->colored as $sym => $n) {
                    $convColored[$sym] = ($convColored[$sym] ?? 0) + $n;
                }
                $convGeneric += $conv['cost']->generic;
            }
            $simple = $used === [] ? $sources : array_values(array_filter($sources, static fn (array $src) => !isset($used[$src['card']])));

            for ($mask = 0; $mask < (1 << $h); $mask++) {
                $colored = $cost->colored;
                foreach ($cost->hybrid as $i => $pair) {
                    $sym = $pair[($mask >> $i) & 1]->symbol();
                    $colored[$sym] = ($colored[$sym] ?? 0) + 1;
                }

                // 2a. La production des convertisseurs paie d'abord le coût demandé.
                $outLeft = $out;
                $outSpend = [];
                $restColored = [];
                foreach ($colored as $sym => $need) {
                    $use = min($need, $outLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $outSpend[$sym] = $use;
                        $outLeft[$sym] -= $use;
                    }
                    if ($need - $use > 0) {
                        $restColored[$sym] = $need - $use;
                    }
                }
                $restGeneric = $cost->generic + $x * $cost->x;
                foreach (self::genericOrder() as $sym) {
                    $use = min($restGeneric, $outLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $outSpend[$sym] = ($outSpend[$sym] ?? 0) + $use;
                        $outLeft[$sym] -= $use;
                        $restGeneric -= $use;
                    }
                }

                // 2b. Besoin restant (coût demandé + coûts des convertisseurs) : la réserve paie d'abord.
                $needColored = $restColored;
                foreach ($convColored as $sym => $n) {
                    $needColored[$sym] = ($needColored[$sym] ?? 0) + $n;
                }
                $poolSpend = [];
                $poolLeft = $pool;
                $residual = [];
                foreach (Color::cases() as $color) {
                    $sym = $color->symbol();
                    $need = $needColored[$sym] ?? 0;
                    $use = min($need, $poolLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $poolSpend[$sym] = $use;
                        $poolLeft[$sym] -= $use;
                    }
                    if ($need - $use > 0) {
                        $residual[$sym] = $need - $use;
                    }
                }
                $g = $restGeneric + $convGeneric;
                foreach (self::genericOrder() as $sym) {
                    $use = min($g, $poolLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $poolSpend[$sym] = ($poolSpend[$sym] ?? 0) + $use;
                        $poolLeft[$sym] -= $use;
                        $g -= $use;
                    }
                }
                $poolUsed = array_sum($poolSpend);

                // 3. Couverture du besoin résiduel par des engagements de sources simples.
                $found = self::cover($simple, $residual, $g, $state);
                if ($found === null) {
                    if ($state['nodes'] >= self::NODE_LIMIT) {
                        break 2;
                    }
                    continue;
                }

                $waste = $found['waste'] + array_sum($outLeft);
                $key = [-$poolUsed, $waste, $found['sac'], $found['taps'] + count($combo), $found['multi'], $mask];
                if ($best === null || self::less($key, $best['key'])) {
                    $best = ['key' => $key, 'plan' => self::build($found['picks'], $residual, $g, $poolSpend, $combo, $outSpend, $restColored)];
                }
                if ($state['nodes'] >= self::NODE_LIMIT) {
                    break 2;
                }
            }
        }

        return $best['plan'] ?? null;
    }

    /**
     * Meilleure couverture de `$residual` (colorés) + `$generic` par des engagements.
     *
     * @return array{waste: int, sac: int, taps: int, multi: int, picks: list<array{card: string, ability: int, option: int, prod: array<string,int>}>}|null
     */
    private static function cover(array $sources, array $residual, int $generic, array &$state): ?array
    {
        $need = array_sum($residual) + $generic;
        if ($need === 0) {
            return ['waste' => 0, 'sac' => 0, 'taps' => 0, 'multi' => 0, 'picks' => []];
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

        $search = function (int $i, int $total, int $multi, int $sac) use (&$search, &$best, &$picks, &$have, &$state, $sources, $residual, $need, $n, $sufTotal, $sufMax, $sufColor): void {
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
                $key = [$total - $need, $sac, $taps, $multi];
                if ($best === null || self::less($key, $best['key'])) {
                    $best = ['key' => $key, 'waste' => $total - $need, 'sac' => $sac, 'taps' => $taps, 'multi' => $multi, 'picks' => $picks];
                }

                return;
            }
            if ($i >= $n || $need - $total > $sufTotal[$i] || $sufMax[$i] === 0) {
                return;
            }
            if ($best !== null) {
                $lb = [max($total, $need) - $need, $sac, $taps + intdiv($totalDeficit + $sufMax[$i] - 1, $sufMax[$i]), $multi];
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
                $search($i + 1, $total + $c['total'], $multi + ($src['multi'] ? 1 : 0), $sac + $c['sac']);
                foreach ($c['prod'] as $s => $v) {
                    $have[$s] -= $v;
                }
                array_pop($picks);
            }
            $search($i + 1, $total, $multi, $sac);
        };
        $search(0, 0, 0, 0);

        return $best;
    }

    /**
     * Assemble le plan. `$residual` + `$generic` = ce que les sources simples paient (avec `$poolSpend` pour la réserve) pour
     * le reste du coût demandé ET les coûts des convertisseurs. On met d'abord de côté les colorés du coût demandé
     * (`$restColored`), puis chaque convertisseur prend son coût (colorés, puis générique : incolore d'abord) ; ce qui reste
     * paie le générique du coût demandé, en plus de la production des convertisseurs (`$outSpend`).
     *
     * @param list<array{card: string, ability: int, option: int, prod: array<string,int>}> $picks
     * @param list<array{card: string, ability: int, option: int, prod: array<string,int>, cost: ManaCost}> $combo
     */
    private static function build(array $picks, array $residual, int $generic, array $poolSpend, array $combo = [], array $outSpend = [], array $restColored = []): ManaPlan
    {
        $taps = [];
        $produced = [];
        foreach ($picks as $pick) {
            $taps[] = ['card' => $pick['card'], 'ability' => $pick['ability'], 'option' => $pick['option']];
            foreach ($pick['prod'] as $s => $v) {
                $produced[$s] = ($produced[$s] ?? 0) + $v;
            }
        }
        // Mana dépensé hors convertisseurs : réserve + colorés résiduels + générique pris sur la production simple.
        $real = $poolSpend;
        foreach ($residual as $s => $r) {
            $real[$s] = ($real[$s] ?? 0) + $r;
            $produced[$s] -= $r;
        }
        foreach (self::genericOrder() as $s) {
            $use = min($generic, $produced[$s] ?? 0);
            if ($use > 0) {
                $real[$s] = ($real[$s] ?? 0) + $use;
                $generic -= $use;
            }
        }

        // Partage entre les coûts des convertisseurs et le coût demandé.
        $left = $real;
        foreach ($restColored as $s => $n) {
            $left[$s] -= $n;
        }
        $converters = [];
        foreach ($combo as $conv) {
            $spend = [];
            foreach ($conv['cost']->colored as $s => $n) {
                $spend[$s] = $n;
                $left[$s] -= $n;
            }
            $g = $conv['cost']->generic;
            foreach (self::genericOrder() as $s) {
                $use = min($g, $left[$s] ?? 0);
                if ($use > 0) {
                    $spend[$s] = ($spend[$s] ?? 0) + $use;
                    $left[$s] -= $use;
                    $g -= $use;
                }
            }
            $converters[] = ['card' => $conv['card'], 'ability' => $conv['ability'], 'option' => $conv['option'], 'spend' => self::ordered($spend)];
        }
        $spend = $outSpend;
        foreach ($restColored as $s => $n) {
            $spend[$s] = ($spend[$s] ?? 0) + $n;
        }
        foreach ($left as $s => $n) {
            if ($n > 0) {
                $spend[$s] = ($spend[$s] ?? 0) + $n;
            }
        }

        return new ManaPlan($taps, self::ordered($spend), $converters);
    }

    /**
     * Ordre stable des symboles (WUBRGC), sans les zéros.
     *
     * @param array<string, int> $amounts
     * @return array<string, int>
     */
    private static function ordered(array $amounts): array
    {
        $ordered = [];
        foreach (Color::cases() as $color) {
            if (($amounts[$color->symbol()] ?? 0) > 0) {
                $ordered[$color->symbol()] = $amounts[$color->symbol()];
            }
        }

        return $ordered;
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
