<?php

declare(strict_types=1);

namespace App\Engine\Snapshot;

use App\Engine\Game\GameState;

/**
 * Notifié juste après chaque PriorityGranted : c'est le SEUL type de point où l'on peut forker.
 * L'état est alors cohérent (pas de résolution de pile à moitié faite).
 * Implémentation Symfony : persister toArray() dans l'entité Checkpoint.
 */
interface CheckpointListener
{
    public function onCheckpoint(GameState $state): void;
}
