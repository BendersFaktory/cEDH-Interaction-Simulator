<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\Discard;
use App\Engine\Action\FinishDeclaration;
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
 * Défausse (main > 7) : un terrain en surplus (≥ 5 en jeu), sinon la carte la plus chère, à égalité le plus petit id.
 * Combat : attaque avec toutes ses créatures sur l'adversaire le plus faible ; bloque quand le bloqueur survit
 * ou tue l'attaquant (un seul bloqueur par attaquant), et chump-bloque seulement si l'attaque est létale.
 * TODO: évaluateur de menace (ne pas contrer n'importe quoi), gestion du mana, etc.
 */
final class HeuristicAgent implements PlayerAgent
{
    public function decide(GameState $state, int $playerId, array $legal): Action
    {
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

        if ($top !== null && $top->controller !== $playerId && $this->threat($state, $playerId, $top) >= self::COUNTER_THRESHOLD) {
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
            if ($casts !== []) {
                $casts = $this->leastWasteful($state, $playerId, $casts);
                $pick = $casts[$state->rng->int(0, count($casts) - 1)];

                return $this->withBestX($state, $pick, $casts);
            }

            // Plus rien à lancer, en phase principale 2 : jetons (Kher Keep), puis pioche (Tome ; Mind Stone seulement main vide).
            if ($state->phase === Phase::Main2) {
                foreach (['token', 'draw'] as $effect) {
                    foreach ($legal as $action) {
                        $ability = $action instanceof ActivateAbility ? $this->ability($state, $action) : null;
                        if ($ability?->effect === $effect && (!$ability->sacrifice || $state->player($playerId)->hand === [])) {
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

    /** Menace minimale d'un sort adverse pour justifier un contresort. */
    private const COUNTER_THRESHOLD = 3;

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

        return $card->cost + $item->x + ($card->effect === 'damage' ? $card->amount : 0) + ($card->isCreature() ? 1 : 0) + ($card->isCreature() && $card->power >= 3 ? 1 : 0);
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
     * Copie de l'état du joueur après avoir payé `$card` (sans effet de bord) ; null si inpayable ou si le coût a un {X}.
     *
     * @return PlayerState|null
     */
    private function afterCast(GameState $state, PlayerState $p, CardInstance $card): ?PlayerState
    {
        $copy = PlayerState::fromArray($p->toArray());
        $copyCard = $copy->findCastable($card->id);
        if ($copyCard === null) {
            return null;
        }
        $cost = $copy->castCost($copyCard);
        if ($cost->hasX()) {
            return null;
        }
        $plan = ManaPayment::plan($copy, $cost, 0, $state->turn, null, $copyCard);
        if ($plan === null) {
            return null;
        }
        $copy->payWithPlan($plan);

        return $copy;
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

    /** @param list<Discard> $discards */
    private function discard(GameState $state, array $discards, int $playerId): Action
    {
        $p = $state->player($playerId);
        $lands = count(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isLand()));
        $best = null;
        foreach ($discards as $d) {
            $card = $p->findInHand($d->cardId);
            $score = $card->isLand() ? ($lands >= 5 ? 1000 : -1) : $card->cost;
            if ($best === null || $score > $best[1] || ($score === $best[1] && $card->id < $best[0]->cardId)) {
                $best = [$d, $score];
            }
        }

        return $best[0];
    }

    /** @param list<Action> $legal */
    private function declareAttackers(GameState $state, array $legal, int $playerId): Action
    {
        // Les créatures à mana (Llanowar Elves) restent à la maison : leur mana vaut plus que leur attaque. Une créature
        // sans force (jeton Kobold 0/1) aussi.
        $attacks = array_values(array_filter($legal, fn (Action $a) => $a instanceof DeclareAttacker
            && !$this->hasManaAbility($card = $this->battlefieldCard($state, $playerId, $a->cardId))
            && ($card?->currentPower() ?? 0) > 0));
        if ($attacks === []) {
            return $this->finish($legal, $playerId);
        }
        $target = null;
        foreach ($attacks as $a) {
            $life = $state->player($a->defender)->life;
            if ($target === null || $life < $state->player($target)->life
                || ($life === $state->player($target)->life && $a->defender < $target)) {
                $target = $a->defender;
            }
        }
        foreach ($attacks as $a) {
            if ($a->defender === $target) {
                return $a;
            }
        }

        return $this->finish($legal, $playerId);
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
            if ($blocker->currentToughness() - $blocker->damage > $attacker->currentPower() || $blocker->currentPower() >= $attacker->currentToughness() - $attacker->damage) {
                return $a;
            }
            if ($lethal && !$blocker->commander && ($chump === null || $attacker->currentPower() > $chump[1])) {
                $chump = [$a, $attacker->currentPower()];
            }
        }
        if ($chump !== null) {
            return $chump[0];
        }

        return $this->finish($legal, $playerId);
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
