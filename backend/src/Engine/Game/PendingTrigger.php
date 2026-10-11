<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\TriggeredAbility;

/**
 * Trigger en attente d'être mis sur la pile (`GameState::$pendingTriggers`). `source` est la carte telle
 * qu'elle était au moment du trigger (information de dernière connaissance : une créature morte n'est plus sur le champ de
 * bataille, un jeton a cessé d'exister).
 */
final class PendingTrigger
{
    public function __construct(
        public readonly string $id,
        public readonly int $controller,
        public readonly CardInstance $source,
        public readonly int $index, // rang dans `$source->triggers`
    ) {
    }

    public function ability(): TriggeredAbility
    {
        return $this->source->triggers[$this->index];
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'controller' => $this->controller, 'source' => $this->source->toArray(), 'index' => $this->index];
    }

    public static function fromArray(array $d): self
    {
        return new self($d['id'], (int) $d['controller'], CardInstance::fromArray($d['source']), (int) $d['index']);
    }
}
