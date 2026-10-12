<?php

declare(strict_types=1);

namespace App\Engine\Mana;

use App\Engine\Game\CardInstance;

/**
 * Restriction d'usage d'un mana (mana conditionnel, règle 106.6). Valeur sérialisée = `kind` (ex. `eldrazi`) :
 * c'est aussi la clé de la partie restreinte de la réserve et le suffixe d'une option de capacité (`{C}{C}@eldrazi`).
 *
 * `allows($paidFor)` : `$paidFor` = la carte du sort lancé, ou la source de la capacité activée.
 */
final class ManaRestriction
{
    /** Eldrazi Temple : seulement pour lancer un sort Eldrazi incolore ou activer une capacité d'un Eldrazi incolore. */
    public const ELDRAZI = 'eldrazi';

    private const KINDS = [self::ELDRAZI];

    public function __construct(public readonly string $kind)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Restriction de mana inconnue : $kind");
        }
    }

    public function allows(?CardInstance $paidFor): bool
    {
        if ($paidFor === null) {
            return false;
        }

        return match ($this->kind) {
            self::ELDRAZI => $paidFor->isColorless() && $paidFor->hasSubtype('Eldrazi', CardInstance::CREATURE),
        };
    }

    /** Texte d'aide (front, journal). */
    public function label(): string
    {
        return match ($this->kind) {
            self::ELDRAZI => 'seulement pour un sort ou une capacité d\'Eldrazi incolore',
        };
    }
}
