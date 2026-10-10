<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Défausse obligatoire au cleanup (main de plus de 7 cartes). Décision hors priorité, une action par carte. */
final class Discard implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('discard:%d:%s', $this->player, $this->cardId);
    }

    public function describe(GameState $state): string
    {
        $card = $state->player($this->player)->findInHand($this->cardId);

        return 'Défausser ' . ($card?->name ?? $this->cardId);
    }

    public function toArray(): array
    {
        return ['type' => 'discard', 'player' => $this->player, 'cardId' => $this->cardId];
    }
}
