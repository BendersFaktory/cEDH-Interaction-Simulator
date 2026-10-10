<?php

declare(strict_types=1);

namespace App\Engine\Mana;

/**
 * Résultat de `ManaPayment::plan()` : comment payer un coût. Sans effet de bord ; exécuté par `PlayerState::payWithPlan()`.
 *
 * - `taps` : capacités de mana à activer (ordre d'exécution) ; `option` = alternative produite.
 * - `spend` : mana réellement dépensé pour le coût, par symbole (pool existant + mana des `taps` et des `converters`).
 * - `converters` : convertisseurs (Signets) activés APRÈS les `taps`, dans l'ordre ; `spend` = leur coût, pris dans la réserve.
 *   Ce qui est produit mais pas dépensé (Sol Ring pour un `{1}`) reste dans le pool.
 */
final class ManaPlan
{
    /**
     * @param list<array{card: string, ability: int, option: int}> $taps
     * @param array<string, int>                                    $spend
     * @param list<array{card: string, ability: int, option: int, spend: array<string, int>}> $converters
     */
    public function __construct(
        public readonly array $taps = [],
        public readonly array $spend = [],
        public readonly array $converters = [],
    ) {
    }
}
