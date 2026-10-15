<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\BottomCard;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\Discard;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\KeepHand;
use App\Engine\Action\Mulligan;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutTrigger;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;

/**
 * IA de départ, volontairement simple :
 *  1. contre le sort adverse au dessus de la pile s'il le peut ;
 *  2. joue un terrain ;
 *  3. à son tour, en phase principale, pile vide : lance un sort au hasard (jamais sur soi-même) ;
 *  4. sinon passe.
 * Triggers : posés dans l'ordre de la file ; une cible de dégâts est l'adversaire vivant le plus faible.
 * Garder de quoi contrer : avec un contresort payable en main, ne lance (sort ou capacité à coût de mana) que ce qui laisse
 * assez de sources non engagées pour le payer (voir `keepCounterMana`).
 * Défausse (main > 7), par ordre : terrain en surplus, carte injouable (couleur introuvable ou coût hors de portée), carte la
 * plus chère ; on garde en dernier les contresorts puis les terrains dont on a besoin (voir `discard`).
 * Mulligan (tous profils) : garde une main de 2 à 5 terrains, sinon prend un mulligan tant qu'il en a pris moins de 2,
 * sinon garde ; les cartes à mettre au fond sont choisies comme une défausse (voir `discard`).
 * Combat : attaque avec toutes ses créatures sur l'adversaire qu'elle peut tuer, sinon le plus menaçant (sauf le commandant s'il peut mourir, et une
 * garde si un adversaire peut me tuer) ; bloque quand le bloqueur survit ou tue l'attaquant, bloque à deux quand la paire
 * tue l'attaquant sans mourir toute entière, et chump-bloque seulement si l'attaque est létale.
 * Profils (`AgentRegistry`) : `ai:heuristic` (défaut ci-dessus), `ai:aggro` (ne garde ni contresort ni bloqueur, attaque
 * le plus faible), `ai:control` (contre plus large, garde un bloqueur dès la moitié de ses PV menacée). Le profil est figé à
 * la construction : l'agent reste sans état.
 */
final class HeuristicAgent implements PlayerAgent
{
    /**
     * @param int  $counterThreshold menace minimale d'un sort adverse pour le contrer (voir `threat`)
     * @param int  $hugeThreat       valeur d'un de mes sorts pour le lancer malgré la réserve de contresort
     * @param bool $keepCounterMana  garder de quoi payer son contresort (voir `keepCounterMana`)
     * @param int  $guardFactor      une créature reste en garde si la force d'un adversaire × ce facteur atteint mes PV (0 : jamais)
     * @param bool $targetByThreat   cible d'attaque : le plus menaçant (sinon le plus faible en PV), après la cible létale
     */
    public function __construct(
        private readonly int $counterThreshold = 3,
        private readonly int $hugeThreat = 6,
        private readonly bool $keepCounterMana = true,
        private readonly int $guardFactor = 1,
        private readonly bool $targetByThreat = true,
    ) {
    }

    /** Agressif : tout pour l'attaque, aucune réserve de contresort ni garde, sur l'adversaire le plus faible. */
    public static function aggro(): self
    {
        return new self(keepCounterMana: false, guardFactor: 0, targetByThreat: false);
    }

    /** Contrôle : contre dès une menace de 2, ne lâche sa réserve que pour un sort énorme, garde un bloqueur plus tôt. */
    public static function control(): self
    {
        return new self(counterThreshold: 2, hugeThreat: 8, guardFactor: 2);
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if ($state->mulligan !== null) {
            return $this->mulliganDecision($state, $legal, $playerId);
        }
        $discards = array_values(array_filter($legal, static fn (Action $a) => $a instanceof Discard));
        if ($discards !== []) {
            return $this->discard($state, $discards, $playerId);
        }
        $triggers = array_values(array_filter($legal, static fn (Action $a) => $a instanceof PutTrigger));
        if ($triggers !== []) {
            return $this->putTrigger($state, $triggers, $playerId);
        }
        if ($state->combat->declaring === 'attackers') {
            return $this->declareAttackers($state, $legal, $playerId);
        }
        if ($state->combat->declaring === 'blockers') {
            return $this->declareBlockers($state, $legal, $playerId);
        }

        $top = $state->topOfStack();

        if ($top !== null && $top->controller !== $playerId && $this->threat($state, $playerId, $top) >= $this->counterThreshold) {
            foreach ($legal as $action) {
                if ($action instanceof CastSpell && $this->isCounter($state, $action)
                    && $action->targets === ['stack:' . $top->id]) {
                    return $action;
                }
            }
        }

        foreach ($legal as $action) {
            if ($action instanceof PlayLand) {
                return $action;
            }
        }

        // Wild Mongrel : en combat (étape des bloqueurs), défausser un terrain pour +1/+1 si la créature attaque ou bloque.
        if ($state->phase === Phase::DeclareBlockers && $state->combat->declaring === null) {
            foreach ($legal as $action) {
                if ($action instanceof ActivateAbility && $this->ability($state, $action)?->effect === 'pump'
                    && (isset($state->combat->attackers[$action->cardId]) || $state->combat->isBlocking($action->cardId))
                    && $state->player($playerId)->findInHand($action->costChoices[0] ?? '')?->isLand()) {
                    return $action;
                }
            }
        }

        // Capacité de dégâts (Prodigal Pinger, Goblin Bombardment) : sur l'adversaire vivant le plus faible (égalité : plus
        // petit id). Bombardment ne sacrifie que des jetons. Les capacités de mana ne sont jamais choisies : l'auto-paiement
        // s'en charge.
        $ping = null;
        foreach ($legal as $action) {
            if ($action instanceof ActivateAbility && !$action->mana
                && ($action->costChoices === [] || $this->battlefieldCard($state, $playerId, $action->costChoices[0])?->token)
                && ($target = $this->playerTarget($action)) !== null && $target !== $playerId) {
                $life = $state->player($target)->life;
                if ($ping === null || $life < $ping[1] || ($life === $ping[1] && $target < $ping[2])) {
                    $ping = [$action, $life, $target];
                }
            }
        }
        if ($ping !== null) {
            return $ping[0];
        }

        if ($top === null && $state->activePlayer === $playerId && $state->phase->isMain()) {
            $casts = [];
            foreach ($legal as $action) {
                if ($action instanceof CastSpell
                    && !$this->isCounter($state, $action)
                    && !in_array('player:' . $playerId, $action->targets, true)) {
                    $casts[] = $action;
                }
            }
            foreach ($casts as $c) {
                if ($state->player($playerId)->findCastable($c->cardId)?->name === 'Sol Ring') {
                    return $c;
                }
            }
            $casts = array_values(array_filter($casts, fn (CastSpell $c) => $this->worthCasting($state, $playerId, $c)));
            $casts = $this->keepCounterMana($state, $playerId, $casts);
            if ($casts !== []) {
                $casts = $this->leastWasteful($state, $playerId, $casts);
                $pick = $this->withBestX($state, $casts[$state->rng->int(0, count($casts) - 1)], $casts);

                return $this->manaSparingCounter($state, $playerId, $pick, $legal) ?? $pick;
            }

            // Plus rien à lancer, en phase principale 2 : jetons (Kher Keep), puis pioche (Tome ; Mind Stone seulement main vide).
            if ($state->phase === Phase::Main2) {
                foreach (['token', 'draw'] as $effect) {
                    foreach ($legal as $action) {
                        $ability = $action instanceof ActivateAbility ? $this->ability($state, $action) : null;
                        if ($ability?->effect === $effect && (!$ability->sacrifice || $state->player($playerId)->hand === [])
                            && $this->abilityKeepsCounterMana($state, $playerId, $action, $ability)) {
                            return $action;
                        }
                    }
                }
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }

    private function ability(GameState $state, ActivateAbility $a): ?ActivatedAbility
    {
        return $this->battlefieldCard($state, $a->player(), $a->cardId)?->abilities()[$a->abilityIndex] ?? null;
    }

    private function playerTarget(ActivateAbility $a): ?int
    {
        $t = $a->targets[0] ?? null;

        return $t !== null && str_starts_with($t, 'player:') ? (int) substr($t, 7) : null;
    }

    /**
     * Évaluateur de menace d'un élément de pile adverse : ce qui me vise (ou protège mon sort d'un contresort adverse)
     * pèse lourd ; un rituel, un jeton de mana ou un sort bon marché (valeur de mana + dégâts < 3) qui ne me vise pas ne vaut pas
     * un contresort. Sinon la valeur de mana (+ les dégâts), +1 pour une créature, +1 pour une créature de force >= 3.
     */
    private function threat(GameState $state, int $playerId, \App\Engine\Game\StackItem $item): int
    {
        if ($item->isAbility()) {
            return 0;
        }
        if (in_array('player:' . $playerId, $item->targets, true)) {
            return 10;
        }
        $card = $item->card;
        if ($card->effect === 'counter') {
            // Un contresort adverse qui vise un de mes sorts : le contrer protège mon sort.
            foreach ($item->targets as $t) {
                if (str_starts_with($t, 'stack:')) {
                    foreach ($state->stack as $other) {
                        if ('stack:' . $other->id === $t && $other->controller === $playerId) {
                            return 8;
                        }
                    }
                }
            }

            return 0;
        }
        if ($card->effect === 'mana' || $card->effect === 'treasure') {
            return 0;
        }

        return $this->cardValue($card, $item->x);
    }

    /**
     * Garde les sorts qui laissent le moins de mana inutilisé dans la réserve (Sol Ring payant un `{1}` laisse 1 mana qui
     * sera perdu à la fin de l'étape). Pour chaque carte, on simule le paiement puis, en regardant un sort de plus, le
     * mana qu'il resterait au mieux une fois la réserve réutilisée par un second sort. Les sorts à X n'entrent pas dans
     * le calcul (le X maximal absorbe le surplus). Sans simulation possible, on garde tout.
     *
     * @param list<CastSpell> $casts
     * @return list<CastSpell>
     */
    private function leastWasteful(GameState $state, int $playerId, array $casts): array
    {
        $p = $state->player($playerId);
        $cards = [];
        foreach ($casts as $c) {
            $cards[$c->cardId] = $p->findCastable($c->cardId);
        }
        if (count($cards) < 2) {
            return $casts;
        }
        $score = [];
        foreach ($cards as $id => $card) {
            if ($card === null || $card->effect === 'mana') {
                $score[$id] = 0;
                continue;
            }
            $copy = $this->afterCast($state, $p, $card);
            if ($copy === null) {
                $score[$id] = 0;
                continue;
            }
            $best = $copy->pool->total();
            foreach ($cards as $id2 => $card2) {
                if ($id2 === $id || $card2 === null || $card2->effect === 'mana') {
                    continue;
                }
                $second = $this->afterCast($state, $copy, $card2);
                if ($second !== null) {
                    $best = min($best, $second->pool->total());
                }
            }
            $score[$id] = $best;
        }
        $min = min($score);

        return array_values(array_filter($casts, static fn (CastSpell $c) => $score[$c->cardId] === $min));
    }

    /**
     * Copie de l'état du joueur après avoir payé `$card` (sans effet de bord) ; null si inpayable ou si le coût a un {X} sans `$x`.
     *
     * @return PlayerState|null
     */
    private function afterCast(GameState $state, PlayerState $p, CardInstance $card, ?int $x = null): ?PlayerState
    {
        $copy = PlayerState::fromArray($p->toArray());
        $copyCard = $copy->findCastable($card->id);
        if ($copyCard === null) {
            return null;
        }
        $cost = $copy->castCost($copyCard);
        if ($cost->hasX() && $x === null) {
            return null;
        }
        $plan = ManaPayment::plan($copy, $cost, $x ?? 0, $state->turn, null, $copyCard);
        if ($plan === null) {
            return null;
        }
        $copy->payWithPlan($plan);

        return $copy;
    }

    /**
     * Garder de quoi contrer : si la main contient un contresort payable maintenant, on n'en lance pas d'autre sort que ceux
     * qui laissent assez de mana (réserve et sources non engagées) pour payer le moins cher des contresorts. Exceptions : un
     * rituel (il ajoute du mana), et une rafale de dégâts létale pour un joueur (ne rien faire serait pire). Si le contresort
     * n'est pas payable de toute façon (couleur absente, sources déjà engagées), la réserve est sans objet et rien n'est filtré.
     *
     * @param list<CastSpell> $casts
     * @return list<CastSpell>
     */
    private function keepCounterMana(GameState $state, int $playerId, array $casts): array
    {
        $reserve = $this->reservedCounter($state, $playerId);
        if ($reserve === null) {
            return $casts;
        }
        $p = $state->player($playerId);
        $kept = [];
        $waste = [];
        foreach ($casts as $c) {
            $card = $p->findCastable($c->cardId);
            if ($card === null || $card->effect === 'mana' || $this->isLethalBurn($state, $card, $c) || $this->isGameChanging($card, $c)) {
                $kept[] = $c;
                $waste[] = 0;
                continue;
            }
            $after = $this->afterCast($state, $p, $card, $c->x);
            $lost = $after === null ? null : $this->counterWaste($state, $after, $reserve);
            if ($lost !== null) {
                $kept[] = $c;
                $waste[] = $lost;
            }
        }
        if ($kept === []) {
            return [];
        }
        // Parmi les sorts qui gardent le contresort, ceux qui laissent de quoi le payer sans perte (une Île plutôt
        // qu'un Signet, dont le mana en trop serait perdu).
        $min = min($waste);

        return array_values(array_filter($kept, static fn (CastSpell $c, int $i) => $waste[$i] === $min, ARRAY_FILTER_USE_BOTH));
    }

    /** Sort qui change la partie : sa valeur (comme `threat()`) atteint `hugeThreat` ; on le lance même sans garder le contresort. */
    private function isGameChanging(CardInstance $card, CastSpell $cast): bool
    {
        return in_array($card->effect, [null, 'damage'], true) && $this->cardValue($card, $cast->x) >= $this->hugeThreat;
    }

    /** Valeur d'un sort : valeur de mana (+ X, + dégâts), +1 pour une créature, +1 pour une créature de force >= 3. */
    private function cardValue(CardInstance $card, int $x): int
    {
        return $card->cost + $x + ($card->effect === 'damage' ? $card->amount : 0) + ($card->isCreature() ? 1 : 0) + ($card->isCreature() && $card->power >= 3 ? 1 : 0);
    }

    /** Mana qui serait perdu en payant `$counter` avec l'état `$after` (production d'un Signet en trop) ; null si impayable. */
    private function counterWaste(GameState $state, PlayerState $after, CardInstance $counter): ?int
    {
        $copy = PlayerState::fromArray($after->toArray());
        $copyCounter = $copy->findCastable($counter->id);
        $plan = $copyCounter === null ? null : ManaPayment::plan($copy, $copy->castCost($copyCounter), 0, $state->turn, null, $copyCounter);
        if ($plan === null) {
            return null;
        }
        $before = $copy->pool->total();
        $copy->payWithPlan($plan);

        return max(0, $copy->pool->total() - $before);
    }

    /**
     * Paiement qui épargne le contresort : si le paiement automatique de `$cast` ne laisse de quoi payer le contresort réservé
     * qu'en perdant du mana (Signet à la place d'une Île), alors qu'un paiement sur les autres sources existe, renvoie la
     * prochaine capacité de mana de ce paiement à activer à la main (le sort sera lancé ensuite, payé par la réserve).
     * Null : lancer directement. La réserve courante est ignorée pour le contresort (elle se vide à la fin de l'étape).
     *
     * @param list<Action> $legal
     */
    private function manaSparingCounter(GameState $state, int $playerId, CastSpell $cast, array $legal): ?ActivateAbility
    {
        $p = $state->player($playerId);
        $card = $p->findCastable($cast->cardId);
        $counter = $this->reservedCounter($state, $playerId);
        if ($card === null || $counter === null || $card->effect === 'mana') {
            return null;
        }
        $after = $this->afterCast($state, $p, $card, $cast->x);
        if ($after === null) {
            return null;
        }
        $after->pool->clear();
        if ($this->counterWaste($state, $after, $counter) === 0) {
            return null;
        }
        // Sources du contresort mises de côté (engagées sur une copie), puis paiement du sort sur le reste.
        $spared = PlayerState::fromArray($p->toArray());
        $spared->pool->clear();
        $counterPlan = ManaPayment::plan($spared, $spared->castCost($counter), 0, $state->turn, null, $counter);
        if ($counterPlan === null) {
            return null;
        }
        $spared = PlayerState::fromArray($p->toArray());
        foreach ([...$counterPlan->taps, ...$counterPlan->converters] as $use) {
            $spared->findOnBattlefield($use['card'])->tapped = true;
        }
        $spellCard = $spared->findCastable($card->id);
        $plan = ManaPayment::plan($spared, $spared->castCost($spellCard), $cast->x, $state->turn, null, $spellCard);
        $next = $plan->taps[0] ?? $plan?->converters[0] ?? null;
        if ($next === null) {
            return null;
        }
        foreach ($legal as $a) {
            if ($a instanceof ActivateAbility && $a->mana && $a->cardId === $next['card']
                && $a->abilityIndex === $next['ability'] && $a->option === $next['option']) {
                return $a;
            }
        }

        return null;
    }

    /** Idem pour une capacité à coût de mana (jetons, pioche) : jamais si elle prive l'IA de son contresort. */
    private function abilityKeepsCounterMana(GameState $state, int $playerId, ActivateAbility $action, ActivatedAbility $ability): bool
    {
        $reserve = $this->reservedCounter($state, $playerId);
        if ($reserve === null) {
            return true;
        }
        $copy = PlayerState::fromArray($state->player($playerId)->toArray());
        if ($ability->manaCost !== null && !$ability->manaCost->hasX()) {
            $plan = ManaPayment::plan($copy, $ability->manaCost, 0, $state->turn, $action->cardId);
            if ($plan === null) {
                return true;
            }
            $copy->payWithPlan($plan);
        }
        if ($ability->tap && ($source = $copy->findOnBattlefield($action->cardId)) !== null) {
            $source->tapped = true;
        }

        return $this->canPayCounter($state, $copy, $reserve);
    }

    /** Le moins cher des contresorts de la main, s'il est payable avec l'état actuel ; sinon null. */
    private function reservedCounter(GameState $state, int $playerId): ?CardInstance
    {
        if (!$this->keepCounterMana) {
            return null;
        }
        $p = $state->player($playerId);
        $best = null;
        foreach ($p->hand as $card) {
            if ($card->effect === 'counter' && ($best === null || $card->cost < $best->cost)) {
                $best = $card;
            }
        }
        if ($best === null || ManaPayment::plan($p, $p->castCost($best), 0, $state->turn, null, $best) === null) {
            return null;
        }

        return $best;
    }

    private function canPayCounter(GameState $state, PlayerState $after, CardInstance $counter): bool
    {
        return ManaPayment::plan($after, $after->castCost($counter), 0, $state->turn, null, $counter) !== null;
    }

    /** Dégâts qui tuent un joueur vivant (X compris) : on les lance même en vidant le mana du contresort. */
    private function isLethalBurn(GameState $state, CardInstance $card, CastSpell $cast): bool
    {
        if ($card->effect !== 'damage') {
            return false;
        }
        foreach ($cast->targets as $t) {
            if (str_starts_with($t, 'player:') && $state->player((int) substr($t, 7))->life <= $card->amount + $cast->x) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un rituel (effect 'mana') n'est lancé que s'il rend payable un sort non-rituel de la main (ou du commandement)
     * qui ne l'était pas. Simulation sur une copie de l'état du joueur : le rituel est payé, puis son mana ajouté.
     */
    private function worthCasting(GameState $state, int $playerId, CastSpell $cast): bool
    {
        $p = $state->player($playerId);
        $ritual = $p->findCastable($cast->cardId);
        if ($ritual === null || $ritual->effect !== 'mana') {
            return true;
        }
        $copy = PlayerState::fromArray($p->toArray());
        $copyRitual = $copy->findCastable($cast->cardId);
        $plan = ManaPayment::plan($copy, $copy->castCost($copyRitual), 0, $state->turn, null, $copyRitual);
        if ($plan === null) {
            return false;
        }
        $copy->payWithPlan($plan);
        foreach (ManaCost::parse($ritual->adds ?? '')->colored as $symbol => $n) {
            $copy->pool->add(\App\Engine\Mana\Color::fromSymbol($symbol), $n);
        }
        // Avant : sans le rituel (état d'origine) ; après : réserve augmentée.
        $before = PlayerState::fromArray($p->toArray());
        foreach ([...$p->hand, ...$p->command] as $card) {
            if ($card->isLand() || $card->effect === 'mana' || $card->id === $ritual->id) {
                continue;
            }
            $cost = $p->castCost($card);
            if (ManaPayment::plan($before, $cost, 0, $state->turn, null, $card) === null && ManaPayment::plan($copy, $cost, 0, $state->turn, null, $card) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Premier trigger de la file ; s'il cible, l'adversaire vivant le plus faible (égalité : plus petit id), jamais soi-même
     * s'il y a un autre choix.
     *
     * @param list<PutTrigger> $triggers
     */
    private function putTrigger(GameState $state, array $triggers, int $playerId): PutTrigger
    {
        $first = $triggers[0]->pendingId;
        $best = null;
        foreach ($triggers as $t) {
            if ($t->pendingId !== $first) {
                continue;
            }
            if ($t->targets === []) {
                return $t;
            }
            $target = (int) substr($t->targets[0], 7);
            $score = [$target === $playerId ? 1 : 0, $state->player($target)->life, $target];
            if ($best === null || $score < $best[1]) {
                $best = [$t, $score];
            }
        }

        return $best[0];
    }

    /**
     * Avant-partie : garder 2 à 5 terrains ; sinon mulligan tant que moins de 2 pris ; mise au fond = classement de la défausse.
     *
     * @param list<Action> $legal
     */
    private function mulliganDecision(GameState $state, array $legal, int $playerId): Action
    {
        $bottoms = array_values(array_filter($legal, static fn (Action $a) => $a instanceof BottomCard));
        if ($bottoms !== []) {
            return $this->discard($state, $bottoms, $playerId);
        }
        $lands = count(array_filter($state->player($playerId)->hand, static fn (CardInstance $c) => $c->isLand()));
        $taken = $state->mulligan[$playerId]['taken'] ?? 0;
        $wanted = ($lands < 2 || $lands > 5) && $taken < 2 ? Mulligan::class : KeepHand::class;
        foreach ($legal as $action) {
            if ($action instanceof $wanted) {
                return $action;
            }
        }

        return $legal[0];
    }

    /**
     * Défausse d'une carte (la décision est reprise carte par carte). Sert aussi à choisir les cartes à mettre au fond
     * après un mulligan (aucun terrain en jeu : un terrain n'y est en surplus qu'à partir de 5 en main). Score le plus haut défaussé en premier :
     *  1. terrain en surplus (>= 5 en jeu, ou >= 3 en jeu et >= 3 en main) : 1000, +5 s'il n'apporte aucune couleur nouvelle ;
     *  2. carte injouable : couleur que ni le jeu ni les terrains en main ne produisent (avec au moins 3 terrains en jeu, avant
     *     cela les couleurs ne sont pas fixées), ou valeur de mana à plus de 3 au dessus du mana potentiel (sources en jeu +
     *     terrains en main, au moins 4) : 800 ;
     *  3. sinon la carte la plus chère (sa valeur de mana) ;
     *  4. on garde en dernier : un terrain utile (-1, ou -1000 s'il y en a moins de 4 en jeu) et un contresort (-500).
     * Égalité : plus petit id.
     *
     * @param list<Discard|BottomCard> $discards
     */
    private function discard(GameState $state, array $discards, int $playerId): Action
    {
        $p = $state->player($playerId);
        $handLandCards = array_values(array_filter($p->hand, static fn (CardInstance $c) => $c->isLand()));
        $lands = count(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isLand()));
        $handLands = count($handLandCards);
        $produced = $this->producedSymbols($p->battlefield);
        $reachable = $this->producedSymbols([...$p->battlefield, ...$handLandCards]);
        $potential = $this->manaPotential($p->battlefield) + $handLands;
        $best = null;
        foreach ($discards as $d) {
            $card = $p->findInHand($d->cardId);
            if ($card->isLand()) {
                if ($lands >= 5 || ($lands >= 3 && $handLands >= 3) || ($state->mulligan !== null && $handLands >= 5)) {
                    $score = 1000 + (array_diff_key($this->producedSymbols([$card]), $produced) === [] ? 5 : 0);
                } else {
                    $score = $lands < 4 ? -1000 : -1;
                }
            } else {
                $cost = $card->manaCost();
                $impossible = $lands >= 3 && array_diff_key($cost->colored, $reachable) !== [];
                if ($impossible || $cost->generic + array_sum($cost->colored) + count($cost->hybrid) > max($potential, 4) + 3) {
                    $score = 800;
                } else {
                    $score = $card->effect === 'counter' ? -500 : $card->cost;
                }
            }
            if ($best === null || $score > $best[1] || ($score === $best[1] && $card->id < $best[0]->cardId)) {
                $best = [$d, $score];
            }
        }

        return $best[0];
    }

    /**
     * Symboles (WUBRGC) que ces permanents savent produire (toutes options de leurs capacités de mana ; un terrain en main
     * compte via `abilities()`).
     *
     * @param iterable<CardInstance> $cards
     * @return array<string, true>
     */
    private function producedSymbols(iterable $cards): array
    {
        $set = [];
        foreach ($cards as $card) {
            foreach ($card->abilities() as $ability) {
                if ($ability->isManaAbility) {
                    foreach ($ability->options as $option) {
                        foreach ($option as $color) {
                            $set[$color->symbol()] = true;
                        }
                    }
                }
            }
        }

        return $set;
    }

    /**
     * Mana qu'ils peuvent produire en un tour : somme, par permanent, de sa meilleure option (Sol Ring : 2).
     *
     * @param iterable<CardInstance> $cards
     */
    private function manaPotential(iterable $cards): int
    {
        $total = 0;
        foreach ($cards as $card) {
            $best = 0;
            foreach ($card->abilities() as $ability) {
                if ($ability->isManaAbility) {
                    foreach ($ability->options as $option) {
                        $best = max($best, count($option));
                    }
                }
            }
            $total += $best;
        }

        return $total;
    }

    /** @param list<Action> $legal */
    private function declareAttackers(GameState $state, array $legal, int $playerId): Action
    {
        // Les créatures à mana (Llanowar Elves) restent à la maison : leur mana vaut plus que leur attaque. Une créature
        // sans force (jeton Kobold 0/1) aussi.
        // Le commandant n'attaque pas s'il peut mourir au blocage (même en échange : le relancer coûte la taxe). En
        // danger de mort au prochain tour, une créature reste en garde (voir `guard`).
        $guard = $this->guard($state, $playerId);
        $attacks = array_values(array_filter($legal, fn (Action $a) => $a instanceof DeclareAttacker
            && $a->cardId !== $guard
            && !$this->hasManaAbility($card = $this->battlefieldCard($state, $playerId, $a->cardId))
            && ($card?->currentPower() ?? 0) > 0
            && !$this->isSuicideAttack($state, $card, $a->defender)
            && !($card->commander && $this->canDieBlocked($state, $card, $a->defender))));
        if ($attacks === []) {
            return $this->finish($legal, $playerId);
        }
        $target = $this->attackTarget($state, $attacks);
        foreach ($attacks as $a) {
            if ($a->defender === $target) {
                return $a;
            }
        }

        return $this->finish($legal, $playerId);
    }

    /**
     * Adversaire visé : d'abord celui que l'attaque peut tuer (force des attaquants possibles contre lui + celle des
     * créatures qui l'attaquent déjà >= ses PV), puis le plus menaçant (`boardThreat`), puis le plus faible en PV, puis le
     * plus petit id. Le choix reste stable d'une déclaration à l'autre : la cible létale le reste, et la menace ne dépend
     * pas des attaquants.
     *
     * @param list<DeclareAttacker> $attacks
     */
    private function attackTarget(GameState $state, array $attacks): int
    {
        $power = [];
        foreach ($attacks as $a) {
            $power[$a->defender][$a->cardId] = max(0, $this->battlefieldCard($state, $a->player(), $a->cardId)?->currentPower() ?? 0);
        }
        $best = null;
        foreach ($power as $defender => $byCard) {
            $total = array_sum($byCard);
            foreach ($state->combat->attackers as $attackerId => $attacked) {
                if ($attacked === $defender) {
                    $total += max(0, $this->battlefieldCard($state, $state->activePlayer, (string) $attackerId)?->currentPower() ?? 0);
                }
            }
            $life = $state->player($defender)->life;
            $score = [$total >= $life ? 0 : 1, -array_sum($byCard), $this->targetByThreat ? -$this->boardThreat($state, $defender) : 0, $life, $defender];
            if ($best === null || $score < $best[1]) {
                $best = [$defender, $score];
            }
        }

        return $best[0];
    }

    /** Menace d'un joueur sur la table : somme des valeurs (`cardValue`) de ses permanents non-terrains. */
    private function boardThreat(GameState $state, int $playerId): int
    {
        $threat = 0;
        foreach ($state->player($playerId)->battlefield as $card) {
            if (!$card->isLand()) {
                $threat += $this->cardValue($card, 0);
            }
        }

        return $threat;
    }

    /**
     * Créature gardée à la maison pour bloquer : seulement si la force cumulée d'un adversaire (créatures sans défenseur)
     * atteint mes PV et qu'aucune autre créature capable de bloquer ne reste déjà (créature à mana, sans force, ou avec
     * vigilance). On garde la plus grande endurance (égalité : la première du champ de bataille). Null sinon.
     */
    private function guard(GameState $state, int $playerId): ?string
    {
        if ($this->guardFactor === 0) {
            return null;
        }
        $me = $state->player($playerId);
        $danger = false;
        foreach ($state->players as $opponent) {
            if ($opponent->id === $playerId || $opponent->lost) {
                continue;
            }
            $power = 0;
            foreach ($opponent->battlefield as $c) {
                if ($c->isCreature() && !$c->hasKeyword(CardInstance::DEFENDER)) {
                    $power += max(0, $c->currentPower());
                }
            }
            $danger = $danger || $power * $this->guardFactor >= $me->life;
        }
        if (!$danger) {
            return null;
        }
        $best = null;
        foreach ($me->battlefield as $c) {
            if (!$c->isCreature() || isset($state->combat->attackers[$c->id])) {
                continue;
            }
            if ($c->tapped) {
                continue;
            }
            if ($this->hasManaAbility($c) || $c->currentPower() <= 0 || $c->hasKeyword(CardInstance::VIGILANCE)) {
                return null;
            }
            if ($best === null || $c->currentToughness() > $best->currentToughness()) {
                $best = $c;
            }
        }

        return $best?->id;
    }

    /** Vrai si un bloqueur possible du défenseur tuerait cet attaquant (échange compris ; vol et portée pris en compte). */
    private function canDieBlocked(GameState $state, CardInstance $attacker, int $defender): bool
    {
        foreach ($state->player($defender)->battlefield as $blocker) {
            if ($blocker->isCreature() && !$blocker->tapped
                && (!$attacker->hasKeyword(CardInstance::FLYING) || $blocker->hasKeyword(CardInstance::FLYING) || $blocker->hasKeyword(CardInstance::REACH))
                && $this->duel($attacker, $blocker)[1]) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Action> $legal */
    private function declareBlockers(GameState $state, array $legal, int $playerId): Action
    {
        $incoming = 0;
        foreach ($state->combat->attackers as $attackerId => $defender) {
            if ($defender === $playerId && ($attacker = $this->battlefieldCard($state, $state->activePlayer, $attackerId)) !== null) {
                $incoming += $attacker->currentPower();
            }
        }
        $lethal = $incoming >= $state->player($playerId)->life;

        $chump = null;
        foreach ($legal as $a) {
            if (!$a instanceof DeclareBlocker || isset($state->combat->blockers[$a->attackerId])) {
                continue;
            }
            $attacker = $this->battlefieldCard($state, $state->activePlayer, $a->attackerId);
            $blocker = $this->battlefieldCard($state, $playerId, $a->cardId);
            if ($attacker === null || $blocker === null) {
                continue;
            }
            [$survives, $kills] = $this->duel($attacker, $blocker);
            if ($survives || $kills) {
                return $a;
            }
            // Contre le piétinement, le chump block ne retient que l'endurance du bloqueur.
            $saved = $attacker->hasKeyword(CardInstance::TRAMPLE)
                ? min($attacker->currentPower(), $blocker->currentToughness() - $blocker->damage)
                : $attacker->currentPower();
            if ($lethal && !$blocker->commander && ($chump === null || $saved > $chump[1])) {
                $chump = [$a, $saved];
            }
        }
        $double = $this->doubleBlock($state, $legal, $playerId);
        if ($double !== null) {
            return $double;
        }
        if ($chump !== null) {
            return $chump[0];
        }

        return $this->finish($legal, $playerId);
    }

    /**
     * Blocage à deux : un attaquant qu'aucun bloqueur seul ne tue est bloqué par deux créatures qui le tuent ensemble, si
     * l'attaquant ne peut pas tuer les deux et vaut au moins le moins cher des bloqueurs (on accepte d'en perdre un).
     * Jamais avec le commandant, ni contre l'initiative, la double initiative ou le contact mortel (le duel à deux n'est
     * pas simulé). La plus grande endurance est déclarée d'abord (l'attaquant répartit ses dégâts dans l'ordre). Second
     * appel : l'attaquant déjà bloqué par une de mes créatures reçoit le second bloqueur.
     *
     * @param list<Action> $legal
     */
    private function doubleBlock(GameState $state, array $legal, int $playerId): ?DeclareBlocker
    {
        $options = [];
        foreach ($legal as $a) {
            if ($a instanceof DeclareBlocker && ($b = $this->battlefieldCard($state, $playerId, $a->cardId)) !== null && !$b->commander) {
                $options[$a->attackerId][] = [$a, $b];
            }
        }
        foreach ($options as $attackerId => $candidates) {
            $attacker = $this->battlefieldCard($state, $state->activePlayer, (string) $attackerId);
            if ($attacker === null || $attacker->hasKeyword(CardInstance::FIRST_STRIKE) || $attacker->hasKeyword(CardInstance::DOUBLE_STRIKE)
                || $attacker->hasKeyword(CardInstance::DEATHTOUCH)) {
                continue;
            }
            $current = [];
            foreach ($state->combat->blockers[$attackerId] ?? [] as $id) {
                if (($c = $this->battlefieldCard($state, $playerId, $id)) !== null) {
                    $current[] = $c;
                }
            }
            if (count($current) >= 2 || (count($current) === 1 && $this->gangKills($attacker, $current))) {
                continue;
            }
            if (count($current) === 1) {
                foreach ($candidates as [$a, $b]) {
                    if ($this->goodPair($attacker, $current[0], $b)) {
                        return $a;
                    }
                }
                continue;
            }
            foreach ($candidates as $i => [$a1, $b1]) {
                foreach ($candidates as $j => [$a2, $b2]) {
                    if ($i !== $j && $this->goodPair($attacker, $b1, $b2)
                        && $b1->currentToughness() - $b1->damage >= $b2->currentToughness() - $b2->damage) {
                        return $a1;
                    }
                }
            }
        }

        return null;
    }

    private function goodPair(CardInstance $attacker, CardInstance $b1, CardInstance $b2): bool
    {
        $left = static fn (CardInstance $c): int => $c->currentToughness() - $c->damage;

        return $this->gangKills($attacker, [$b1, $b2])
            && $attacker->currentPower() < $left($b1) + $left($b2)
            && $attacker->cost >= min($b1->cost, $b2->cost);
    }

    /** @param list<CardInstance> $blockers Vrai si les bloqueurs tuent ensemble l'attaquant (force cumulée, ou contact mortel). */
    private function gangKills(CardInstance $attacker, array $blockers): bool
    {
        $power = 0;
        foreach ($blockers as $b) {
            if ($b->currentPower() > 0 && $b->hasKeyword(CardInstance::DEATHTOUCH)) {
                return true;
            }
            $power += max(0, $b->currentPower());
        }

        return $power > 0 && $power >= $attacker->currentToughness() - $attacker->damage;
    }

    /**
     * Issue d'un duel attaquant / bloqueur (contact mortel et initiative compris).
     *
     * @return array{bool, bool} [le bloqueur survit, l'attaquant meurt]
     */
    private function duel(CardInstance $attacker, CardInstance $blocker): array
    {
        $kills = static fn (CardInstance $by, CardInstance $on): bool => $by->currentPower() > 0
            && ($by->hasKeyword(CardInstance::DEATHTOUCH) || $by->currentPower() >= $on->currentToughness() - $on->damage);
        $first = static fn (CardInstance $c): bool => $c->hasKeyword(CardInstance::FIRST_STRIKE) || $c->hasKeyword(CardInstance::DOUBLE_STRIKE);
        $blockerDies = $kills($attacker, $blocker);
        $attackerDies = $kills($blocker, $attacker);
        if ($first($attacker) && !$first($blocker) && $blockerDies) {
            $attackerDies = false;
        } elseif ($first($blocker) && !$first($attacker) && $attackerDies) {
            $blockerDies = false;
        }

        return [!$blockerDies, $attackerDies];
    }

    /** Vrai si un bloqueur possible du défenseur tuerait cet attaquant sans mourir (hors vol, piétinement, menace). */
    private function isSuicideAttack(GameState $state, CardInstance $attacker, int $defender): bool
    {
        if ($attacker->hasKeyword(CardInstance::TRAMPLE) || $attacker->hasKeyword(CardInstance::FLYING) || $attacker->hasKeyword(CardInstance::MENACE)) {
            return false;
        }
        foreach ($state->player($defender)->battlefield as $blocker) {
            if ($blocker->isCreature() && !$blocker->tapped) {
                [$survives, $kills] = $this->duel($attacker, $blocker);
                if ($survives && $kills) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sort à X (une CastSpell par valeur de X) : retient la variante au X le plus grand, jamais X=0 si possible.
     *
     * @param list<CastSpell> $casts
     */
    private function withBestX(GameState $state, CastSpell $pick, array $casts): CastSpell
    {
        if (!property_exists($pick, 'x')) {
            return $pick;
        }
        $best = $pick;
        foreach ($casts as $c) {
            if ($c->cardId === $pick->cardId && $c->targets === $pick->targets && $c->x > $best->x) {
                $best = $c;
            }
        }
        if ($best->x === 0) {
            // Seule la variante X=0 existe pour cette carte : on l'évite si une autre action de lancer est possible.
            foreach ($casts as $c) {
                if ($c->x > 0) {
                    return $c;
                }
            }
        }

        return $best;
    }

    /** @param list<Action> $legal */
    private function finish(array $legal, int $playerId): Action
    {
        foreach ($legal as $a) {
            if ($a instanceof FinishDeclaration) {
                return $a;
            }
        }

        return new FinishDeclaration($playerId);
    }

    private function battlefieldCard(GameState $state, int $owner, string $cardId): ?CardInstance
    {
        foreach ($state->player($owner)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function hasManaAbility(?CardInstance $card): bool
    {
        foreach ($card?->abilities() ?? [] as $ability) {
            if ($ability->isManaAbility) {
                return true;
            }
        }

        return false;
    }

    private function isCounter(GameState $state, CastSpell $action): bool
    {
        return $state->player($action->player())->findCastable($action->cardId)?->effect === 'counter';
    }
}
