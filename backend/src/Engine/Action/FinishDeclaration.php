<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Termine la déclaration en cours (attaquants ou bloqueurs) du joueur. */
final class FinishDeclaration implements Action
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
        return sprintf('done:%d', $this->player);
    }

    public function describe(GameState $state): string
    {
        return $state->combat->declaring === 'blockers' ? 'Terminer les blocages' : 'Terminer les attaques';
    }

    public function toArray(): array
    {
        return ['type' => 'done', 'player' => $this->player];
    }
}
