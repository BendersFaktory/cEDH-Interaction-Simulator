<?php

declare(strict_types=1);

namespace App\Engine\Mana;

/**
 * Réserve de mana d'un joueur (vide hors paiement ; vidée à la fin de chaque étape). Indexée par `Color::symbol()`.
 *
 * Deux parties : le mana libre (`toArray()`, table plate) et le mana restreint (`restrictedToArray()`, par
 * `ManaRestriction::$kind` puis par symbole, ex. `['eldrazi' => ['C' => 2]]`), qui ne paie que ce que sa restriction admet.
 */
final class ManaPool
{
    /**
     * @param array<string, int>                $amounts
     * @param array<string, array<string, int>> $restricted
     */
    public function __construct(private array $amounts = [], private array $restricted = [])
    {
    }

    public function add(Color $color, int $n = 1, ?ManaRestriction $restriction = null): void
    {
        if ($restriction !== null) {
            $this->restricted[$restriction->kind][$color->symbol()] = ($this->restricted[$restriction->kind][$color->symbol()] ?? 0) + $n;

            return;
        }
        $this->amounts[$color->symbol()] = ($this->amounts[$color->symbol()] ?? 0) + $n;
    }

    /** Mana libre de cette couleur. */
    public function get(Color $color): int
    {
        return $this->amounts[$color->symbol()] ?? 0;
    }

    /** Dépense du mana libre. */
    public function spend(Color $color, int $n = 1): void
    {
        if ($this->get($color) < $n) {
            throw new \DomainException('Mana insuffisant dans la réserve : ' . $color->symbol());
        }
        $left = $this->get($color) - $n;
        if ($left === 0) {
            unset($this->amounts[$color->symbol()]);
        } else {
            $this->amounts[$color->symbol()] = $left;
        }
    }

    /** Dépense du mana restreint par `$kind` (l'appelant a vérifié que la restriction admet le paiement). */
    public function spendRestricted(string $kind, Color $color, int $n = 1): void
    {
        $have = $this->restricted[$kind][$color->symbol()] ?? 0;
        if ($have < $n) {
            throw new \DomainException("Mana restreint ($kind) insuffisant dans la réserve : " . $color->symbol());
        }
        if ($have === $n) {
            unset($this->restricted[$kind][$color->symbol()]);
            if ($this->restricted[$kind] === []) {
                unset($this->restricted[$kind]);
            }
        } else {
            $this->restricted[$kind][$color->symbol()] = $have - $n;
        }
    }

    /** Total, mana restreint compris. */
    public function total(): int
    {
        return array_sum($this->amounts) + array_sum(array_map('array_sum', $this->restricted));
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    public function clear(): void
    {
        $this->amounts = [];
        $this->restricted = [];
    }

    /**
     * Tout le mana par symbole (libre + restreint), ordre WUBRGC : ce qui est perdu au vidage.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        if ($this->restricted === []) {
            return $this->amounts;
        }
        $out = [];
        foreach (Color::cases() as $color) {
            $n = $this->get($color);
            foreach ($this->restricted as $amounts) {
                $n += $amounts[$color->symbol()] ?? 0;
            }
            if ($n > 0) {
                $out[$color->symbol()] = $n;
            }
        }

        return $out;
    }

    /** @return array<string, int> mana libre */
    public function toArray(): array
    {
        return $this->amounts;
    }

    /** @return array<string, array<string, int>> mana restreint, par restriction (vide si aucun) */
    public function restrictedToArray(): array
    {
        return $this->restricted;
    }

    /**
     * Champ `restricted` des payloads d'événements (valeur absolue), omis si rien n'est restreint.
     *
     * @return array{restricted?: array<string, array<string, int>>}
     */
    public function restrictedField(): array
    {
        return $this->restricted === [] ? [] : ['restricted' => $this->restricted];
    }

    /**
     * @param array<string, int>                $d          mana libre
     * @param array<string, array<string, int>> $restricted mana restreint
     */
    public static function fromArray(array $d, array $restricted = []): self
    {
        foreach (array_keys($restricted) as $kind) {
            new ManaRestriction($kind);
        }

        return new self(array_map('intval', $d), array_map(static fn (array $a) => array_map('intval', $a), $restricted));
    }
}
