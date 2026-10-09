<?php

declare(strict_types=1);

namespace App\Engine\Snapshot;

use App\Engine\Game\GameState;

/** Collecte les checkpoints en mémoire (tests, smoke, et tampon avant persistance Doctrine). */
final class InMemoryCheckpoints implements CheckpointListener
{
    /** @var array<int, array> eventSeq => état sérialisé */
    public array $states = [];

    public function onCheckpoint(GameState $state): void
    {
        $this->states[$state->eventSeq] = $state->toArray();
    }
}
