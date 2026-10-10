<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Déclaration d'un bloqueur sur un attaquant qui vise le joueur. Pas de pile. */
final class DeclareBlocker implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly string $attackerId,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('block:%d:%s:%s', $this->player, $this->cardId, $this->attackerId);
    }

    public function describe(GameState $state): string
    {
        return sprintf('Bloquer %s avec %s', $this->nameOf($state, $this->attackerId), $this->nameOf($state, $this->cardId));
    }

    private function nameOf(GameState $state, string $cardId): string
    {
        foreach ($state->players as $p) {
            foreach ($p->battlefield as $card) {
                if ($card->id === $cardId) {
                    return sprintf('%s (%s)', $card->name, $card->id);
                }
            }
        }

        return $cardId;
    }

    public function toArray(): array
    {
        return ['type' => 'block', 'player' => $this->player, 'cardId' => $this->cardId, 'attackerId' => $this->attackerId];
    }
}
