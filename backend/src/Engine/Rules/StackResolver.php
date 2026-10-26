<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;

/** Résout le sommet de la pile (sort, capacité activée ou trigger). TODO: dispatch par carte (handlers) au lieu de flags 'damage'/'counter'. */
final class StackResolver
{
    /**
     * Le sommet de pile demande-t-il un paiement à sa résolution ? Contresort « sauf si son contrôleur paie » (Daze, Mana Leak)
     * dont la cible est encore légale : le contrôleur du sort visé paie. Trigger de Pact of Negation : son contrôleur paie
     * ou perd la partie. Trigger de Rhystic Study (`draw_unless`) : le lanceur du sort (sa cible `player:<id>`) paie, s'il est
     * encore en jeu. Rien sinon (aucune décision).
     *
     * @return array{player: int, cost: string, source: string}|null
     */
    public static function paymentDue(GameState $s): ?array
    {
        $top = $s->topOfStack();
        if ($top === null) {
            return null;
        }
        if ($top->isTrigger()) {
            if ($top->trigger->effect === 'draw_unless') {
                $payer = str_starts_with($top->targets[0] ?? '', 'player:') ? (int) substr($top->targets[0], 7) : null;

                return $payer === null || $s->player($payer)->lost ? null : ['player' => $payer, 'cost' => $top->trigger->spec, 'source' => $top->card->name];
            }

            return $top->trigger->effect === 'pact' ? ['player' => $top->controller, 'cost' => $top->trigger->spec, 'source' => $top->card->name] : null;
        }
        if ($top->isAbility()) {
            return null;
        }
        $k = 0;
        foreach ($top->card->effects as $effect) {
            if (!$effect->isTargeted()) {
                continue;
            }
            $target = $top->targets[$k++] ?? '';
            if ($effect->effect === 'counter' && $effect->payCost !== null) {
                $victim = str_starts_with($target, 'stack:') ? $s->findStackItem(substr($target, 6)) : null;
                if ($victim === null || $victim->isAbility() || $s->player($victim->controller)->lost) {
                    return null;
                }

                return ['player' => $victim->controller, 'cost' => $effect->payCost, 'source' => $top->card->name];
            }
        }

        return null;
    }

    /** @param ?bool $paid réponse au paiement demandé par `paymentDue()` (null : aucun paiement demandé) */
    public function resolveTop(GameState $s, ?bool $paid = null): void
    {
        $item = array_pop($s->stack);
        if ($item === null) {
            return;
        }
        $card = $item->card;

        if ($item->isAbility()) {
            // Capacité activée ou trigger : la source reste où elle est (ou l'a déjà quitté), seuls les effets s'appliquent.
            $s->emit('AbilityResolved', [
                'stackId' => $item->id,
                'controller' => $item->controller,
                'card' => $card->toPublic(),
                'abilityIndex' => $item->abilityIndex,
            ] + ($item->isTrigger() ? ['trigger' => true] : []));
            $ability = $item->ability ?? $item->trigger;
            match ($ability->effect) {
                'damage' => $this->damage($s, $item->targets[0] ?? '', $ability->amount, $card->name),
                'draw' => $this->draw($s, $item->controller, $ability->amount),
                'token' => $this->tokens($s, $item->controller, $ability->spec, $ability->amount),
                'treasure' => $this->treasures($s, $item->controller, $ability->amount),
                'pump' => $this->pump($s, $item->controller, $card->id, $item->ability),
                'fetch' => $this->fetch($s, $item->controller, $item->ability, $item->targets),
                'untap' => $this->untap($s, $item->controller, $card->id),
                'self_damage' => $this->selfDamage($s, $item->controller, $card->id, $item->trigger),
                'imprint' => $this->imprint($s, $item->controller, $card->id, $item->targets[0] ?? ''),
                'mana' => $ability->amount > 0 ? $this->addMana($s, $item->controller, str_repeat('{C}', $ability->amount), $item->id) : null,
                'pact' => $paid === true ? null : (new StateBasedActions())->eliminate($s, $item->controller, 'pact_unpaid'),
                'draw_unless' => $paid === true ? null : $this->draw($s, $item->controller, $ability->amount), // Rhystic Study
                'pay_life_draw' => $this->payLifeDraw($s, $item->controller, $card->name), // Tymna the Weaver
                default => null,
            };

            return;
        }

        // Permanent -> champ de bataille ; sinon cimetière.
        if ($card->isPermanent()) {
            $s->enterBattlefield($item->controller, $card);
            $destination = 'battlefield';
        } else {
            $destination = $s->sendToGraveyard($item->controller, $card);
        }

        $s->emit('SpellResolved', [
            'stackId' => $item->id,
            'card' => $card->toPublic(),
            'controller' => $item->controller,
            'destination' => $destination,
        ]);

        // CR 608.2b : une cible par effet ciblé, dans l'ordre du texte. Si TOUTES les cibles sont devenues illégales, le sort ne
        // fait rien (ses effets non ciblés compris) ; si seulement une partie l'est, les autres effets se résolvent quand même.
        $targets = $this->targetsOfEffects($card, $item->targets);
        $anyLegal = $targets === [];
        foreach ($targets as $i => $target) {
            if ($this->targetLegal($s, $card->effects[$i], $target, $item->controller)) {
                $anyLegal = true;
            }
        }

        // Effets dans l'ordre du texte (CR 608.2c). Une sélection (Brainstorm, Ponder) suspend la résolution : les pioches qui
        // suivent attendent la décision (`GameState::$selection`, `draw`).
        foreach ($card->effects as $i => $effect) {
            if (!$anyLegal && !$effect->isTargeted()) {
                continue;
            }
            if ($s->selection !== null) {
                if ($effect->effect !== 'draw') {
                    throw new \LogicException("Effet « {$effect->effect} » après une sélection de cartes");
                }
                $s->selection['draw'] += $effect->amount;
                continue;
            }
            $target = $targets[$i] ?? '';
            match ($effect->effect) {
                'damage' => $this->damage($s, $target, $card->manaCost()->hasX() ? $item->x : $effect->amount, $card->name),
                'counter' => $this->counter($s, $target, $item, $effect, $paid),
                'mana' => $this->addMana($s, $item->controller, $effect->adds ?? '', $item->id),
                'treasure' => $s->createTreasure($item->controller),
                'draw' => $this->draw($s, $item->controller, $effect->amount),
                'exile' => $this->exileTarget($s, $target, $effect, $card->name),
                'tutor' => $this->tutor($s, $item->controller, $target, $effect, $card->name),
                'copy' => $this->copy($s, $item->controller, $target),
                'scry' => null, // rangement déjà décidé avant la résolution (PriorityManager::scry)
                'put_back' => $this->startSelection($s, $item->controller, 'put_back', min($effect->amount, count($s->player($item->controller)->hand)), $card->name),
                'arrange' => $this->startSelection($s, $item->controller, 'arrange', min($effect->amount, count($s->player($item->controller)->library)), $card->name),
                'pact' => $s->addDelayedTrigger($item->controller, $card, new \App\Engine\Ability\TriggeredAbility(\App\Engine\Ability\TriggeredAbility::NEXT_UPKEEP, 'pact', spec: (string) $effect->payCost)),
            };
        }
    }

    /** Ouvre une sélection de cartes (voir `GameState::$selection`) ; rien à choisir (`$count` nul) : aucune décision. */
    private function startSelection(GameState $s, int $player, string $kind, int $count, string $source): void
    {
        if ($count > 0) {
            $s->selection = ['player' => $player, 'kind' => $kind, 'count' => $count, 'source' => $source, 'draw' => 0];
        }
    }

    /**
     * Cible de chaque effet ciblé, indexée par la position de l'effet dans `$card->effects` (les cibles sont données dans
     * l'ordre des effets ciblés, voir `LegalActionGenerator::targetSets()`).
     *
     * @param list<string> $targets
     * @return array<int, string>
     */
    private function targetsOfEffects(CardInstance $card, array $targets): array
    {
        $out = [];
        $k = 0;
        foreach ($card->effects as $i => $effect) {
            if ($effect->isTargeted()) {
                $out[$i] = $targets[$k++] ?? '';
            }
        }

        return $out;
    }

    /** La cible est-elle encore légale pour son effet à la résolution (CR 608.2b) ? Aucun événement émis. */
    private function targetLegal(GameState $s, SpellEffect $effect, string $target, int $controller): bool
    {
        return match ($effect->effect) {
            'damage' => str_starts_with($target, 'player:') && !$s->player((int) substr($target, 7))->lost,
            'counter' => $this->counterVictim($s, $target) !== null,
            'exile' => $this->targetPermanent($s, $target, $effect) !== null,
            'copy' => $this->copyTarget($s, $target, $controller) !== null,
            default => true, // recherche de bibliothèque : une cible absente ne fait simplement rien
        };
    }

    /** Le sort visé par un contresort (`stack:<id>`), s'il est encore sur la pile et n'est pas une capacité. */
    private function counterVictim(GameState $s, string $target): ?\App\Engine\Game\StackItem
    {
        $victim = str_starts_with($target, 'stack:') ? $s->findStackItem(substr($target, 6)) : null;

        return $victim === null || $victim->isAbility() ? null : $victim;
    }

    /**
     * Le contrôleur et le permanent ciblés (`permanent:<id>`), s'ils sont encore sur le champ de bataille et légaux pour `$effect`
     * (créature seulement si `target` = `creature`).
     *
     * @return array{int, CardInstance}|null
     */
    private function targetPermanent(GameState $s, string $target, SpellEffect $effect): ?array
    {
        $id = str_starts_with($target, 'permanent:') ? substr($target, 10) : null;
        foreach ($s->players as $p) {
            $card = $id === null || $p->lost ? null : $p->findOnBattlefield($id);
            if ($card !== null && $effect->canTargetPermanent($card)) {
                return [$p->id, $card];
            }
        }

        return null;
    }

    /**
     * Exile le permanent ciblé (`permanent:<id>`) s'il est encore sur le champ de bataille et toujours une cible légale
     * (créature pour `target: creature`) ; sinon `SpellFizzled`.
     */
    private function exileTarget(GameState $s, string $target, SpellEffect $effect, string $source): void
    {
        $hit = $this->targetPermanent($s, $target, $effect);
        if ($hit === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        [$owner, $card] = $hit;
        $power = $card->currentPower();
        $s->exilePermanent($owner, $card->id, $source);
        if ($effect->powerLife) {
            $s->gainLife($owner, $power, $source); // Swords to Plowshares : la force est celle d'avant l'exil
        }
    }

    /** La créature ciblée (`permanent:<id>`) si elle est encore sur le champ de bataille sous le contrôle du lanceur (CR 608.2b). */
    private function copyTarget(GameState $s, string $target, int $controller): ?CardInstance
    {
        $card = str_starts_with($target, 'permanent:') ? $s->player($controller)->findOnBattlefield(substr($target, 10)) : null;

        return $card !== null && $card->isCreature() ? $card : null;
    }

    /** Crée un jeton copie de la créature ciblée (CR 707.2) ; si elle a disparu, `SpellFizzled`. */
    private function copy(GameState $s, int $controller, string $target): void
    {
        $source = $this->copyTarget($s, $target, $controller);
        if ($source === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        $s->createTokenCopy($controller, $source);
    }

    /** Rituel : le mana arrive dans la réserve du contrôleur (il disparaît à la fin de l'étape). */
    private function addMana(GameState $s, int $player, string $adds, string $stackId): void
    {
        $cost = ManaCost::parse($adds);
        $p = $s->player($player);
        foreach ($cost->colored as $symbol => $n) {
            $p->pool->add(Color::fromSymbol($symbol), $n);
        }
        $s->emit('ManaAdded', ['player' => $player, 'mana' => $cost->colored, 'pool' => $p->pool->toArray()] + $p->pool->restrictedField() + ['source' => $stackId]);
    }

    /**
     * Fetchland : le terrain choisi à l'activation (`library:<id>`, suivi de `life` pour payer une shockland) est cherché s'il est
     * encore dans la bibliothèque et convient ; sinon la recherche échoue (permis, CR 701.19b). La bibliothèque est mélangée
     * dans tous les cas (`LibraryShuffled`).
     *
     * @param list<string> $targets
     */
    private function fetch(GameState $s, int $player, ActivatedAbility $ability, array $targets): void
    {
        $p = $s->player($player);
        $id = substr($targets[0] ?? '', strlen('library:'));
        foreach ($p->library as $i => $card) {
            if ($card->id === $id && $ability->fetches($card)) {
                array_splice($p->library, $i, 1);
                $s->putLandOntoBattlefield($player, $card, in_array('life', $targets, true), 'LandFetched', $ability->fetchSpec()['tapped']);
                break;
            }
        }
        $p->library = $s->rng->shuffle($p->library);
        $s->emit('LibraryShuffled', ['player' => $player, 'library' => count($p->library)]);
    }

    /**
     * Tuteur (CR 701.19) : la carte cherchée va en main, ou sur le dessus de la bibliothèque après un mélange (`tutorTop`).
     * La bibliothèque est mélangée (`CardTutored` avec `to`, `LibraryShuffled`). Les PV du tuteur (`lifeLoss`) se perdent même si rien n'est trouvé.
     */
    private function tutor(GameState $s, int $player, string $target, SpellEffect $effect, string $source): void
    {
        $p = $s->player($player);
        $id = substr($target, strlen('library:'));
        foreach ($p->library as $i => $card) {
            if ($card->id === $id) {
                array_splice($p->library, $i, 1);
                $p->library = $s->rng->shuffle($p->library);
                if ($effect->tutorTop) {
                    array_unshift($p->library, $card);
                } else {
                    $p->hand[] = $card;
                }
                $s->emit('CardTutored', ['player' => $player, 'card' => $card->toPublic(), 'library' => count($p->library), 'to' => $effect->tutorTop ? 'top' : 'hand']);
                $s->emit('LibraryShuffled', ['player' => $player, 'library' => count($p->library)]);
                break;
            }
        }
        $s->loseLife($player, $effect->lifeLoss, $source);
    }

    /**
     * Tymna the Weaver : X = adversaires du contrôleur blessés au combat ce tour (compté à la résolution) ; il paie X PV et pioche
     * X cartes. Le « you may » est choisi si X > 0 et qu'il garde au moins 1 PV (CR 119.4 permettrait d'aller à 0).
     */
    private function payLifeDraw(GameState $s, int $player, string $source): void
    {
        $x = count(array_filter($s->combatDamaged, static fn (int $id): bool => $id !== $player));
        if ($x === 0 || $s->player($player)->life <= $x) {
            return;
        }
        $s->payLife($player, $x, $source);
        $this->draw($s, $player, $x);
    }

    /** Pioche : une bibliothèque vide fait perdre le joueur à la prochaine vérification des SBA (CR 704.5b). */
    private function draw(GameState $s, int $player, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            if (!$s->drawCard($player)) {
                return;
            }
        }
    }

    private function tokens(GameState $s, int $player, string $spec, int $amount): void
    {
        ['name' => $name, 'power' => $power, 'toughness' => $toughness] = ActivatedAbility::parseTokenSpec($spec);
        for ($i = 0; $i < $amount; $i++) {
            $s->createToken($player, $name, CardInstance::CREATURE, $power, $toughness);
        }
    }

    private function treasures(GameState $s, int $player, int $amount): void
    {
        for ($i = 0; $i < $amount; $i++) {
            $s->createTreasure($player);
        }
    }

    /** Dégage la source si elle est encore sur le champ de bataille (Grim Monolith, Mana Vault) ; `PermanentsUntapped`. */
    private function untap(GameState $s, int $player, string $cardId): void
    {
        $card = $s->player($player)->findOnBattlefield($cardId);
        if ($card === null || !$card->tapped) {
            return;
        }
        $card->tapped = false;
        $s->emit('PermanentsUntapped', ['player' => $player, 'cards' => [$cardId]]);
    }

    /**
     * Dégâts de la source à son contrôleur (Mana Vault). Clause « si engagée » (CR 603.4) revérifiée à la résolution :
     * la source doit être encore sur le champ de bataille et engagée.
     */
    private function selfDamage(GameState $s, int $player, string $cardId, \App\Engine\Ability\TriggeredAbility $trigger): void
    {
        $card = $s->player($player)->findOnBattlefield($cardId);
        if ($trigger->filter === \App\Engine\Ability\TriggeredAbility::TAPPED && ($card === null || !$card->tapped)) {
            return;
        }
        $s->damageSelf($player, $trigger->amount, $card?->name ?? $cardId);
    }

    /**
     * Imprint (Chrome Mox, CR 607.2) : la carte choisie (`card:<id>`) quitte la main pour l'exil si elle y est encore et reste
     * éligible (non-artefact, non-terrain) ; le permanent retient ses couleurs (`CardImprinted`). Source partie : rien ne se passe.
     */
    private function imprint(GameState $s, int $player, string $sourceId, string $target): void
    {
        $p = $s->player($player);
        $source = $p->findOnBattlefield($sourceId);
        $card = str_starts_with($target, 'card:') ? $p->findInHand(substr($target, 5)) : null;
        if ($source === null || $card === null || $card->isLand() || $card->hasType(CardInstance::ARTIFACT)) {
            return;
        }
        $p->takeFromHand($card->id);
        $s->exileFromHand($player, $card, $source->name);
        $source->imprint = $card->colors();
        $s->emit('CardImprinted', ['player' => $player, 'card' => $source->id, 'exiled' => $card->name, 'colors' => $source->imprint]);
    }

    /** Bonus jusqu'à la fin du tour sur la source, si elle est encore sur le champ de bataille (sinon sans effet). */
    private function pump(GameState $s, int $player, string $cardId, ActivatedAbility $ability): void
    {
        $card = $s->player($player)->findOnBattlefield($cardId);
        if ($card === null) {
            return;
        }
        [$power, $toughness] = $ability->boost();
        $card->powerBonus += $power;
        $card->toughnessBonus += $toughness;
        $s->emit('CreatureBoosted', ['player' => $player, 'card' => $card->id, 'power' => $card->currentPower(), 'toughness' => $card->currentToughness()]);
    }

    private function damage(GameState $s, string $target, int $amount, string $source): void
    {
        if (!str_starts_with($target, 'player:')) {
            return;
        }
        $victim = $s->player((int) substr($target, 7));
        if ($victim->lost) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        $victim->life -= $amount;
        $s->emit('DamageDealt', ['source' => $source, 'target' => $target, 'amount' => $amount, 'life' => $victim->life]);
    }

    /**
     * Contre le sort visé. « Sauf si son contrôleur paie » (`payCost`) : rien si `$paid`. Destination : cimetière, exil
     * (`counterTo: exile`, Force of Negation) ou dessus de la bibliothèque (`library_top`, Memory Lapse ; un commandant va
     * alors en zone de commandement, CR 903.9b, choix toujours fait). Mana Drain (`drainMana`) : trigger retardé qui ajoutera
     * {C} égal à la valeur de mana du sort contré.
     */
    private function counter(GameState $s, string $target, \App\Engine\Game\StackItem $by, SpellEffect $effect, ?bool $paid): void
    {
        $victim = $this->counterVictim($s, $target);
        if ($victim === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        if ($effect->payCost !== null && $paid === true) {
            return; // le contrôleur du sort visé a payé (`CostPaid`)
        }
        $s->stack = array_values(array_filter($s->stack, static fn ($i) => $i->id !== $victim->id));
        $owner = $s->player($victim->controller);
        $extra = [];
        if ($effect->counterTo === SpellEffect::COUNTER_EXILE) {
            $destination = $s->exile($victim->controller, $victim->card);
        } elseif ($effect->counterTo === SpellEffect::COUNTER_LIBRARY_TOP && $victim->card->commander) {
            $owner->command[] = $victim->card;
            $destination = 'command';
        } elseif ($effect->counterTo === SpellEffect::COUNTER_LIBRARY_TOP && !$victim->card->token) {
            array_unshift($owner->library, $victim->card);
            $destination = 'library';
            $extra = ['library' => count($owner->library)];
        } else {
            $destination = $s->sendToGraveyard($victim->controller, $victim->card);
        }
        $s->emit('SpellCountered', [
            'stackId' => $victim->id,
            'card' => $victim->card->toPublic(),
            'controller' => $victim->controller,
            'by' => $by->id,
            'destination' => $destination, // 'graveyard' (un commandant y passe avant les SBA, CR 903.9a), 'exile', 'library', 'command'
        ] + $extra);
        if ($effect->drainMana) {
            $amount = $victim->card->manaCost()->manaValue($victim->x);
            $s->addDelayedTrigger($by->controller, $by->card, new \App\Engine\Ability\TriggeredAbility(\App\Engine\Ability\TriggeredAbility::NEXT_MAIN, 'mana', $amount));
        }
        if ($effect->token !== null) {
            // Swan Song : « si ce sort est contré ainsi, son contrôleur crée un jeton » (le sort contré, pas le contresort).
            ['name' => $name, 'power' => $power, 'toughness' => $toughness] = ActivatedAbility::parseTokenSpec($effect->token);
            $s->createToken($victim->controller, $name, CardInstance::CREATURE, $power, $toughness, keywords: $effect->tokenKeywords);
        }
    }
}
