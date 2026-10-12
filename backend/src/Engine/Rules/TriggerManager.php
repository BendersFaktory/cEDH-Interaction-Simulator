<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Action\Action;
use App\Engine\Action\PutTrigger;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameState;
use App\Engine\Game\PendingTrigger;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;

/**
 * Triggers. Appelé avant chaque octroi de priorité (après les SBA) : les triggers en attente rejoignent
 * la pile dans l'ordre APNAP (règle 603.3b) — le joueur actif pose tous les siens, puis chaque autre joueur dans le sens
 * du tour ; ceux du joueur actif sont donc en dessous et se résolvent en dernier.
 *
 * Choix d'ordre et de cible : comme la défausse, ce n'est PAS une priorité. Le contrôleur occupe `priority->holder` (le
 * serveur et le rejeu retrouvent l'action via LegalActionGenerator::for) et l'ancien détenteur est gardé dans
 * `GameState::$triggerResumeHolder`. Sans alternative (une seule action possible), le trigger est posé sans décision.
 */
final class TriggerManager
{
    private const MAX_TRIGGERS = 500;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LegalActionGenerator $legal,
    ) {
    }

    /**
     * Peut lever DecisionRequired (état intact, repris via $injected). Si au moins un trigger a été posé, la priorité
     * revient au joueur qui allait la recevoir et les passes sont remises à zéro (nouvel objet sur la pile).
     */
    public function putPendingOnStack(GameState $s, ?Action &$injected): void
    {
        // Les triggers d'un joueur éliminé quittent la partie avec lui (règle 800.4a).
        $s->pendingTriggers = array_values(array_filter($s->pendingTriggers, static fn (PendingTrigger $t) => !$s->player($t->controller)->lost));

        $put = false;
        for ($i = 0; $s->pendingTriggers !== []; $i++) {
            if ($i > self::MAX_TRIGGERS) {
                throw new \LogicException('Triggers sans fin (garde-fou).');
            }
            $controller = $this->nextController($s);
            $legal = $this->legal->triggerActions($s, $controller);

            if (count($legal) === 1) {
                $action = $legal[0];
            } else {
                if ($s->triggerResumeHolder === null) {
                    $s->triggerResumeHolder = $s->priority->holder;
                }
                $s->priority = new PriorityState($controller, [], true);
                if ($injected !== null) {
                    $action = $this->legal->match($injected, $controller, $legal);
                    $injected = null;
                } else {
                    $action = $this->agents->get($s->controllers[$controller])->decide($s, $controller, $legal);
                }
                if (!$action instanceof PutTrigger) {
                    throw new \LogicException('Un trigger doit être mis sur la pile : ' . $action::class);
                }
            }

            $this->put($s, $action);
            $put = true;
        }

        if ($s->triggerResumeHolder !== null) {
            $s->priority = new PriorityState($s->triggerResumeHolder);
            $s->triggerResumeHolder = null;
        } elseif ($put) {
            $s->priority->passed = [];
            $s->priority->announced = false;
        }
    }

    /** Premier joueur, à partir du joueur actif et dans le sens du tour, qui a des triggers en attente. */
    private function nextController(GameState $s): int
    {
        $ids = array_keys($s->players);
        $start = (int) array_search($s->activePlayer, $ids, true);
        for ($k = 0; $k < count($ids); $k++) {
            $id = $ids[($start + $k) % count($ids)];
            foreach ($s->pendingTriggers as $t) {
                if ($t->controller === $id) {
                    return $id;
                }
            }
        }

        throw new \LogicException('Aucun trigger en attente.');
    }

    private function put(GameState $s, PutTrigger $a): void
    {
        $pending = null;
        foreach ($s->pendingTriggers as $k => $t) {
            if ($t->id === $a->pendingId) {
                $pending = $t;
                array_splice($s->pendingTriggers, $k, 1);
                break;
            }
        }
        if ($pending === null) {
            throw new \LogicException("Trigger en attente introuvable : {$a->pendingId}");
        }

        $ability = $pending->ability();
        $item = new StackItem('s' . $s->nextStackId++, $pending->source, $pending->controller, $a->targets, 0, null, $pending->index, $ability);
        $s->stack[] = $item;
        $s->emit('AbilityTriggered', [
            'player' => $pending->controller,
            'stackId' => $item->id,
            'card' => $pending->source->toPublic(),
            'triggerIndex' => $pending->index,
            'trigger' => $ability->toArray(),
            'label' => $ability->label(),
            'targets' => $a->targets,
        ]);
    }
}
