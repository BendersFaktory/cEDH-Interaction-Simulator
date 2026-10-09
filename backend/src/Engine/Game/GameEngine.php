<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Action\Action;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\DecisionRequired;
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

    public function __construct(
        AgentRegistry $agents,
        ?CheckpointListener $listener = null,
        ?LegalActionGenerator $legal = null,
    ) {
        $sba = new StateBasedActions();
        $this->turns = new TurnManager($sba);
        $this->priority = new PriorityManager(
            $agents,
            $legal ?? new LegalActionGenerator(),
            $sba,
            new StackResolver(),
            new TriggerManager(),
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
                $this->priority->runRound($s, $injected);
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
}
