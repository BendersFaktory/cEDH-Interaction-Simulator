<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\PassPriority;
use App\Engine\Decision\DecisionRequest;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameState;

/**
 * Agent « toi » : suspend la simulation pour demander une décision.
 *
 * Avec $autoPass = true, il répond « passer » tout seul quand il n'a pas de raison de s'arrêter. Le moteur déroule la
 * ronde exactement pareil (les PriorityPassed restent journalisés) : seule la réponse est automatique.
 *
 * Raisons de s'arrêter :
 * - sans stops configurés (`GameState::$stops[$playerId]` absent) : dès qu'une action utile existe (hors passer et
 *   capacités de mana, dont l'auto-paiement se charge) ;
 * - avec des stops : voir la sémantique de StopConfig (étapes cochées, sort adverse sur la pile, déclarations de
 *   combat) ; une décision obligatoire (défausse, trigger à poser, légende à garder, mulligan) arrête toujours. Un mulligan n'est jamais
 *   tranché tout seul : seul PassPriority est répondu automatiquement.
 */
final class HumanAgent implements PlayerAgent
{
    public function __construct(private readonly bool $autoPass = false)
    {
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if ($this->autoPass && !$this->shouldStop($state, $playerId, $legal)) {
            return AutoPassAgent::pass($legal, $playerId);
        }

        throw new DecisionRequired(DecisionRequest::from($state, $playerId, $legal));
    }

    /** @param list<Action> $legal */
    private function shouldStop(GameState $state, int $playerId, array $legal): bool
    {
        $stops = $state->stops[$playerId] ?? null;
        if ($stops === null) {
            return $this->hasUsefulAction($state, $legal);
        }

        $canPass = false;
        $declaration = false;
        foreach ($legal as $action) {
            $canPass = $canPass || $action instanceof PassPriority;
            $declaration = $declaration || $action instanceof FinishDeclaration;
        }

        if ($canPass) {
            return $this->hasUsefulAction($state, $legal)
                && ($stops->stopsAt($state->phase) || ($stops->onOpponentSpell && $this->opponentObjectOnStack($state, $playerId)));
        }
        if ($declaration) {
            return $state->combat->declaring === CombatState::BLOCKERS ? $stops->onDeclareBlockers : $stops->onDeclareAttackers;
        }

        // Défausse, trigger à poser, légende à garder, mulligan (garder / mulligan / carte au fond) : la décision n'est pas optionnelle.
        return true;
    }

    private function opponentObjectOnStack(GameState $state, int $playerId): bool
    {
        foreach ($state->stack as $item) {
            if ($item->controller !== $playerId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Action> $legal */
    private function hasUsefulAction(GameState $state, array $legal): bool
    {
        foreach ($legal as $action) {
            // Passer et activer du mana à la main ne sont pas des raisons de s'arrêter (l'auto-paiement s'en charge).
            if ($action instanceof PassPriority || ($action instanceof ActivateAbility && $action->isManaAbility($state))) {
                continue;
            }

            return true;
        }

        return false;
    }
}
