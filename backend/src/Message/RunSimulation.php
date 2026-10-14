<?php

declare(strict_types=1);

namespace App\Message;

/** Demande de jouer une simulation créée en statut QUEUED. */
final class RunSimulation
{
    public function __construct(public readonly int $simulationId)
    {
    }
}
