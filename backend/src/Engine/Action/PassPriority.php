<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

final class PassPriority implements Action
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
        return sprintf('pass:%d', $this->player);
    }

    public function describe(GameState $state): string
    {
        return 'Passer la priorité';
    }

    public function toArray(): array
    {
        return ['type' => 'pass', 'player' => $this->player];
    }
}
