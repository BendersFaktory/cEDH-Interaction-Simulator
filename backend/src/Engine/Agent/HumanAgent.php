<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\PassPriority;
use App\Engine\Decision\DecisionRequest;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Game\GameState;

/**
 * Agent « toi » : suspend la simulation pour demander une décision.
 *
 * Avec $autoPass = true, il répond « passer » tout seul tant qu'il n'a aucune action
 * utile (hors PassPriority). Le moteur déroule la ronde exactement pareil : seule la
 * réponse est automatique. TODO: stops configurables par phase / par type d'événement.
 */
final class HumanAgent implements PlayerAgent
{
    public function __construct(private readonly bool $autoPass = false)
    {
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if ($this->autoPass && !$this->hasUsefulAction($legal)) {
            return AutoPassAgent::pass($legal, $playerId);
        }

        throw new DecisionRequired(DecisionRequest::from($state, $playerId, $legal));
    }

    /** @param list<Action> $legal */
    private function hasUsefulAction(array $legal): bool
    {
        foreach ($legal as $action) {
            if (!$action instanceof PassPriority) {
                return true;
            }
        }

        return false;
    }
}
