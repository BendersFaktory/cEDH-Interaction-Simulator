<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Mana\ManaPayment;
use App\Engine\Snapshot\CheckpointListener;

/**
 * Boucle de priorité. RÈGLE D'OR : le moteur n'octroie JAMAIS la priorité « pour gagner du temps ».
 * Chaque joueur en vie est interrogé à chaque ronde, ce qui garantit qu'une IA peut contrer
 * un sort juste après un fork.
 */
final class PriorityManager
{
    private const MAX_ITERATIONS = 5000;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LegalActionGenerator $legal,
        private readonly StateBasedActions $sba,
        private readonly StackResolver $resolver,
        private readonly TriggerManager $triggers,
        private readonly ?CheckpointListener $listener = null,
    ) {
    }

    /**
     * Joue des octrois de priorité jusqu'à ce que la ronde se termine (tous ont passé, pile vide)
     * ou que la partie soit finie. Peut lever DecisionRequired (état intact, repris via $injected).
     *
     * @param Action|null $injected décision externe consommée au premier octroi
     */
    public function runRound(GameState $s, ?Action &$injected): void
    {
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $p = $s->priority;

            // SBA puis triggers en attente (y compris après une action spéciale comme jouer un terrain, ou à la reprise d'un
            // choix de légende, d'ordre ou de cible) : avant que quiconque reçoive la priorité.
            if (!$p->announced || $s->pendingTriggers !== [] || $s->sbaResume !== null) {
                $this->sba->check($s, $injected);
                if ($s->over) {
                    return;
                }
                $this->triggers->putPendingOnStack($s, $injected);
                $this->repairPriorityHolder($s);
            }

            $p = $s->priority;
            if (!$p->announced) {
                // Point de bifurcation : début de ronde (personne n'a encore passé) OU pile non vide
                // (c'est là qu'on veut pouvoir « juste avant ta réponse »). Les octrois intermédiaires
                // sur pile vide ne sont pas des checkpoints : ça divise le volume de snapshots par ~4.
                $checkpoint = $p->passed === [] || $s->stack !== [];
                $s->emit('PriorityGranted', [
                    'holder' => $p->holder,
                    'passed' => $p->passed,
                    'stackSize' => count($s->stack),
                    'checkpoint' => $checkpoint,
                ], verbose: true);
                $p->announced = true;
                if ($checkpoint) {
                    $this->listener?->onCheckpoint($s);
                }
            }

            $holder = $p->holder;
            $legal = $this->legal->for($s, $holder);

            if ($injected !== null) {
                $action = $this->legal->match($injected, $holder, $legal);
                $injected = null;
            } else {
                $action = $this->agents->get($s->controllers[$holder])->decide($s, $holder, $legal);
            }

            if ($action instanceof PassPriority) {
                if ($this->handlePass($s, $holder)) {
                    return;
                }
                continue;
            }

            if ($action instanceof PlayLand) {
                $this->playLand($s, $action);
                continue; // action spéciale : le joueur garde la priorité, pas de nouvelle ronde
            }

            if ($action instanceof ActivateAbility) {
                AbilityManager::activate($s, $action);
                continue; // capacité de mana : le joueur garde la priorité ; autre capacité : sur la pile (remise à zéro des passes)
            }

            if ($action instanceof CastSpell) {
                $this->cast($s, $action);
                continue;
            }

            throw new \LogicException('Action non gérée : ' . $action::class);
        }

        throw new \LogicException('Boucle de priorité sans fin (garde-fou).');
    }

    /** @return bool true si la ronde est terminée (étape suivante) */
    private function handlePass(GameState $s, int $holder): bool
    {
        $p = $s->priority;
        if (!in_array($holder, $p->passed, true)) {
            $p->passed[] = $holder;
        }
        $s->emit('PriorityPassed', ['player' => $holder], verbose: true);

        if (count($p->passed) >= count($s->alivePlayerIds())) {
            if ($s->stack === []) {
                return true;
            }
            $this->resolver->resolveTop($s);
            // Après une résolution : nouvelle ronde, le joueur actif (ou le suivant s'il est mort) commence.
            $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
            $s->priority = new PriorityState($first);

            return false;
        }

        $p->holder = $s->nextAlive($holder);
        $p->announced = false;

        return false;
    }

    private function playLand(GameState $s, PlayLand $a): void
    {
        $p = $s->player($a->player());
        $card = $p->takeFromHand($a->cardId) ?? throw new IllegalActionException('Terrain absent de la main');
        $s->landsPlayed++;
        $s->putLandOntoBattlefield($a->player(), $card, $a->payLife, 'LandPlayed');
    }

    private function cast(GameState $s, CastSpell $a): void
    {
        $p = $s->player($a->player());
        $card = $p->findCastable($a->cardId) ?? throw new IllegalActionException('Carte absente de la main et de la zone de commandement');

        // Coût calculé AVANT d'incrémenter le compteur de lancements (taxe de commandant).
        $cost = $p->castCost($card);
        if ($a->x < 0 || ($a->x > 0 && !$cost->hasX())) {
            throw new IllegalActionException('X invalide pour ce sort');
        }
        $plan = ManaPayment::plan($p, $cost, $a->x, $s->turn, null, $card) ?? throw new IllegalActionException('Mana insuffisant pour ce sort');

        $card = $p->takeFromHand($a->cardId);
        $fromCommand = false;
        if ($card === null) {
            $card = $p->takeFromCommand($a->cardId);
            $fromCommand = true;
        }
        ['tapped' => $tapped, 'paid' => $paid, 'fromPool' => $fromPool, 'sacrifice' => $sacrifice, 'pain' => $pain] = $p->payWithPlan($plan);
        if ($plan->lifeCost > 0) {
            $pain[] = ['source' => $card->name, 'lifeCost' => $plan->lifeCost, 'selfDamage' => 0]; // symboles phyrexian payés en PV
        }
        if ($fromCommand) {
            $p->commanderCasts[$card->id] = ($p->commanderCasts[$card->id] ?? 0) + 1;
        }

        $item = new \App\Engine\Game\StackItem('s' . $s->nextStackId++, $card, $a->player(), $a->targets, $a->x);
        $s->stack[] = $item;

        $s->emit('SpellCast', [
            'player' => $a->player(),
            'card' => $card->toPublic(),
            'stackId' => $item->id,
            'targets' => $a->targets,
            'tapped' => $tapped,
            'cost' => $cost->manaValue($a->x),
            'manaCost' => (string) $cost,
            'x' => $a->x,
            'paid' => $paid,
            'fromPool' => $fromPool,
            'pool' => $p->pool->toArray(),
        ] + $p->pool->restrictedField() + [
            'fromCommand' => $fromCommand,
        ]);
        $s->sacrifice($a->player(), $sacrifice); // Treasures engagés pour le coût
        $s->hurtByMana($a->player(), $pain);     // Ancient Tomb, Talismans, Mana Confluence
        // « Quand vous lancez un sort » : posés au-dessus du sort (avant la priorité du lanceur), donc résolus avant lui.
        $s->queuePlayerTriggers($a->player(), TriggeredAbility::CAST, $card);

        // Un sort sur la pile remet les passes à zéro : TOUS les autres joueurs (IA comprise) doivent repasser.
        $s->priority->passed = [];
        $s->priority->announced = false; // le lanceur reçoit la priorité en premier
    }

    /** Si le détenteur a été éliminé pendant la ronde, la priorité passe au suivant. */
    private function repairPriorityHolder(GameState $s): void
    {
        $p = $s->priority;
        $alive = $s->alivePlayerIds();
        $p->passed = array_values(array_filter($p->passed, static fn (int $id) => in_array($id, $alive, true)));
        if ($s->player($p->holder)->lost) {
            $p->holder = $s->nextAlive($p->holder);
        }
    }
}
