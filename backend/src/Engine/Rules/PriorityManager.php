<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\ArrangeTop;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ChainCopy;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PayCost;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutBack;
use App\Engine\Action\ScryArrange;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Mana\ManaCost;
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
            // choix de légende, d'ordre ou de cible) : avant que quiconque reçoive la priorité. Jamais au milieu d'une sélection
            // de cartes (Brainstorm, Ponder) : la résolution n'est pas finie.
            if ($s->selection === null && (!$p->announced || $s->pendingTriggers !== [] || $s->sbaResume !== null)) {
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

            if ($action instanceof PayCost) {
                $s->paymentPending = false;
                $this->resolveStack($s, $this->pay($s, $action->player(), $action->pay));
                continue;
            }

            if ($action instanceof PutBack) {
                $this->putBack($s, $action);
                continue;
            }

            if ($action instanceof ArrangeTop) {
                $this->arrangeTop($s, $action);
                continue;
            }

            if ($action instanceof ChainCopy) {
                $this->chainCopy($s, $action);
                continue;
            }

            if ($action instanceof ScryArrange) {
                $s->scryPending = false;
                $this->scry($s, $action);
                $this->resolveStack($s);
                continue;
            }

            throw new \LogicException('Action non gérée : ' . $action::class);
        }

        throw new \LogicException('Boucle de priorité sans fin (garde-fou).');
    }

    /** Le sommet de pile est un sort avec Scry et son contrôleur a de quoi ranger (sinon rien à décider). */
    private function scryDue(GameState $s): bool
    {
        $top = $s->stack[array_key_last($s->stack)];

        return !$top->isAbility() && $top->card->effect('scry') !== null && $s->player($top->controller)->library !== [];
    }

    /**
     * Scry (CR 701.22) : les cartes gardées vont sur le dessus, dans l'ordre choisi ; les autres sous la bibliothèque.
     * Émet `Scried` (nombres seulement : le contenu du dessus n'est connu que du contrôleur).
     */
    private function scry(GameState $s, ScryArrange $a): void
    {
        $p = $s->player($a->player());
        $top = array_slice($p->library, 0, $a->count);
        $rest = array_slice($p->library, $a->count);
        $kept = [];
        foreach ($a->kept as $id) {
            foreach ($top as $card) {
                if ($card->id === $id) {
                    $kept[] = $card;
                }
            }
        }
        $bottom = array_values(array_filter($top, static fn (CardInstance $c) => !in_array($c->id, $a->kept, true)));
        $p->library = [...$kept, ...$rest, ...$bottom];
        $s->emit('Scried', ['player' => $a->player(), 'top' => count($kept), 'bottom' => count($bottom), 'library' => count($p->library)]);
    }

    /**
     * Paiement demandé par le sommet de pile (`StackResolver::paymentDue()`) : payé par auto-paiement (`CostPaid`, sources
     * engagées, Treasures sacrifiés, blessures des terrains) si `$pay` et payable ; sinon `CostNotPaid`. Retourne s'il est payé.
     */
    private function pay(GameState $s, int $player, bool $pay): bool
    {
        $due = StackResolver::paymentDue($s) ?? throw new \LogicException('Aucun paiement demandé');
        $p = $s->player($player);
        $cost = ManaCost::parse($due['cost']);
        $plan = $pay ? ManaPayment::plan($p, $cost, 0, $s->turn) : null;
        $stackId = $s->topOfStack()?->id;
        if ($plan === null) {
            $s->emit('CostNotPaid', ['player' => $player, 'cost' => $due['cost'], 'source' => $due['source'], 'stackId' => $stackId]);

            return false;
        }
        ['tapped' => $tapped, 'paid' => $paid, 'fromPool' => $fromPool, 'sacrifice' => $sacrifice, 'pain' => $pain] = $p->payWithPlan($plan);
        if ($plan->lifeCost > 0) {
            $pain[] = ['source' => $due['source'], 'lifeCost' => $plan->lifeCost, 'selfDamage' => 0];
        }
        $s->emit('CostPaid', [
            'player' => $player,
            'cost' => $due['cost'],
            'source' => $due['source'],
            'stackId' => $stackId,
            'tapped' => $tapped,
            'paid' => $paid,
            'fromPool' => $fromPool,
            'pool' => $p->pool->toArray(),
        ] + $p->pool->restrictedField());
        $s->sacrifice($player, $sacrifice);
        $s->hurtByMana($player, $pain);

        return true;
    }

    /**
     * Résolution du sommet de pile, puis nouvelle ronde : le joueur actif (ou le suivant s'il est mort) commence. Si la
     * résolution ouvre une sélection de cartes (Brainstorm, Ponder), son joueur décide d'abord (hors priorité, sans checkpoint).
     */
    private function resolveStack(GameState $s, ?bool $paid = null): void
    {
        $this->resolver->resolveTop($s, $paid);
        if ($s->selection !== null) {
            $s->priority = new PriorityState($s->selection['player'], [], true);

            return;
        }
        $this->newRoundAfterResolution($s);
    }

    private function newRoundAfterResolution(GameState $s): void
    {
        $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
        $s->priority = new PriorityState($first);
    }

    /**
     * Brainstorm : la carte choisie passe de la main au dessus de la bibliothèque (`CardPutOnTop`) ; la dernière remise finit
     * la sélection.
     */
    private function putBack(GameState $s, PutBack $a): void
    {
        $sel = $s->selection ?? throw new \LogicException('Aucune sélection en cours');
        $p = $s->player($a->player());
        $card = $p->takeFromHand($a->cardId) ?? throw new IllegalActionException('Carte absente de la main');
        array_unshift($p->library, $card);
        $s->emit('CardPutOnTop', ['player' => $a->player(), 'card' => $card->toPublic(), 'hand' => count($p->hand), 'library' => count($p->library), 'source' => $sel['source']]);
        $s->selection['count']--;
        if ($s->selection['count'] <= 0) {
            $this->finishSelection($s);
        }
    }

    /**
     * Ponder : les cartes regardées reviennent sur le dessus dans l'ordre choisi (`Scried`, toutes dessus : seuls les nombres
     * sont publics), ou la bibliothèque est mélangée par `GameState::$rng` (`LibraryShuffled`). Puis la sélection se termine.
     */
    private function arrangeTop(GameState $s, ArrangeTop $a): void
    {
        $sel = $s->selection ?? throw new \LogicException('Aucune sélection en cours');
        $p = $s->player($a->player());
        if ($a->shuffle) {
            $p->library = $s->rng->shuffle($p->library);
            $s->emit('LibraryShuffled', ['player' => $a->player(), 'library' => count($p->library)]);
        } else {
            $top = array_slice($p->library, 0, $sel['count']);
            $byId = [];
            foreach ($top as $card) {
                $byId[$card->id] = $card;
            }
            $ordered = array_map(static fn (string $id) => $byId[$id] ?? throw new IllegalActionException("Carte $id hors des cartes regardées"), $a->order);
            if (count($ordered) !== count($top)) {
                throw new IllegalActionException('Toutes les cartes regardées doivent être remises');
            }
            $p->library = [...$ordered, ...array_slice($p->library, $sel['count'])];
            $s->emit('Scried', ['player' => $a->player(), 'top' => count($ordered), 'bottom' => 0, 'library' => count($p->library)]);
        }
        $this->finishSelection($s);
    }

    /**
     * Chain of Vapor : le joueur décline, ou sacrifie un terrain qu'il contrôle et copie le sort (CR 707.10) ; la copie, contrôlée
     * par lui, vise le permanent non-terrain choisi (`SpellCopied`). Puis la sélection se termine.
     */
    private function chainCopy(GameState $s, ChainCopy $a): void
    {
        $sel = $s->selection ?? throw new \LogicException('Aucune sélection en cours');
        if ($a->landId !== null) {
            $p = $s->player($a->player());
            if (!($p->findOnBattlefield($a->landId)?->isLand() ?? false)) {
                throw new IllegalActionException('Terrain à sacrifier introuvable');
            }
            $s->sacrifice($a->player(), [$a->landId]);
            $source = $sel['card'] ?? throw new \LogicException('Sort à copier absent de la sélection');
            $card = CardInstance::fromArray($source);
            $item = new \App\Engine\Game\StackItem('s' . $s->nextStackId++, $card, $a->player(), [(string) $a->target], copy: true);
            $s->stack[] = $item;
            $s->emit('SpellCopied', ['player' => $a->player(), 'card' => $card->toPublic(), 'stackId' => $item->id, 'targets' => $item->targets, 'source' => $sel['source']]);
        }
        $this->finishSelection($s);
    }

    /** Fin de la sélection : les pioches qui suivaient (Ponder) se font, puis nouvelle ronde comme après une résolution. */
    private function finishSelection(GameState $s): void
    {
        $sel = $s->selection;
        $s->selection = null;
        for ($i = 0; $i < ($sel['draw'] ?? 0); $i++) {
            if (!$s->drawCard($sel['player'])) {
                break;
            }
        }
        $this->newRoundAfterResolution($s);
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
            if ($this->scryDue($s)) {
                // Scry : le contrôleur du sort range le dessus de sa bibliothèque avant la résolution (décision hors priorité).
                $s->scryPending = true;
                $s->priority = new PriorityState($s->stack[array_key_last($s->stack)]->controller, [], true);

                return false;
            }
            $due = StackResolver::paymentDue($s);
            if ($due !== null) {
                if (ManaPayment::plan($s->player($due['player']), ManaCost::parse($due['cost']), 0, $s->turn) === null) {
                    // Impayable : pas de décision (un seul choix possible, comme un trigger sans alternative).
                    $this->resolveStack($s, $this->pay($s, $due['player'], false));

                    return false;
                }
                // Le payeur décide avant la résolution (hors priorité, comme le Scry).
                $s->paymentPending = true;
                $s->priority = new PriorityState($due['player'], [], true);

                return false;
            }
            $this->resolveStack($s);

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
        $s->queueLandPlayedTriggers($a->player(), $card); // City of Traitors
    }

    private function cast(GameState $s, CastSpell $a): void
    {
        $p = $s->player($a->player());
        $card = $p->findCastable($a->cardId) ?? throw new IllegalActionException('Carte absente de la main et de la zone de commandement');
        if ($card->isModal() ? !in_array($a->mode, $card->modes(), true) : $a->mode !== 0) {
            throw new IllegalActionException('Mode invalide pour ce sort');
        }
        if (!$s->canCast($a->player(), $card, $a->x)) {
            throw new IllegalActionException('Une interdiction vous empêche de lancer ce sort');
        }
        $sacrificed = null; // coût additionnel : créature sacrifiée (Culling the Weak, Diabolic Intent)
        if ($card->extraCost === CardInstance::EXTRA_SACRIFICE_CREATURE) {
            $sacrificed = $a->sacrifice !== null ? $p->findOnBattlefield($a->sacrifice) : null;
            if ($sacrificed === null || !$sacrificed->isCreature()) {
                throw new IllegalActionException('Créature à sacrifier introuvable');
            }
        } elseif ($a->sacrifice !== null) {
            throw new IllegalActionException('Ce sort n\'a pas de coût de sacrifice');
        }

        // Coût calculé AVANT d'incrémenter le compteur de lancements (taxe de commandant).
        $cost = $p->castCost($card);
        if ($a->x < 0 || ($a->x > 0 && !$cost->hasX())) {
            throw new IllegalActionException('X invalide pour ce sort');
        }
        if ($a->kicked) {
            if ($card->kicker === null || $a->alt !== null) {
                throw new IllegalActionException('Kicker indisponible');
            }
            $cost = ManaCost::parse((string) $cost . $card->kicker); // Orim's Chant : {W} de plus
        }
        $plan = null; // coût alternatif : rien à payer en mana
        if ($a->alt === null) {
            $plan = ManaPayment::plan($p, $cost, $a->x, $s->turn, null, $card) ?? throw new IllegalActionException('Mana insuffisant pour ce sort');
        } elseif ($a->x !== 0) {
            throw new IllegalActionException('Coût alternatif sans X');
        }
        $pitch = null;
        $pitchLife = 0;
        $returned = [];
        if ($a->alt === CastSpell::ALT_FREE) {
            if (!(($card->altCost === CardInstance::ALT_COMMANDER && $p->controlsCommander())
                || ($card->altCost === CardInstance::ALT_OPPONENT_THREE_SPELLS && self::opponentCastThree($s, $a->player())))) {
                throw new IllegalActionException('Coût alternatif gratuit indisponible');
            }
        } elseif ($a->alt !== null && str_starts_with($a->alt, CastSpell::ALT_RETURN)) {
            // Daze, Gush : renvoyer N Îles distinctes que l'on contrôle.
            $ids = explode(',', substr($a->alt, strlen(CastSpell::ALT_RETURN)));
            foreach ($ids as $id) {
                $island = $p->findOnBattlefield($id);
                if ($island === null || !$island->isIsland()) {
                    throw new IllegalActionException('Île à renvoyer introuvable');
                }
            }
            if ($card->islandsToReturn() === 0 || count(array_unique($ids)) !== $card->islandsToReturn() || count($ids) !== $card->islandsToReturn()) {
                throw new IllegalActionException('Coût alternatif par renvoi d\'Îles indisponible');
            }
            $returned = $ids;
        } elseif ($a->alt !== null) {
            // Exil de cartes bleues de la main : une (Force of Will, Misdirection…) ou deux (Commandeer).
            $ids = str_starts_with($a->alt, CastSpell::ALT_PITCH) ? explode(',', substr($a->alt, strlen(CastSpell::ALT_PITCH))) : [];
            $pitch = [];
            foreach (array_unique($ids) as $id) {
                $blue = $p->findHand($id);
                if ($blue !== null && $blue->isBlue() && $blue->id !== $card->id) {
                    $pitch[] = $blue;
                }
            }
            $pitchLife = $card->altCost === CardInstance::ALT_PITCH_BLUE ? 1 : 0;
            $wanted = $card->altCost === CardInstance::ALT_PITCH_TWO_BLUE ? 2 : 1;
            $allowed = $card->altCost === CardInstance::ALT_PITCH_BLUE || $card->altCost === CardInstance::ALT_PITCH_BLUE_FREE || $card->altCost === CardInstance::ALT_PITCH_TWO_BLUE
                || ($card->altCost === CardInstance::ALT_PITCH_BLUE_OFF_TURN && $s->activePlayer !== $a->player()); // Force of Negation
            if (!$allowed || $p->life < $pitchLife || count($pitch) !== $wanted || count($ids) !== $wanted) {
                throw new IllegalActionException('Coût alternatif par exil indisponible');
            }
        }

        $card = $p->takeFromHand($a->cardId);
        $fromCommand = false;
        if ($card === null) {
            $card = $p->takeFromCommand($a->cardId);
            $fromCommand = true;
        }
        ['tapped' => $tapped, 'paid' => $paid, 'fromPool' => $fromPool, 'sacrifice' => $sacrifice, 'pain' => $pain] = $plan === null
            ? ['tapped' => [], 'paid' => [], 'fromPool' => [], 'sacrifice' => [], 'pain' => []]
            : $p->payWithPlan($plan);
        if ($pitchLife > 0) {
            $s->payLife($a->player(), $pitchLife, $card->name);
        }
        foreach ($pitch ?? [] as $blue) {
            $p->takeFromHand($blue->id);
            $s->exileFromHand($a->player(), $blue, $card->name);
        }
        foreach ($returned as $id) {
            $s->returnToHand($a->player(), $id, $card->name);
        }
        if ($plan !== null && $plan->lifeCost > 0) {
            $pain[] = ['source' => $card->name, 'lifeCost' => $plan->lifeCost, 'selfDamage' => 0]; // symboles phyrexian payés en PV
        }
        if ($fromCommand) {
            $p->commanderCasts[$card->id] = ($p->commanderCasts[$card->id] ?? 0) + 1;
        }

        $item = new \App\Engine\Game\StackItem('s' . $s->nextStackId++, $card, $a->player(), $a->targets, $a->x, mode: $a->mode, kicked: $a->kicked);
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
        ] + ($a->mode > 0 ? ['mode' => $a->mode] : []) + ($a->kicked ? ['kicked' => true] : []));
        if ($sacrificed !== null && !in_array($sacrificed->id, $sacrifice, true)) {
            $sacrifice[] = $sacrificed->id;
        }
        $s->sacrifice($a->player(), $sacrifice); // Treasures engagés pour le coût, créature sacrifiée en coût additionnel
        $s->hurtByMana($a->player(), $pain);     // Ancient Tomb, Talismans, Mana Confluence
        // « Quand vous lancez un sort » : posés au-dessus du sort (avant la priorité du lanceur), donc résolus avant lui.
        $s->queuePlayerTriggers($a->player(), TriggeredAbility::CAST, $card);
        $s->spellsCast[$a->player()] = ($s->spellsCast[$a->player()] ?? 0) + 1;
        if ($card->hasKeyword(CardInstance::STORM)) {
            // Storm (CR 702.40) : trigger du sort lui-même, une copie par sort lancé avant lui ce tour (tous joueurs confondus).
            $count = array_sum($s->spellsCast) - 1;
            if ($count > 0) {
                $storm = new TriggeredAbility(TriggeredAbility::CAST, 'storm', $count, StackResolver::stormSpec($item));
                $s->pendingTriggers[] = new \App\Engine\Game\PendingTrigger('tr' . $s->nextTriggerId++, $a->player(), clone $card, 0, $storm);
            }
        }
        // Lavinia : « si aucun mana n'a été dépensé pour le lancer » (coût alternatif, ou coût de mana nul).
        $s->queueOpponentCastTriggers($a->player(), $card, $item->id, $paid !== [] || $fromPool !== []); // Rhystic Study, Kraum, Lavinia

        // Un sort sur la pile remet les passes à zéro : TOUS les autres joueurs (IA comprise) doivent repasser.
        $s->priority->passed = [];
        $s->priority->announced = false; // le lanceur reçoit la priorité en premier
    }

    /** Un adversaire de `$player` a-t-il lancé trois sorts ou plus ce tour (Mindbreak Trap) ? */
    public static function opponentCastThree(GameState $s, int $player): bool
    {
        foreach ($s->spellsCast as $id => $count) {
            if ($id !== $player && $count >= 3) {
                return true;
            }
        }

        return false;
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
