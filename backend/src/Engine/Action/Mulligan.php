<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Avant-partie (mulligan de Londres, CR 103.5) : remettre la main dans la bibliothèque, mélanger et repiocher 7 cartes. */
final class Mulligan implements Action
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
        return sprintf('mulligan:%d', $this->player);
    }

    public function describe(GameState $state): string
    {
        // Le premier mulligan est gratuit en multijoueur (CR 103.5c) : le n-ième coûte n - 1 cartes au fond.
        $taken = $state->mulligan[$this->player]['taken'] ?? 0;

        return $taken === 0 ? 'Mulligan (gratuit)' : sprintf('Mulligan (%d carte(s) au fond)', $taken);
    }

    public function toArray(): array
    {
        return ['type' => 'mulligan', 'player' => $this->player];
    }
}
