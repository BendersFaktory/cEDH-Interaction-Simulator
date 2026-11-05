<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Brainstorm (`GameState::$selection`, kind `put_back`) : remettre une carte de sa main sur le dessus de sa bibliothèque.
 * Une décision par carte : la dernière remise est sur le dessus (« dans l'ordre de son choix »). Hors priorité, comme le Scry.
 */
final class PutBack implements Action
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
        return sprintf('putback:%d:%s', $this->player, $this->cardId);
    }

    public function describe(GameState $state): string
    {
        $name = $state->player($this->player)->findInHand($this->cardId)?->name ?? $this->cardId;
        $left = $state->selection['count'] ?? 1;

        return sprintf('Remettre %s sur le dessus de la bibliothèque%s', $name, $left > 1 ? sprintf(' (encore %d ensuite, au-dessus)', $left - 1) : '');
    }

    public function toArray(): array
    {
        return ['type' => 'putBack', 'player' => $this->player, 'cardId' => $this->cardId];
    }
}
