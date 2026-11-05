<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Après un mulligan, main gardée : mettre une carte de la main au fond de la bibliothèque (une action par carte). */
final class BottomCard implements Action
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
        return sprintf('bottom:%d:%s', $this->player, $this->cardId);
    }

    public function describe(GameState $state): string
    {
        $card = $state->player($this->player)->findInHand($this->cardId);

        return 'Mettre au fond ' . ($card?->name ?? $this->cardId);
    }

    public function toArray(): array
    {
        return ['type' => 'bottom', 'player' => $this->player, 'cardId' => $this->cardId];
    }
}
