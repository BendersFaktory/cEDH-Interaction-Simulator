<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\GameState;

/**
 * Emplacement réservé aux capacités déclenchées.
 * Appelé avant chaque octroi de priorité : les déclencheurs en attente rejoignent la pile
 * dans l'ordre APNAP (joueur actif d'abord, puis sens du tour).
 * TODO: file d'attente de déclencheurs dans GameState + ciblage.
 */
final class TriggerManager
{
    public function putPendingOnStack(GameState $s): void
    {
        // Rien pour l'instant.
    }
}
