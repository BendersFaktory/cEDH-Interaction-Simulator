<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Avant-partie (mulligan de Londres, CR 103.5) : garder la main actuelle. Décision hors priorité. */
final class KeepHand implements Action
{
    public function __construct(private readonly int $player)
    {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('keep:%d', $this->player);
    }

    public function describe(GameState $state): string
    {
        $bottom = max(0, ($state->mulligan[$this->player]['taken'] ?? 0) - 1);

        return $bottom > 0 ? sprintf('Garder (puis %d carte(s) au fond)', $bottom) : 'Garder';
    }

    public function toArray(): array
    {
        return ['type' => 'keep', 'player' => $this->player];
    }
}
