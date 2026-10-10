<?php

declare(strict_types=1);

namespace App\Engine\Mana;

/**
 * Coût de mana immuable : générique, coloré, hybride et X. Forme texte : `{X}{2}{R/G}{U}{U}`.
 * Les maps sont indexées par `Color::symbol()`.
 */
final class ManaCost
{
    /**
     * @param array<string, int>                $colored symbole => nombre de symboles colorés (ou {C}) à payer
     * @param list<array{0: Color, 1: Color}>   $hybrid  un élément par symbole hybride {A/B}
     * @param int                               $x       nombre de {X}
     */
    public function __construct(
        public readonly int $generic = 0,
        public readonly array $colored = [],
        public readonly array $hybrid = [],
        public readonly int $x = 0,
    ) {
    }

    public static function parse(string $text): self
    {
        if (!preg_match('/^(\{[^{}]+\})*$/', $text)) {
            throw new \InvalidArgumentException("Coût de mana invalide : $text");
        }
        preg_match_all('/\{([^{}]+)\}/', $text, $m);

        $generic = 0;
        $x = 0;
        $colored = [];
        $hybrid = [];
        foreach ($m[1] as $token) {
            if (ctype_digit($token)) {
                $generic += (int) $token;
            } elseif ($token === 'X') {
                $x++;
            } elseif (str_contains($token, '/')) {
                [$a, $b] = explode('/', $token, 2);
                $hybrid[] = [Color::fromSymbol($a), Color::fromSymbol($b)];
            } else {
                $symbol = Color::fromSymbol($token)->symbol();
                $colored[$symbol] = ($colored[$symbol] ?? 0) + 1;
            }
        }

        return new self($generic, $colored, $hybrid, $x);
    }

    /** Valeur de mana ; chaque {X} vaut `$x` (sur la pile). */
    public function manaValue(int $x = 0): int
    {
        return $this->generic + array_sum($this->colored) + count($this->hybrid) + $x * $this->x;
    }

    public function hasX(): bool
    {
        return $this->x > 0;
    }

    /** Taxe de commandant : ajoute du mana générique. */
    public function withExtraGeneric(int $n): self
    {
        return new self($this->generic + $n, $this->colored, $this->hybrid, $this->x);
    }

    /** Forme canonique : X, générique, hybrides, puis couleurs dans l'ordre WUBRGC. */
    public function __toString(): string
    {
        $out = str_repeat('{X}', $this->x);
        if ($this->generic > 0) {
            $out .= '{' . $this->generic . '}';
        }
        foreach ($this->hybrid as [$a, $b]) {
            $out .= '{' . $a->symbol() . '/' . $b->symbol() . '}';
        }
        foreach (Color::cases() as $color) {
            $out .= str_repeat('{' . $color->symbol() . '}', $this->colored[$color->symbol()] ?? 0);
        }

        return $out === '' ? '{0}' : $out;
    }
}
