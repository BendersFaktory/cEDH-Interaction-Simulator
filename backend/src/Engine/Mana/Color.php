<?php

declare(strict_types=1);

namespace App\Engine\Mana;

/**
 * Couleurs de mana. La lettre (WUBRGC) est le seul format sérialisé : coûts `{2}{U}`, événements, états.
 * Ne jamais sérialiser l'enum directement ; les maps internes sont indexées par `symbol()` (un enum ne peut pas être clé de tableau).
 */
enum Color
{
    case White;
    case Blue;
    case Black;
    case Red;
    case Green;
    case Colorless;

    public function symbol(): string
    {
        return match ($this) {
            self::White => 'W',
            self::Blue => 'U',
            self::Black => 'B',
            self::Red => 'R',
            self::Green => 'G',
            self::Colorless => 'C',
        };
    }

    public static function fromSymbol(string $symbol): self
    {
        return match ($symbol) {
            'W' => self::White,
            'U' => self::Blue,
            'B' => self::Black,
            'R' => self::Red,
            'G' => self::Green,
            'C' => self::Colorless,
            default => throw new \InvalidArgumentException("Symbole de mana inconnu : $symbol"),
        };
    }
}
