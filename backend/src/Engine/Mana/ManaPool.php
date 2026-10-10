<?php

declare(strict_types=1);

namespace App\Engine\Mana;

/** Réserve de mana d'un joueur (vide hors paiement ; vidée à la fin de chaque étape). Indexée par `Color::symbol()`. */
final class ManaPool
{
    /** @param array<string, int> $amounts */
    public function __construct(private array $amounts = [])
    {
    }

    public function add(Color $color, int $n = 1): void
    {
        $this->amounts[$color->symbol()] = ($this->amounts[$color->symbol()] ?? 0) + $n;
    }

    public function get(Color $color): int
    {
        return $this->amounts[$color->symbol()] ?? 0;
    }

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

    public function total(): int
    {
        return array_sum($this->amounts);
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    public function clear(): void
    {
        $this->amounts = [];
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return $this->amounts;
    }

    /** @param array<string, int> $d */
    public static function fromArray(array $d): self
    {
        return new self(array_map('intval', $d));
    }
}
