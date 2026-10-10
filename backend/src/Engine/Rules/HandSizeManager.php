<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Action\Action;
use App\Engine\Action\Discard;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Game\PriorityState;

/**
 * Défausse du cleanup : le joueur actif défausse jusqu'à 7 cartes. Comme les déclarations de combat, ce n'est
 * PAS une priorité : le défausseur occupe `priority->holder` (le serveur et le rejeu retrouvent l'action via
 * LegalActionGenerator::for), et aucun état n'est ajouté à GameState (« main > 7 » est recalculé à la reprise).
 */
final class HandSizeManager
{
    private const MAX_DISCARDS = 200;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LegalActionGenerator $legal,
    ) {
    }

    /** Peut lever DecisionRequired (état intact, repris via $injected). */
    public function run(GameState $s, ?Action &$injected): void
    {
        $playerId = $s->activePlayer;
        for ($i = 0; $i < self::MAX_DISCARDS; $i++) {
            $p = $s->player($playerId);
            if ($p->lost || count($p->hand) <= PlayerState::MAX_HAND_SIZE) {
                return;
            }

            $s->priority = new PriorityState($playerId, [], true);
            $legal = $this->legal->for($s, $playerId);

            if ($injected !== null) {
                $action = $this->legal->match($injected, $playerId, $legal);
                $injected = null;
            } else {
                $action = $this->agents->get($s->controllers[$playerId])->decide($s, $playerId, $legal);
            }
            if (!$action instanceof Discard) {
                throw new \LogicException('Une défausse est obligatoire : ' . $action::class);
            }

            $s->discard($playerId, $action->cardId);
        }

        throw new \LogicException('Défausse sans fin (garde-fou).');
    }
}
