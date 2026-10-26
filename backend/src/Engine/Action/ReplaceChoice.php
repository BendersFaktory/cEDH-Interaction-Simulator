<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Effet de remplacement d'entrée (`GameState::$selection`, kind `replace`, Mox Diamond) : défausser la carte de terrain
 * `discardId` de sa main pour que le permanent arrive sur le champ de bataille, ou décliner (`discardId` null : il va au
 * cimetière). Une seule décision, hors priorité, comme Chain of Vapor.
 */
final class ReplaceChoice implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly ?string $discardId = null,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('replace:%d:%s', $this->player, $this->discardId ?? 'decline');
    }

    public function describe(GameState $state): string
    {
        $source = $state->selection['source'] ?? 'le permanent';
        if ($this->discardId === null) {
            return sprintf('Ne rien défausser (%s va au cimetière)', $source);
        }
        $land = $state->player($this->player)->findInHand($this->discardId)?->name ?? $this->discardId;

        return sprintf('Défausser %s (%s arrive sur le champ de bataille)', $land, $source);
    }

    public function toArray(): array
    {
        return ['type' => 'replace', 'player' => $this->player] + ($this->discardId === null ? [] : ['discardId' => $this->discardId]);
    }
}
