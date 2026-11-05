<?php

declare(strict_types=1);

namespace App\Service;

use App\Engine\Game\GameState;
use App\Engine\Snapshot\CheckpointListener;

/**
 * Ne retient qu'une ancre tous les $everyNTurns tours parmi les checkpoints du moteur ; les autres
 * se reconstruisent par rejeu déterministe (StateReconstructor).
 *
 * $anchoredTurn = tour déjà ancré au départ du run (reprise après décision ou fork : l'ancre de départ existe).
 * Null à la création : le premier checkpoint est ancré.
 */
final class SparseCheckpoints implements CheckpointListener
{
    /** @var array<int, array> eventSeq => état sérialisé */
    public array $states = [];

    public function __construct(private readonly int $everyNTurns = 1, private ?int $anchoredTurn = null,
        /** @var (callable(int): void)|null appelé quand le tour change (progression) */
        private readonly mixed $onTurn = null,
    ) {
    }

    private ?int $lastTurn = null;

    public function onCheckpoint(GameState $state): void
    {
        if ($this->onTurn !== null && $state->turn !== $this->lastTurn) {
            $this->lastTurn = $state->turn;
            ($this->onTurn)($state->turn);
        }
        if ($this->anchoredTurn !== null && $state->turn < $this->anchoredTurn + $this->everyNTurns) {
            return;
        }
        $this->anchoredTurn = $state->turn;
        $this->states[$state->eventSeq] = $state->toArray();
    }
}
