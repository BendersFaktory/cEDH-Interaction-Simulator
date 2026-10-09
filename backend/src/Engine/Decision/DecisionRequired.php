<?php

declare(strict_types=1);

namespace App\Engine\Decision;

/**
 * Levée par un agent « humain » pour suspendre la simulation.
 * IMPORTANT : un agent doit la lever AVANT toute mutation du GameState,
 * pour que l'état sauvegardé soit exactement le point de décision.
 */
final class DecisionRequired extends \RuntimeException
{
    public function __construct(public readonly DecisionRequest $request)
    {
        parent::__construct(sprintf('Décision requise du joueur %d', $request->player));
    }
}
