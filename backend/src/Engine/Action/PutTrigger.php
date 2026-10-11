<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Met sur la pile une capacité déclenchée en attente (`GameState::$pendingTriggers`), avec sa cible éventuelle.
 * Décision hors priorité, comme la défausse : son contrôleur choisit un déclencheur à la fois, le premier choisi va
 * en dessous (ordre de résolution inverse).
 */
final class PutTrigger implements Action
{
    /** @param list<string> $targets ex: ['player:1'] */
    public function __construct(
        private readonly int $player,
        public readonly string $pendingId,
        public readonly array $targets = [],
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('trigger:%d:%s:%s', $this->player, $this->pendingId, implode(',', $this->targets));
    }

    public function describe(GameState $state): string
    {
        $label = $this->pendingId;
        foreach ($state->pendingTriggers as $pending) {
            if ($pending->id === $this->pendingId) {
                $label = $pending->source->name . ' (' . $pending->ability()->label() . ')';
            }
        }
        if ($this->targets !== []) {
            $label .= ' → joueur ' . substr($this->targets[0], 7);
        }

        return 'Mettre sur la pile : ' . $label;
    }

    public function toArray(): array
    {
        return ['type' => 'trigger', 'player' => $this->player, 'pendingId' => $this->pendingId, 'targets' => $this->targets];
    }
}
