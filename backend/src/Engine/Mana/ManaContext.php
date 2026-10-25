<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Ability\AbilityManager;
use App\Engine\Game\CardInstance;
use App\Engine\Game\PlayerState;

/**
 * Photo du mana disponible d'un joueur pour un tour donné : sources, convertisseurs et réserve, scannés UNE fois.
 * Valable tant que le joueur et le champ de bataille ne changent pas (un appel de `LegalActionGenerator::for()`) ;
 * jamais stockée ni sérialisée, jamais partagée entre deux états : ce n'est qu'une mémoïsation locale d'un calcul pur.
 * Seuls `$for` (restrictions d'usage) et `$exclude` (la source d'une capacité ne paie pas son propre coût) varient
 * d'une carte à l'autre : `sources()` et `converters()` les appliquent sur le scan déjà fait, sans changer ni l'ordre
 * ni le contenu par rapport à un scan complet.
 */
final class ManaContext
{
    /** @var list<array{card: string, choices: list<array<string,mixed>>, restr: list<?ManaRestriction>, plain: bool}>|null */
    private ?array $sources = null;
    /** @var list<array{card: string, multi: bool, choices: list<array<string,mixed>>}>|null */
    private ?array $converters = null;
    /** @var array<string, int>|null */
    private ?array $pool = null;
    /** @var array<string, array<string, int>>|null */
    private ?array $restrictedPool = null;

    /** @param bool $spell false : le paiement sert une capacité (`ManaRestriction::allows()` sans sort) */
    public function __construct(private readonly PlayerState $p, private readonly ?int $turn, private readonly bool $spell = true)
    {
    }

    /** @return array<string, int> */
    public function pool(): array
    {
        return $this->pool ??= $this->p->pool->toArray();
    }

    /**
     * Mana restreint de la réserve dont la restriction admet `$for`, par restriction puis par symbole.
     *
     * @return array<string, array<string, int>>
     */
    public function restrictedPool(?CardInstance $for): array
    {
        $this->restrictedPool ??= $this->p->pool->restrictedToArray();
        $out = [];
        foreach ($this->restrictedPool as $kind => $amounts) {
            if ((new ManaRestriction($kind))->allows($for, $this->spell)) {
                $out[$kind] = $amounts;
            }
        }

        return $out;
    }

    /**
     * Permanents prêts avec au moins une capacité de mana `{T}` sans coût de mana ; une option restreinte n'est retenue
     * que si sa restriction admet `$for`.
     *
     * @return list<array{card: string, multi: bool, choices: list<array<string,mixed>>}>
     */
    public function sources(?string $exclude, ?CardInstance $for): array
    {
        $this->scan();
        $out = [];
        foreach ($this->sources as $s) {
            if ($s['card'] === $exclude) {
                continue;
            }
            if ($s['plain']) {
                $out[] = ['card' => $s['card'], 'multi' => count($s['choices']) > 1, 'choices' => $s['choices']];
                continue;
            }
            $choices = [];
            foreach ($s['choices'] as $i => $choice) {
                if ($s['restr'][$i] === null || $s['restr'][$i]->allows($for, $this->spell)) {
                    $choices[] = $choice;
                }
            }
            if ($choices !== []) {
                $out[] = ['card' => $s['card'], 'multi' => count($choices) > 1, 'choices' => $choices];
            }
        }

        return $out;
    }

    /**
     * Convertisseurs prêts (capacité de mana `{T}` avec un coût de mana sans hybride ni X), au plus `$max`.
     *
     * @return list<array{card: string, multi: bool, choices: list<array<string,mixed>>}>
     */
    public function converters(?string $exclude, int $max): array
    {
        $this->scan();
        $out = [];
        foreach ($this->converters as $c) {
            if ($c['card'] !== $exclude) {
                $out[] = $c;
                if (count($out) === $max) {
                    break;
                }
            }
        }

        return $out;
    }

    /** Un seul parcours du champ de bataille pour les sources et les convertisseurs. */
    private function scan(): void
    {
        if ($this->sources !== null) {
            return;
        }
        $order = [];
        foreach (Color::cases() as $i => $color) {
            $order[$color->symbol()] = $i;
        }

        $this->sources = [];
        $this->converters = [];
        foreach ($this->p->battlefield as $card) {
            $plain = [];
            $plainRestr = [];
            $conv = [];
            foreach ($card->abilities() as $ai => $ability) {
                if (!$ability->isManaAbility || $ability->effect !== 'mana' || !$ability->tap
                    || !AbilityManager::sourceReady($card, $ability, $this->turn)) {
                    continue;
                }
                // Source qui blesse (PV payés ou dégâts à soi) : jamais si elle ferait tomber son contrôleur à 0 PV ou moins
                // (et un coût en PV exige ces PV, CR 119.4) ; sinon comptée comme un sacrifice, en dernier recours.
                $hurt = $ability->lifeCost + $ability->selfDamage;
                if ($hurt > 0 && $this->p->life <= $hurt) {
                    continue;
                }
                $withCost = $ability->manaCost !== null;
                if ($withCost && ($ability->manaCost->hybrid !== [] || $ability->manaCost->phyrexian !== [] || $ability->manaCost->x > 0)) {
                    continue;
                }
                foreach ($ability->options as $oi => $_) {
                    $restriction = $ability->restriction($oi);
                    if ($restriction !== null && $withCost) {
                        continue;
                    }
                    $prod = $ability->produced($oi);
                    $total = array_sum($prod);
                    if ($total === 0) {
                        continue;
                    }
                    $first = $ability->options[$oi][0]->symbol();
                    $choice = ['ability' => $ai, 'option' => $oi, 'prod' => $prod, 'total' => $total, 'k' => $order[$first], 'sac' => $ability->sacrifice || $hurt > 0 ? 1 : 0, 'rk' => $restriction?->kind];
                    if ($withCost) {
                        $choice['cost'] = $ability->manaCost;
                        $choice['costTotal'] = $ability->manaCost->manaValue();
                        $conv[] = $choice;
                    } else {
                        $plain[] = $choice;
                        $plainRestr[] = $restriction;
                    }
                }
            }
            if ($plain !== []) {
                // Tri stable (PHP 8) : WUBRGC puis ordre déclaré ; la restriction reste alignée sur chaque choix.
                $idx = array_keys($plain);
                usort($idx, static fn (int $a, int $b) => $plain[$a]['k'] <=> $plain[$b]['k'] ?: $a <=> $b);
                $choices = [];
                $restr = [];
                foreach ($idx as $i) {
                    $choices[] = $plain[$i];
                    $restr[] = $plainRestr[$i];
                }
                $this->sources[] = ['card' => $card->id, 'choices' => $choices, 'restr' => $restr, 'plain' => array_filter($restr) === []];
            }
            if ($conv !== []) {
                usort($conv, static fn (array $a, array $b) => $a['k'] <=> $b['k']);
                $this->converters[] = ['card' => $card->id, 'multi' => count($conv) > 1, 'choices' => $conv];
            }
        }
    }
}
