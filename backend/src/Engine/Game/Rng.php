<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * RNG déterministe (xorshift32) dont l'état tient dans un entier :
 * il est sauvegardé dans les snapshots, ce qui rend les forks reproductibles.
 * Ne JAMAIS utiliser rand()/random_int()/shuffle() dans le moteur.
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 0x9E3779B9;
        }
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x;

        return $x;
    }

    /** Entier dans [$min, $max] inclus. */
    public function int(int $min, int $max): int
    {
        return $min + $this->next() % ($max - $min + 1);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    public function state(): int
    {
        return $this->state;
    }

    public static function fromState(int $state): self
    {
        return new self($state);
    }
}
