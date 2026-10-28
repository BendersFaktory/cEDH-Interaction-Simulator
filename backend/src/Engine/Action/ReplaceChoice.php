<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Effet de remplacement d'entrée (`GameState::$selection`, kind `replace`, Mox Diamond) : défausser la carte de terrain
 * `discardId` de sa main pour que le permanent arrive sur le champ de bataille, ou décliner (`discardId` null : il va au
 * cimetière). Une seule décision, hors priorité, comme Chain of Vapor. Mockingbird : `copyId` = la créature à copier, ou
 * décliner (ni `discardId` ni `copyId` : elle arrive telle quelle).
 */
final class ReplaceChoice implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly ?string $discardId = null,
        public readonly ?string $copyId = null,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return $this->copyId !== null ? sprintf('replace:%d:copy=%s', $this->player, $this->copyId) : sprintf('replace:%d:%s', $this->player, $this->discardId ?? 'decline');
    }

    public function describe(GameState $state): string
    {
        $source = $state->selection['source'] ?? 'le permanent';
        if ($this->copyId !== null) {
            return sprintf('%s arrive comme une copie de %s', $source, $state->findPermanent($this->copyId)?->name ?? $this->copyId);
        }
        if (($state->selection['instead'] ?? null) === \App\Engine\Card\ReplacementEffect::COPY_CREATURE) {
            return sprintf('%s arrive telle quelle', $source);
        }
        if ($this->discardId === null) {
            return sprintf('Ne rien défausser (%s va au cimetière)', $source);
        }
        $land = $state->player($this->player)->findInHand($this->discardId)?->name ?? $this->discardId;

        return sprintf('Défausser %s (%s arrive sur le champ de bataille)', $land, $source);
    }

    public function toArray(): array
    {
        return ['type' => 'replace', 'player' => $this->player] + ($this->discardId === null ? [] : ['discardId' => $this->discardId])
            + ($this->copyId === null ? [] : ['copyId' => $this->copyId]);
    }
}
