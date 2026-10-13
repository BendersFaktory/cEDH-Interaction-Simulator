<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\DecisionRequired;
use App\Engine\Rules\CombatManager;
use App\Engine\Rules\HandSizeManager;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\PriorityManager;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use App\Engine\Rules\TriggerManager;
use App\Engine\Snapshot\CheckpointListener;

/**
 * Point d'entrée du moteur : advance(GameState, ?Action): AdvanceResult.
 *
 * Fait avancer la partie jusqu'à sa fin ou jusqu'à la prochaine décision d'un agent humain.
 * Le GameState est muté en place (cloner via toArray/fromArray AVANT si on veut garder l'ancien).
 */
final class GameEngine
{
    private const MAX_STEPS = 100_000;

    private readonly TurnManager $turns;
    private readonly PriorityManager $priority;
    private readonly CombatManager $combat;
    private readonly HandSizeManager $handSize;
    private readonly StateBasedActions $sba;

    public function __construct(
        AgentRegistry $agents,
        ?CheckpointListener $listener = null,
        ?LegalActionGenerator $legal = null,
    ) {
        $sba = $this->sba = new StateBasedActions();
        $legal ??= new LegalActionGenerator();
        $this->combat = new CombatManager($agents, $legal);
        $this->handSize = new HandSizeManager($agents, $legal);
        $this->turns = new TurnManager($sba, $this->combat);
        $this->priority = new PriorityManager(
            $agents,
            $legal,
            $sba,
            new StackResolver(),
            new TriggerManager($agents, $legal),
            $listener,
        );
    }

    public function advance(GameState $s, ?Action $decision = null): AdvanceResult
    {
        $injected = $decision;

        try {
            for ($step = 0; !$s->over; $step++) {
                if ($step > self::MAX_STEPS) {
                    throw new \LogicException('Garde-fou : trop d\'étapes dans advance().');
                }
                if (!$s->stepStarted) {
                    $this->turns->beginStep($s);
                }
                if ($s->phase->hasPriority()) {
                    if ($s->combat->declaring !== null) {
                        $this->combat->runDeclaration($s, $injected);
                    }
                    // Aucun attaquant déclaré : l'étape n'ouvre pas de ronde de priorité.
                    if (!($s->phase === Phase::DeclareAttackers && $s->combat->attackers === [])) {
                        $this->priority->runRound($s, $injected);
                    }
                }
                if ($s->phase === Phase::Cleanup && $this->cleanup($s, $injected)) {
                    continue; // nouvelle étape de cleanup (règle 514.3a)
                }
                if ($s->over) {
                    break;
                }
                $this->turns->endStep($s);
            }
        } catch (DecisionRequired $e) {
            return new AdvanceResult($s->drainEvents(), $e->request, false);
        }

        return new AdvanceResult($s->drainEvents(), null, true);
    }

    /**
     * Cleanup : défausse à 7, puis règle 514.3a — si des SBA agissent ou si des triggers attendent, une ronde de priorité
     * complète s'ouvre (octrois journalisés, checkpoints), puis une nouvelle étape de cleanup commence. `cleanupRound`
     * marque cette ronde : à la reprise après une décision, la défausse n'est pas rejouée. Peut lever DecisionRequired.
     *
     * @return bool true si une nouvelle étape de cleanup doit commencer
     */
    private function cleanup(GameState $s, ?Action &$injected): bool
    {
        if (!$s->cleanupRound) {
            $this->handSize->run($s, $injected);
            $seq = $s->eventSeq;
            $this->sba->check($s);
            if ($s->over || ($s->eventSeq === $seq && $s->pendingTriggers === [])) {
                return false;
            }
            $s->cleanupRound = true;
            $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
            $s->priority = new PriorityState($first);
        }

        $this->priority->runRound($s, $injected);
        $s->cleanupRound = false;
        if ($s->over) {
            return false;
        }
        $s->stepStarted = false;

        return true;
    }
}
