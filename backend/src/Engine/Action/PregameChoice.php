<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Avant-partie, après les mulligans (Gemstone Caverns) : commencer la partie avec la carte `$sourceId` de la main sur le
 * champ de bataille, en exilant la carte `$cardId` de la main ; `$cardId` null = décliner.
 */
final class PregameChoice implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $sourceId,
        public readonly ?string $cardId,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('pregame:%d:%s:%s', $this->player, $this->sourceId, $this->cardId ?? 'no');
    }

    public function describe(GameState $state): string
    {
        $p = $state->player($this->player);
        $source = $p->findInHand($this->sourceId)?->name ?? $this->sourceId;

        return $this->cardId === null
            ? "Garder $source en main"
            : "Commencer avec $source en jeu, en exilant " . ($p->findInHand($this->cardId)?->name ?? $this->cardId);
    }

    public function toArray(): array
    {
        return ['type' => 'pregame', 'player' => $this->player, 'sourceId' => $this->sourceId, 'cardId' => $this->cardId];
    }
}
