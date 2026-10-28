<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Ponder (`GameState::$selection`, kind `arrange`) : remettre les cartes du dessus regardées dans l'ordre `order` (ids, dessus
 * en premier), ou mélanger la bibliothèque (`shuffle`, `order` vide). Une seule décision, hors priorité, comme le Scry.
 */
final class ArrangeTop implements Action
{
    /** @param list<string> $order ids des cartes du dessus, dessus en premier (vide si `shuffle`) */
    public function __construct(
        private readonly int $player,
        public readonly array $order,
        public readonly bool $shuffle = false,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('arrange:%d:%s', $this->player, $this->shuffle ? 'shuffle' : implode(',', $this->order));
    }

    public function describe(GameState $state): string
    {
        if ($this->shuffle) {
            return 'Mélanger la bibliothèque';
        }
        $names = [];
        foreach ($state->player($this->player)->library as $card) {
            $names[$card->id] = $card->name;
        }

        return 'Remettre dans cet ordre (dessus en premier) : ' . implode(', ', array_map(static fn (string $id) => $names[$id] ?? $id, $this->order));
    }

    public function toArray(): array
    {
        return ['type' => 'arrange', 'player' => $this->player, 'order' => $this->order, 'shuffle' => $this->shuffle];
    }
}
