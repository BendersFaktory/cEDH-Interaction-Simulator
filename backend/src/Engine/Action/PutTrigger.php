<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Met sur la pile un trigger en attente (`GameState::$pendingTriggers`), avec sa cible éventuelle.
 * Décision hors priorité, comme la défausse : son contrôleur choisit un trigger à la fois, le premier choisi va
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
        if (str_starts_with($this->targets[0] ?? '', 'card:')) {
            $name = $state->player($this->player)->findInHand(substr($this->targets[0], 5))?->name ?? substr($this->targets[0], 5);
            $label .= ' → exiler ' . $name;
        } elseif (str_starts_with($this->targets[0] ?? '', 'library:')) {
            $id = substr($this->targets[0], 8);
            $found = null;
            foreach ($state->player($this->player)->library as $c) {
                if ($c->id === $id) {
                    $found = $c;
                }
            }
            $label .= $found === null ? ' → rien à chercher' : ' → ' . $found->name;
        } elseif ($this->targets !== []) {
            $label .= ' → joueur ' . substr($this->targets[0], 7);
        } elseif ($this->hasHandChoice($state)) {
            $label .= ' → ne rien exiler';
        }

        return 'Mettre sur la pile : ' . $label;
    }

    /** Le trigger en attente choisit-il une carte de la main (imprint) : sans cible, il décline. */
    private function hasHandChoice(GameState $state): bool
    {
        foreach ($state->pendingTriggers as $pending) {
            if ($pending->id === $this->pendingId) {
                return $pending->ability()->choosesHandCard();
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return ['type' => 'trigger', 'player' => $this->player, 'pendingId' => $this->pendingId, 'targets' => $this->targets];
    }
}
