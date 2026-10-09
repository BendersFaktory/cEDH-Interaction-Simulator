<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Decision\DecisionRequest;
use App\Engine\Event\GameEvent;

final class AdvanceResult
{
    /** @param list<GameEvent> $events événements produits pendant CET appel d'advance() */
    public function __construct(
        public readonly array $events,
        public readonly ?DecisionRequest $pending,
        public readonly bool $over,
    ) {
    }
}
