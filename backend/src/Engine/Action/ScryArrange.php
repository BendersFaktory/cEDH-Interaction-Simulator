<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Scry (CR 701.22) : le contrôleur du sort range les `count` cartes du dessus de sa bibliothèque. `kept` = celles qu'il garde
 * sur le dessus, dans l'ordre (dessus en premier) ; les autres vont sous la bibliothèque. Une seule décision par Scry, avant
 * la résolution du sort (`GameState::$scryPending`).
 */
final class ScryArrange implements Action
{
    /** @param list<string> $kept ids des cartes gardées sur le dessus, dessus en premier */
    public function __construct(
        private readonly int $player,
        public readonly array $kept,
        public readonly int $count,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('scry:%d:%d:%s', $this->player, $this->count, implode(',', $this->kept));
    }

    public function describe(GameState $state): string
    {
        $library = array_slice($state->player($this->player)->library, 0, $this->count);
        $names = [];
        foreach ($library as $card) {
            $names[$card->id] = $card->name;
        }
        $top = implode(', ', array_map(static fn (string $id) => $names[$id] ?? $id, $this->kept));
        $bottom = $this->count - count($this->kept);

        return sprintf('Scry %d : dessus = %s ; dessous : %d carte(s)', $this->count, $top === '' ? 'aucune' : $top, $bottom);
    }

    public function toArray(): array
    {
        return ['type' => 'scry', 'player' => $this->player, 'kept' => $this->kept, 'count' => $this->count];
    }
}
