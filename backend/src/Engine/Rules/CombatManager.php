<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Action\Action;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;

/**
 * Déclarations de combat. Ce ne sont PAS des priorités : le déclarant occupe `priority->holder`
 * (pour que le serveur et le rejeu retrouvent l'action via LegalActionGenerator::for) et est réinterrogé
 * après chaque choix jusqu'à FinishDeclaration. Les rondes de priorité des étapes de combat, elles,
 * restent complètes (PriorityManager), y compris après la déclaration.
 */
final class CombatManager
{
    private const MAX_DECLARATIONS = 2000;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LegalActionGenerator $legal,
        private readonly CombatResolver $resolver = new CombatResolver(),
    ) {
    }

    /** Étapes de combat sautées faute d'attaquants (comme en MTG). */
    public function skips(Phase $phase, GameState $s): bool
    {
        return match ($phase) {
            Phase::DeclareAttackers => $this->legal->attackActions($s, $s->activePlayer) === [],
            Phase::DeclareBlockers, Phase::CombatDamage => $s->combat->attackers === [],
            default => false,
        };
    }

    /** Effets d'entrée dans une étape de combat (appelé par TurnManager::beginStep, après PhaseChanged). */
    public function beginStep(GameState $s): void
    {
        switch ($s->phase) {
            case Phase::DeclareAttackers:
                $s->combat = new CombatState(CombatState::ATTACKERS);
                break;
            case Phase::DeclareBlockers:
                $s->combat->declaring = CombatState::BLOCKERS;
                $s->combat->queue = $this->defendersInTurnOrder($s);
                break;
            case Phase::CombatDamage:
                $this->resolver->resolve($s);
                break;
            case Phase::EndCombat:
                if ($s->combat->attackers !== []) {
                    $s->emit('CombatEnded');
                }
                $s->combat = new CombatState();
                break;
            default:
        }
    }

    /**
     * Joue la déclaration en cours jusqu'à son terme. Peut lever DecisionRequired (état intact, repris via $injected).
     * À la fin, la priorité est rendue au joueur actif pour la ronde de l'étape.
     */
    public function runDeclaration(GameState $s, ?Action &$injected): void
    {
        for ($i = 0; $i < self::MAX_DECLARATIONS; $i++) {
            $c = $s->combat;
            if ($c->declaring === null) {
                return;
            }

            $declarer = $this->currentDeclarer($s);
            if ($declarer === null) {
                $this->finish($s);

                return;
            }

            $s->priority = new PriorityState($declarer, [], true);
            $legal = $this->legal->for($s, $declarer);

            // Aucun choix réel (seulement « terminer ») : pas de décision à demander.
            if ($injected === null && count($legal) === 1) {
                $action = $legal[0];
            } elseif ($injected !== null) {
                $action = $this->legal->match($injected, $declarer, $legal);
                $injected = null;
            } else {
                $action = $this->agents->get($s->controllers[$declarer])->decide($s, $declarer, $legal);
            }

            if ($action instanceof DeclareAttacker) {
                $this->declareAttacker($s, $action);
            } elseif ($action instanceof DeclareBlocker) {
                $this->declareBlocker($s, $action);
            } elseif ($action instanceof FinishDeclaration) {
                if (count($legal) > 1) {
                    $s->emit('DeclarationFinished', ['player' => $declarer, 'step' => $c->declaring], verbose: true);
                }
                if ($c->declaring === CombatState::BLOCKERS) {
                    array_shift($c->queue);
                } else {
                    $this->finish($s);

                    return;
                }
            } else {
                throw new \LogicException('Action non gérée pendant la déclaration : ' . $action::class);
            }
        }

        throw new \LogicException('Déclaration de combat sans fin (garde-fou).');
    }

    private function currentDeclarer(GameState $s): ?int
    {
        $c = $s->combat;
        if ($c->declaring === CombatState::ATTACKERS) {
            return $s->activePlayer;
        }
        while ($c->queue !== [] && $s->player($c->queue[0])->lost) {
            array_shift($c->queue);
        }

        return $c->queue[0] ?? null;
    }

    private function finish(GameState $s): void
    {
        $s->combat->declaring = null;
        $s->combat->queue = [];
        $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
        $s->priority = new PriorityState($first);
    }

    private function declareAttacker(GameState $s, DeclareAttacker $a): void
    {
        $card = $this->find($s, $a->player(), $a->cardId);
        $card->tapped = true;
        $s->combat->attackers[$a->cardId] = $a->defender;
        $s->emit('AttackerDeclared', ['player' => $a->player(), 'card' => $a->cardId, 'defender' => $a->defender, 'tapped' => true]);
    }

    private function declareBlocker(GameState $s, DeclareBlocker $a): void
    {
        $s->combat->blockers[$a->attackerId][] = $a->cardId;
        $s->emit('BlockerDeclared', ['player' => $a->player(), 'blocker' => $a->cardId, 'attacker' => $a->attackerId]);
    }

    private function find(GameState $s, int $playerId, string $cardId): \App\Engine\Game\CardInstance
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }
        throw new \LogicException("Permanent introuvable : $cardId");
    }

    /**
     * Défenseurs attaqués et en vie, dans le sens du tour à partir du joueur suivant l'actif.
     *
     * @return list<int>
     */
    private function defendersInTurnOrder(GameState $s): array
    {
        $attacked = array_unique(array_values($s->combat->attackers));
        $order = [];
        $from = $s->activePlayer;
        for ($i = 0; $i < count($s->players); $i++) {
            $from = $s->nextAlive($from);
            if ($from !== $s->activePlayer && in_array($from, $attacked, true) && !in_array($from, $order, true)) {
                $order[] = $from;
            }
        }

        return $order;
    }
}
