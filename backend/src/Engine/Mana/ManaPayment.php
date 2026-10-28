<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Game\CardInstance;
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
 * Mana conditionnel : `$for` = la carte payée (sort lancé, ou source de la capacité). Le mana restreint de la réserve et les
 * options restreintes des sources ne sont pris en compte que si leur restriction admet `$for`. Le mana restreint de la
 * réserve paie le coût demandé (jamais celui d'un convertisseur) AVANT la réserve libre ; une option restreinte n'est
 * candidate que sans convertisseur, et n'est choisie que si elle fait strictement mieux selon le critère (premier trouvé
 * gagne, l'option libre d'une même source étant essayée d'abord).
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
    /** Symboles dans l'ordre de `Color::cases()` (évite d'appeler `symbol()` dans les boucles chaudes). */
    private const WUBRGC = ['W', 'U', 'B', 'R', 'G', 'C'];
    /** Ordre dans lequel on dépense le mana pour du générique : incolore d'abord (garde les couleurs), puis WUBRG. */
    private const GENERIC_ORDER = ['C', 'W', 'U', 'B', 'R', 'G'];

    public static function plan(PlayerState $p, ManaCost $cost, int $x = 0, ?int $turn = null, ?string $exclude = null, ?CardInstance $for = null, ?ManaContext $ctx = null): ?ManaPlan
    {
        $ctx ??= new ManaContext($p, $turn);
        $sources = $ctx->sources($exclude, $for);
        $converters = $ctx->converters($exclude, self::MAX_CONVERTERS);
        $pool = $ctx->pool();
        $restricted = $ctx->restrictedPool($for);

        if ($cost->phyrexian === []) {
            $state = ['nodes' => 0];

            return self::solve($sources, $converters, $pool, $restricted, $cost, $x, $state);
        }
        // Symboles phyrexian (CR 107.4f) : le plus de mana possible, le reste en PV (2 par symbole, jamais sous 1 PV : CR 119.4).
        $n = count($cost->phyrexian);
        for ($life = 0; $life <= $n; $life++) {
            if ($life > 0 && $p->life - 2 * $life < 1) {
                return null;
            }
            $state = ['nodes' => 0];
            $plan = self::solve($sources, $converters, $pool, $restricted, $cost->withPhyrexianAsMana($n - $life), $x, $state);
            if ($plan !== null) {
                return $life === 0 ? $plan : $plan->withLifeCost(2 * $life);
            }
        }

        return null;
    }

    /** Photo du mana de `$p` à passer à `plan()`/`maxX()` pour scanner le champ de bataille une seule fois (état inchangé). */
    public static function context(PlayerState $p, ?int $turn): ManaContext
    {
        return new ManaContext($p, $turn);
    }

    /** Plus grand X payable avec la réserve et les sources non engagées (0 si le sort n'a pas de {X}). */
    public static function maxX(PlayerState $p, ManaCost $cost, ?int $turn = null, ?CardInstance $for = null, ?ManaContext $ctx = null): int
    {
        if (!$cost->hasX()) {
            return 0;
        }
        // Symboles phyrexian : payés en PV autant que les PV le permettent (jamais sous 1 PV), le reste en mana.
        if ($cost->phyrexian !== []) {
            $byLife = min(count($cost->phyrexian), intdiv(max(0, $p->life - 1), 2));
            $cost = $cost->withPhyrexianAsMana(count($cost->phyrexian) - $byLife);
        }
        $ctx ??= new ManaContext($p, $turn);
        $sources = $ctx->sources(null, $for);
        $converters = $ctx->converters(null, self::MAX_CONVERTERS);
        $pool = $ctx->pool();
        $restricted = $ctx->restrictedPool($for);

        // Borne haute : tout le mana potentiel, divisé par le nombre de {X}. Le coût croît avec X, donc faisable est monotone.
        $potential = array_sum($pool) + array_sum(array_map('array_sum', $restricted));
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
            if (self::solve($sources, $converters, $pool, $restricted, $cost, $x, $state) === null) {
                break;
            }
            $best = $x;
        }

        return $best;
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
     * @param array<string, array<string, int>> $restricted mana restreint admis de la réserve
     * @param array{nodes: int} $state
     */
    private static function solve(array $sources, array $converters, array $pool, array $restricted, ManaCost $cost, int $x, array &$state): ?ManaPlan
    {
        $h = count($cost->hybrid);
        $best = null; // ['key' => list<int>, 'plan' => ManaPlan]
        $freeSources = null; // sources sans options restreintes, calculées au premier convertisseur engagé

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
            $simple = $used === [] ? $sources : array_values(array_filter($freeSources ??= self::withoutRestricted($sources), static fn (array $src) => !isset($used[$src['card']])));

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
                foreach (self::GENERIC_ORDER as $sym) {
                    $use = min($restGeneric, $outLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $outSpend[$sym] = ($outSpend[$sym] ?? 0) + $use;
                        $outLeft[$sym] -= $use;
                        $restGeneric -= $use;
                    }
                }

                // 2b. Le mana restreint admis de la réserve paie le reste du coût demandé (colorés, puis générique).
                $rSpend = [];
                if ($restricted !== []) {
                    $rLeft = $restricted;
                    foreach ($restColored as $sym => $need) {
                        foreach ($rLeft as $kind => $amounts) {
                            $use = min($need, $amounts[$sym] ?? 0);
                            if ($use > 0) {
                                $rSpend[$kind][$sym] = $use;
                                $rLeft[$kind][$sym] -= $use;
                                $need -= $use;
                            }
                        }
                        if ($need > 0) {
                            $restColored[$sym] = $need;
                        } else {
                            unset($restColored[$sym]);
                        }
                    }
                    foreach ($rLeft as $kind => $amounts) {
                        foreach (self::GENERIC_ORDER as $sym) {
                            $use = min($restGeneric, $amounts[$sym] ?? 0);
                            if ($use > 0) {
                                $rSpend[$kind][$sym] = ($rSpend[$kind][$sym] ?? 0) + $use;
                                $restGeneric -= $use;
                            }
                        }
                    }
                }

                // 2c. Besoin restant (coût demandé + coûts des convertisseurs) : la réserve libre paie d'abord.
                $needColored = $restColored;
                foreach ($convColored as $sym => $n) {
                    $needColored[$sym] = ($needColored[$sym] ?? 0) + $n;
                }
                $poolSpend = [];
                $poolLeft = $pool;
                $residual = [];
                foreach (self::WUBRGC as $sym) {
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
                foreach (self::GENERIC_ORDER as $sym) {
                    $use = min($g, $poolLeft[$sym] ?? 0);
                    if ($use > 0) {
                        $poolSpend[$sym] = ($poolSpend[$sym] ?? 0) + $use;
                        $poolLeft[$sym] -= $use;
                        $g -= $use;
                    }
                }
                $poolUsed = array_sum($poolSpend) + array_sum(array_map('array_sum', $rSpend));

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
                    $best = ['key' => $key, 'plan' => self::build($found['picks'], $residual, $g, $poolSpend, $combo, $outSpend, $restColored, $rSpend)];
                }
                if ($state['nodes'] >= self::NODE_LIMIT) {
                    break 2;
                }
            }
        }

        return $best['plan'] ?? null;
    }

    /**
     * Sources sans leurs options restreintes (avec un convertisseur, la production simple peut payer son coût : le mana
     * restreint n'y a pas droit).
     */
    private static function withoutRestricted(array $sources): array
    {
        $any = false;
        foreach ($sources as $src) {
            foreach ($src['choices'] as $c) {
                $any = $any || $c['rk'] !== null;
            }
        }
        if (!$any) {
            return $sources;
        }
        $out = [];
        foreach ($sources as $src) {
            $choices = array_values(array_filter($src['choices'], static fn (array $c) => $c['rk'] === null));
            if ($choices !== []) {
                $out[] = ['card' => $src['card'], 'multi' => count($choices) > 1, 'choices' => $choices];
            }
        }

        return $out;
    }

    /**
     * Meilleure couverture de `$residual` (colorés) + `$generic` par des engagements.
     *
     * @param array{nodes: int} $state
     * @return array{waste: int, sac: int, taps: int, multi: int, picks: list<array{card: string, ability: int, option: int, prod: array<string,int>, rk: string|null}>}|null
     */
    private static function cover(array $sources, array $residual, int $generic, array &$state): ?array
    {
        $need = array_sum($residual) + $generic;
        if ($need === 0) {
            return ['waste' => 0, 'sac' => 0, 'taps' => 0, 'multi' => 0, 'picks' => []];
        }

        // Couleurs du besoin résiduel, indexées 0..r-1 : seules elles comptent pour le déficit (le reste ne compte que
        // dans le total produit).
        $rSym = [];
        $rv = [];
        foreach ($residual as $s => $v) {
            $rSym[] = $s;
            $rv[] = $v;
        }
        $r = count($rv);

        // Bornes de suffixe : production maximale restante des sources i..n-1 (totale, par couleur du besoin) ; nombre de
        // choix et production maximale de chaque source. Les données d'un choix ne sont lues qu'à son engagement.
        $n = count($sources);
        $zero = $r === 0 ? [] : array_fill(0, $r, 0);
        $nc = [];
        $srcMax = [];
        $sufTotal = [$n => 0];
        $sufColor = [$n => $zero];
        $anySac = $anyMulti = false;
        $tot = 0;
        $col = $zero;
        for ($i = $n - 1; $i >= 0; $i--) {
            $src = $sources[$i];
            $nc[$i] = count($src['choices']);
            $anyMulti = $anyMulti || $src['multi'];
            $max = 0;
            $per = $zero;
            foreach ($src['choices'] as $c) {
                if ($c['total'] > $max) {
                    $max = $c['total'];
                }
                if ($c['sac'] !== 0) {
                    $anySac = true;
                }
                for ($j = 0; $j < $r; $j++) {
                    $v = $c['prod'][$rSym[$j]] ?? 0;
                    if ($v > $per[$j]) {
                        $per[$j] = $v;
                    }
                }
            }
            for ($j = 0; $j < $r; $j++) {
                $col[$j] += $per[$j];
            }
            $srcMax[$i] = $max;
            $tot += $max;
            $sufTotal[$i] = $tot;
            $sufColor[$i] = $col;
        }

        // Parcours en profondeur itératif, dans l'ordre de la recherche exhaustive : à chaque niveau i, les choix de la
        // source i dans l'ordre, puis « ne pas l'engager » (le niveau d'un nœud est l'indice de sa source). Chaque nœud
        // visité compte pour NODE_LIMIT. Clé du meilleur plan (gaspillage, sacrifices, engagements, multi) comparée
        // lexicographiquement, en ligne. Les engagements empilés sont codés `source * 256 + choix`.
        // Borne des engagements : il faut au moins `$minTaps[$i][$d]` sources parmi i..n-1 pour produire encore `$d`
        // (les plus grosses d'abord). Calculée à la demande, niveau par niveau.
        $minTaps = [];
        $sufNS = $sufNSColor = $sufSg = $sufSgColor = null; // mêmes bornes, sans sacrifice / sans source multi (à la demande)
        $best = null;
        $bw = $bs = $bt = $bm = 0;
        $picks = [];
        $have = array_fill(0, $r, 0);
        $nodes = $state['nodes'];
        $limit = self::NODE_LIMIT;
        $T = [0];   // total produit à l'entrée du niveau
        $M = [0];   // sources à plusieurs alternatives engagées
        $S = [0];   // sacrifices
        $ci = [];   // choix courant de chaque niveau ($nc[$i] = « ne pas l'engager »)
        $P = [];    // production du choix engagé à chaque niveau, sur les couleurs du besoin
        $taps = 0;
        $i = 0;

        while (true) {
            // Visite du nœud (niveau $i) : faut-il explorer ses enfants ?
            $expand = false;
            if ($nodes++ < $limit) {
                $total = $T[$i];
                $deficit = 0;
                $feasible = true;
                for ($j = 0; $j < $r; $j++) {
                    $d = $rv[$j] - $have[$j];
                    if ($d > 0) {
                        $deficit += $d;
                        if ($sufColor[$i][$j] < $d) {
                            $feasible = false;
                            break;
                        }
                    }
                }
                if ($feasible) {
                    $totalDeficit = $need - $total > $deficit ? $need - $total : $deficit;
                    if ($totalDeficit === 0) {
                        $waste = $total - $need;
                        $sac = $S[$i];
                        $multi = $M[$i];
                        if ($best === null
                            || $waste < $bw || ($waste === $bw && ($sac < $bs || ($sac === $bs && ($taps < $bt || ($taps === $bt && $multi < $bm)))))) {
                            $bw = $waste;
                            $bs = $sac;
                            $bt = $taps;
                            $bm = $multi;
                            $best = $picks;
                        }
                    } elseif ($totalDeficit <= $sufTotal[$i]) {
                        // (Chaque engagement ajoute au plus le total de sa source : au-delà de `$sufTotal`, aucune feuille.)
                        $expand = true;
                        if ($best !== null) {
                            // Borne basse de la clé de toute feuille du sous-arbre, composante par composante : il faut
                            // encore produire au moins `$totalDeficit` (gaspillage final ≥ total + déficit − besoin), avec au
                            // moins `$minTaps` sources. On n'explore que si elle est strictement meilleure que le meilleur plan :
                            // un sous-arbre élagué ne contient aucune feuille strictement meilleure, donc le plan retenu
                            // (meilleure clé, premier trouvé à égalité) est le même, et les nœuds visités sont un
                            // sous-ensemble de ceux d'une borne plus faible (NODE_LIMIT n'est pas atteint plus tôt).
                            // Sacrifices / multi : +1 si les sources restantes ne couvrent pas le déficit sans un choix à
                            // sacrifice / sans une source à plusieurs alternatives.
                            $lw = $total + $totalDeficit - $need;
                            if ($lw !== $bw) {
                                $expand = $lw < $bw;
                            } else {
                                if ($sufNS === null) {
                                    [$sufNS, $sufNSColor] = $anySac ? self::suffixes($sources, $rSym, true) : [$sufTotal, $sufColor];
                                    [$sufSg, $sufSgColor] = $anyMulti ? self::suffixes($sources, $rSym, false) : [$sufTotal, $sufColor];
                                }
                                $ls = $S[$i];
                                if ($totalDeficit > $sufNS[$i]) {
                                    $ls++;
                                } else {
                                    for ($j = 0; $j < $r; $j++) {
                                        if ($rv[$j] - $have[$j] > $sufNSColor[$i][$j]) {
                                            $ls++;
                                            break;
                                        }
                                    }
                                }
                                if ($ls !== $bs) {
                                    $expand = $ls < $bs;
                                } else {
                                    if (!isset($minTaps[$i])) {
                                        $rest = [];
                                        for ($q = $i; $q < $n; $q++) {
                                            $rest[] = $srcMax[$q];
                                        }
                                        $minTaps[$i] = self::minTaps($rest);
                                    }
                                    $lt = $taps + $minTaps[$i][$totalDeficit];
                                    if ($lt !== $bt) {
                                        $expand = $lt < $bt;
                                    } else {
                                        $lm = $M[$i];
                                        if ($totalDeficit > $sufSg[$i]) {
                                            $lm++;
                                        } else {
                                            for ($j = 0; $j < $r; $j++) {
                                                if ($rv[$j] - $have[$j] > $sufSgColor[$i][$j]) {
                                                    $lm++;
                                                    break;
                                                }
                                            }
                                        }
                                        $expand = $lm < $bm;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($expand) {
                $p = $i;
                $k = 0;
            } else {
                // Retour au parent : on défait son choix courant et on passe au suivant.
                $p = $i - 1;
                if ($p < 0) {
                    break;
                }
                $k = $ci[$p];
                if ($k < $nc[$p]) {
                    $prod = $P[$p];
                    for ($j = 0; $j < $r; $j++) {
                        $have[$j] -= $prod[$j];
                    }
                    array_pop($picks);
                    $taps--;
                }
                $k++;
            }

            // Enfant suivant du niveau $p, en remontant tant qu'un niveau n'a plus d'enfant.
            while (true) {
                if ($k < $nc[$p]) {
                    $ci[$p] = $k;
                    $c = $sources[$p]['choices'][$k];
                    $prod = $zero;
                    for ($j = 0; $j < $r; $j++) {
                        $prod[$j] = $c['prod'][$rSym[$j]] ?? 0;
                        $have[$j] += $prod[$j];
                    }
                    $P[$p] = $prod;
                    $picks[] = $p * 256 + $k;
                    $taps++;
                    $i = $p + 1;
                    $T[$i] = $T[$p] + $c['total'];
                    $M[$i] = $sources[$p]['multi'] ? $M[$p] + 1 : $M[$p];
                    $S[$i] = $S[$p] + $c['sac'];
                    continue 2;
                }
                if ($k === $nc[$p]) {
                    $ci[$p] = $k;
                    $i = $p + 1;
                    $T[$i] = $T[$p];
                    $M[$i] = $M[$p];
                    $S[$i] = $S[$p];
                    continue 2;
                }
                $p--;
                if ($p < 0) {
                    break 2;
                }
                $k = $ci[$p];
                if ($k < $nc[$p]) {
                    $prod = $P[$p];
                    for ($j = 0; $j < $r; $j++) {
                        $have[$j] -= $prod[$j];
                    }
                    array_pop($picks);
                    $taps--;
                }
                $k++;
            }
        }
        $state['nodes'] = $nodes;
        if ($best === null) {
            return null;
        }

        $out = [];
        foreach ($best as $code) {
            $src = $sources[$code >> 8];
            $c = $src['choices'][$code & 255];
            $out[] = ['card' => $src['card'], 'ability' => $c['ability'], 'option' => $c['option'], 'prod' => $c['prod'], 'rk' => $c['rk']];
        }

        return ['waste' => $bw, 'sac' => $bs, 'taps' => $bt, 'multi' => $bm, 'picks' => $out];
    }

    /**
     * Bornes de suffixe de `cover()` (production maximale des sources i..n-1, totale et par couleur `$rSym`, indice n = 0)
     * restreintes aux choix sans sacrifice (`$noSacrifice`), ou aux sources à une seule alternative.
     *
     * @param list<string> $rSym
     * @return array{0: array<int, int>, 1: array<int, list<int>>}
     */
    private static function suffixes(array $sources, array $rSym, bool $noSacrifice): array
    {
        $n = count($sources);
        $r = count($rSym);
        $tot = 0;
        $col = $r === 0 ? [] : array_fill(0, $r, 0);
        $total = [$n => 0];
        $color = [$n => $col];
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($noSacrifice || !$sources[$i]['multi']) {
                $max = 0;
                $per = $r === 0 ? [] : array_fill(0, $r, 0);
                foreach ($sources[$i]['choices'] as $c) {
                    if ($noSacrifice && $c['sac'] !== 0) {
                        continue;
                    }
                    if ($c['total'] > $max) {
                        $max = $c['total'];
                    }
                    for ($j = 0; $j < $r; $j++) {
                        $v = $c['prod'][$rSym[$j]] ?? 0;
                        if ($v > $per[$j]) {
                            $per[$j] = $v;
                        }
                    }
                }
                $tot += $max;
                for ($j = 0; $j < $r; $j++) {
                    $col[$j] += $per[$j];
                }
            }
            $total[$i] = $tot;
            $color[$i] = $col;
        }

        return [$total, $color];
    }

    /**
     * Pour chaque production `$d` (1..somme), le plus petit nombre de sources qui peuvent la fournir : on prend les plus
     * grosses d'abord. `$max` = production maximale de chaque source restante.
     *
     * @param list<int> $max
     * @return array<int, int>
     */
    private static function minTaps(array $max): array
    {
        rsort($max);
        $out = [];
        $sum = 0;
        $d = 1;
        foreach ($max as $k => $v) {
            $sum += $v;
            for (; $d <= $sum; $d++) {
                $out[$d] = $k + 1;
            }
        }

        return $out;
    }

    /**
     * Assemble le plan. `$residual` + `$generic` = ce que les sources simples paient (avec `$poolSpend` pour la réserve) pour
     * le reste du coût demandé ET les coûts des convertisseurs. On met d'abord de côté les colorés du coût demandé
     * (`$restColored`), puis chaque convertisseur prend son coût (colorés, puis générique : incolore d'abord) ; ce qui reste
     * paie le générique du coût demandé, en plus de la production des convertisseurs (`$outSpend`). Le mana restreint
     * (`$rSpend` pris dans la réserve, production des options restreintes, consommée avant la libre) ne paie que le coût
     * demandé : `$rSpend` s'ajoute à `spend`, et une option restreinte n'est jamais choisie avec un convertisseur.
     *
     * @param list<array{card: string, ability: int, option: int, prod: array<string,int>, rk: string|null}> $picks
     * @param list<array{card: string, ability: int, option: int, prod: array<string,int>, cost: ManaCost}> $combo
     */
    private static function build(array $picks, array $residual, int $generic, array $poolSpend, array $combo = [], array $outSpend = [], array $restColored = [], array $rSpend = []): ManaPlan
    {
        $taps = [];
        $produced = [];
        $producedR = []; // production restreinte, par restriction puis par symbole
        foreach ($picks as $pick) {
            $taps[] = ['card' => $pick['card'], 'ability' => $pick['ability'], 'option' => $pick['option']];
            foreach ($pick['prod'] as $s => $v) {
                $produced[$s] = ($produced[$s] ?? 0) + $v;
                if ($pick['rk'] !== null) {
                    $producedR[$pick['rk']][$s] = ($producedR[$pick['rk']][$s] ?? 0) + $v;
                }
            }
        }
        $spendR = $rSpend;
        // Mana dépensé hors convertisseurs : réserve + colorés résiduels + générique pris sur la production simple. La
        // production restreinte est consommée d'abord.
        $real = $poolSpend;
        foreach ($residual as $s => $r) {
            $real[$s] = ($real[$s] ?? 0) + $r;
            $produced[$s] -= $r;
            foreach ($producedR as $kind => $amounts) {
                $use = min($r, $amounts[$s] ?? 0);
                if ($use > 0) {
                    $producedR[$kind][$s] -= $use;
                    $spendR[$kind][$s] = ($spendR[$kind][$s] ?? 0) + $use;
                    $r -= $use;
                }
            }
        }
        foreach ($producedR as $kind => $amounts) {
            foreach (self::GENERIC_ORDER as $s) {
                $use = min($generic, $amounts[$s] ?? 0);
                if ($use > 0) {
                    $real[$s] = ($real[$s] ?? 0) + $use;
                    $produced[$s] -= $use;
                    $spendR[$kind][$s] = ($spendR[$kind][$s] ?? 0) + $use;
                    $generic -= $use;
                }
            }
        }
        foreach (self::GENERIC_ORDER as $s) {
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
            foreach (self::GENERIC_ORDER as $s) {
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
        foreach ($rSpend as $amounts) {
            foreach ($amounts as $s => $n) {
                $spend[$s] = ($spend[$s] ?? 0) + $n;
            }
        }

        return new ManaPlan($taps, self::ordered($spend), $converters, array_filter(array_map(self::ordered(...), $spendR)));
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
        foreach (self::WUBRGC as $s) {
            if (($amounts[$s] ?? 0) > 0) {
                $ordered[$s] = $amounts[$s];
            }
        }

        return $ordered;
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
