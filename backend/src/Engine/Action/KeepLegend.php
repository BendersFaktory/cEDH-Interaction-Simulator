<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Règle des légendes (CR 704.5j) : le joueur garde ce permanent légendaire, les autres de même nom vont au cimetière.
 * Décision hors priorité, une action par permanent du groupe (voir StateBasedActions).
 */
final class KeepLegend implements Action
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
        return sprintf('legend:%d:%s', $this->player, $this->cardId);
    }

    public function describe(GameState $state): string
    {
        $card = $state->player($this->player)->findOnBattlefield($this->cardId);

        return 'Garder ' . ($card?->name ?? $this->cardId) . ' (' . $this->cardId . ')' . ($card?->tapped ? ' (engagé)' : '');
    }

    public function toArray(): array
    {
        return ['type' => 'keepLegend', 'player' => $this->player, 'cardId' => $this->cardId];
    }
}
