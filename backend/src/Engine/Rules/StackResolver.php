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
        foreach ($top->effects() as $effect) {
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
                'sacrifice_self' => $this->sacrificeSelf($s, $item->controller, $card->id),
                'tutor' => $this->tutorTrigger($s, $item->controller, $item->targets[0] ?? '', $item->trigger, $card->name),
                'bounce' => $this->bounceAbilityTarget($s, $item->targets[0] ?? '', $card->name),
                'lock_noncreature' => $this->lockNoncreature($s, $item->controller, $card->name),
                'pact' => $paid === true ? null : (new StateBasedActions())->eliminate($s, $item->controller, 'pact_unpaid'),
                'draw_unless' => $paid === true ? null : $this->draw($s, $item->controller, $ability->amount), // Rhystic Study
                'pay_life_draw' => $this->payLifeDraw($s, $item->controller, $card->name), // Tymna the Weaver
                'storm' => $this->stormCopies($s, $item),
                'counter_spell' => $this->counter($s, $item->targets[0] ?? '', $item, new SpellEffect('counter'), null), // Lavinia
                'mobilize' => $this->mobilize($s, $item),
                'sacrifice_tokens' => $this->sacrificeTokens($s, $item->controller, $ability->spec),
                'pump_untap' => $this->pumpUntap($s, $item->controller, $ability->spec), // Valley Floodcaller
                default => null,
            };

            return;
        }

        // Permanent -> champ de bataille ; sinon cimetière de son propriétaire. Une copie cesse d'exister (CR 707.10a).
        if ($item->copy) {
            $destination = 'none';
        } elseif ($card->isPermanent()) {
            $s->enterBattlefield($item->controller, $card);
            $destination = 'battlefield';
        } else {
            $destination = $s->sendToGraveyard($item->ownerId(), $card);
        }

        $s->emit('SpellResolved', [
            'stackId' => $item->id,
            'card' => $card->toPublic(),
            'controller' => $item->controller,
            'destination' => $destination,
        ] + ($item->ownerId() !== $item->controller ? ['owner' => $item->ownerId()] : []));

        // CR 608.2b : une cible par effet ciblé, dans l'ordre du texte. Si TOUTES les cibles sont devenues illégales, le sort ne
        // fait rien (ses effets non ciblés compris) ; si seulement une partie l'est, les autres effets se résolvent quand même.
        // Le joueur d'un Gift n'est pas une cible (CR 702.174) : il ne compte pas, et sans cible légale il ne reçoit rien.
        $effects = array_values(array_filter($item->effects(), static fn (SpellEffect $e) => !$e->kicked || $item->kicked));
        $targets = $this->targetsOfEffects($effects, $item->targets);
        $anyLegal = array_filter(array_keys($targets), static fn (int $i) => $effects[$i]->effect !== 'gift') === [];
        foreach ($targets as $i => $target) {
            if ($effects[$i]->effect !== 'gift' && $this->targetLegal($s, $effects[$i], $target, $item->controller)) {
                $anyLegal = true;
            }
        }

        // Effets dans l'ordre du texte (CR 608.2c). Une sélection (Brainstorm, Ponder) suspend la résolution : les pioches qui
        // suivent attendent la décision (`GameState::$selection`, `draw`).
        foreach ($effects as $i => $effect) {
            if (!$anyLegal && (!$effect->isTargeted() || $effect->effect === 'gift')) {
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
                'mana' => $this->addMana($s, $item->controller, $this->manaProduced($s, $item->controller, $card, $effect), $item->id),
                'grant_sac_mana' => $this->grantSacrificeMana($s, $item->controller, (string) $effect->adds),
                'bounce' => $this->bounceTarget($s, $target, $effect, $item),
                'destroy' => $this->destroyTarget($s, $target, $effect, $card->name),
                'treasure' => $s->createTreasure($item->controller),
                'draw' => $this->draw($s, $item->controller, $effect->amount),
                'exile' => $this->exileTarget($s, $target, $effect, $card->name),
                'tutor' => $this->tutor($s, $item->controller, $target, $effect, $card->name),
                'copy' => $this->copy($s, $item->controller, $target),
                'scry' => null, // rangement déjà décidé avant la résolution (PriorityManager::scry)
                'put_back' => $this->startSelection($s, $item->controller, 'put_back', min($effect->amount, count($s->player($item->controller)->hand)), $card->name),
                'arrange' => $this->startSelection($s, $item->controller, 'arrange', min($effect->amount, count($s->player($item->controller)->library)), $card->name),
                'pact' => $s->addDelayedTrigger($item->controller, $card, new \App\Engine\Ability\TriggeredAbility(\App\Engine\Ability\TriggeredAbility::NEXT_UPKEEP, 'pact', spec: (string) $effect->payCost)),
                'redirect' => $this->redirect($s, $target, $effect, $item),
                'steal' => $this->steal($s, $target, $effect, $item),
                'exile_spells' => $this->exileSpells($s, $target, $item),
                'mill' => $this->millTarget($s, $target, $effect->amount, $card->name),
                'silence' => $this->lockCasts($s, array_values(array_filter($s->alivePlayerIds(), static fn (int $id) => $id !== $item->controller)), $card->name),
                'silence_target' => $this->lockCasts($s, $this->livingPlayer($s, $target) === null ? [] : [(int) $this->livingPlayer($s, $target)], $card->name),
                'no_attacks' => $this->lockAttacks($s, $card->name),
                'flash_grant' => $this->grantFlash($s, $item->controller, $card->name),
                'gift' => $this->gift($s, $target, (string) $effect->token, $card->name),
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
     * @param list<SpellEffect> $effects
     * @param list<string> $targets
     * @return array<int, string>
     */
    private function targetsOfEffects(array $effects, array $targets): array
    {
        $out = [];
        $k = 0;
        foreach ($effects as $i => $effect) {
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
            'exile', 'bounce', 'destroy' => $this->targetPermanent($s, $target, $effect, $controller) !== null,
            'copy' => $this->copyTarget($s, $target, $controller) !== null,
            'redirect' => $this->stackObject($s, explode('>', $target, 2)[0]) !== null,
            'steal' => ($v = $this->counterVictim($s, $target)) !== null && $effect->canCounter($v->card, $v->x),
            'exile_spells' => array_filter(self::spellIds($target), fn (string $id) => $this->counterVictim($s, 'stack:' . $id) !== null) !== [],
            'mill', 'silence_target' => $this->livingPlayer($s, $target) !== null,
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
    private function targetPermanent(GameState $s, string $target, SpellEffect $effect, ?int $caster = null): ?array
    {
        $id = str_starts_with($target, 'permanent:') ? substr($target, 10) : null;
        foreach ($s->players as $p) {
            $card = $id === null || $p->lost ? null : $p->findOnBattlefield($id);
            if ($card !== null && $effect->canTargetPermanent($card, $p->id, $caster)) {
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
        $hit = $this->targetPermanent($s, $target, $effect); // aucun motif `opponent_…` pour un exil
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

    /**
     * Renvoie le permanent ciblé dans la main de son propriétaire (Snapback, Into the Flood Maw) s'il est encore là et légal ;
     * sinon `SpellFizzled`. Chain of Vapor (`chain`) : son contrôleur peut ensuite sacrifier un terrain pour copier le sort,
     * décision hors priorité (`GameState::$selection`, kind `chain`), proposée s'il a un terrain et qu'il reste une cible.
     */
    private function bounceTarget(GameState $s, string $target, SpellEffect $effect, \App\Engine\Game\StackItem $item): void
    {
        $hit = $this->targetPermanent($s, $target, $effect, $item->controller);
        if ($hit === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        [$controller, $card] = $hit;
        $s->returnToHand($controller, $card->id, $item->card->name);
        if (!$effect->chain || $s->player($controller)->lost) {
            return;
        }
        $hasLand = array_filter($s->player($controller)->battlefield, static fn (CardInstance $c) => $c->isLand()) !== [];
        $hasTarget = false;
        foreach ($s->alivePlayerIds() as $id) {
            foreach ($s->player($id)->battlefield as $permanent) {
                $hasTarget = $hasTarget || !$permanent->isLand();
            }
        }
        if ($hasLand && $hasTarget) {
            $s->selection = ['player' => $controller, 'kind' => 'chain', 'count' => 1, 'source' => $item->card->name, 'draw' => 0, 'card' => $item->card->toArray()];
        }
    }

    /** Le joueur en vie désigné par `player:<id>`, ou null. */
    private function livingPlayer(GameState $s, string $target): ?int
    {
        if (!str_starts_with($target, 'player:')) {
            return null;
        }
        $id = (int) substr($target, 7);

        return isset($s->players[$id]) && !$s->player($id)->lost ? $id : null;
    }

    /** L'objet de pile désigné par `stack:<id>` (sort ou capacité), ou null. */
    private function stackObject(GameState $s, string $ref): ?\App\Engine\Game\StackItem
    {
        return str_starts_with($ref, 'stack:') ? $s->findStackItem(substr($ref, 6)) : null;
    }

    /** @return list<string> ids des sorts d'une cible de Mindbreak Trap (`stack:<id>+<id>…`) */
    private static function spellIds(string $target): array
    {
        return str_starts_with($target, 'stack:') ? explode('+', substr($target, 6)) : [];
    }

    /**
     * Deflecting Swat, Misdirection : l'objet de pile visé reçoit la nouvelle cible choisie au lancement (`stack:<id>><cible>`),
     * si elle est encore légale pour lui (CR 115.7 : sinon il garde sa cible) ; `TargetChanged`.
     */
    private function redirect(GameState $s, string $target, SpellEffect $effect, \App\Engine\Game\StackItem $by): void
    {
        [$ref, $new] = explode('>', $target, 2) + [1 => ''];
        $victim = $this->stackObject($s, $ref);
        if ($victim === null || ($effect->target === 'spell' && $victim->isAbility())) {
            $s->emit('SpellFizzled', ['target' => $ref]);

            return;
        }
        if (!in_array($new, (new LegalActionGenerator())->retargetCandidates($s, $victim), true)) {
            return;
        }
        $s->replaceStackItem($victim->with(targets: [$new]));
        $s->emit('TargetChanged', ['stackId' => $victim->id, 'targets' => [$new], 'by' => $by->id, 'source' => $by->card->name]);
    }

    /** Commandeer : le lanceur prend le contrôle du sort ciblé (il garde ses cibles) ; `SpellControlChanged`. */
    private function steal(GameState $s, string $target, SpellEffect $effect, \App\Engine\Game\StackItem $by): void
    {
        $victim = $this->counterVictim($s, $target);
        if ($victim === null || !$effect->canCounter($victim->card, $victim->x)) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        if ($victim->controller === $by->controller) {
            return;
        }
        $s->replaceStackItem($victim->with(controller: $by->controller));
        $s->emit('SpellControlChanged', ['stackId' => $victim->id, 'controller' => $by->controller, 'previous' => $victim->controller, 'source' => $by->card->name]);
    }

    /** Mindbreak Trap : chaque sort ciblé encore sur la pile est exilé (une copie cesse d'exister) ; `SpellExiled`. */
    private function exileSpells(GameState $s, string $target, \App\Engine\Game\StackItem $by): void
    {
        foreach (self::spellIds($target) as $id) {
            $victim = $this->counterVictim($s, 'stack:' . $id);
            if ($victim === null) {
                continue;
            }
            $s->stack = array_values(array_filter($s->stack, static fn ($i) => $i->id !== $victim->id));
            $destination = $victim->copy ? 'none' : $s->exile($victim->ownerId(), $victim->card);
            $s->emit('SpellExiled', ['stackId' => $victim->id, 'card' => $victim->card->toPublic(), 'controller' => $victim->controller,
                'owner' => $victim->ownerId(), 'by' => $by->id, 'destination' => $destination]);
        }
    }

    /** Brain Freeze : le joueur ciblé meule (`CardsMilled`). */
    private function millTarget(GameState $s, string $target, int $amount, string $source): void
    {
        $player = $this->livingPlayer($s, $target);
        if ($player === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        $s->mill($player, $amount, $source);
    }

    /**
     * Silence, Orim's Chant : ces joueurs ne peuvent pas lancer de sorts ce tour (`GameState::$castLocks`) ; `CastLockApplied`.
     *
     * @param list<int> $players
     */
    private function lockCasts(GameState $s, array $players, string $source): void
    {
        foreach ($players as $id) {
            if (!in_array($id, $s->castLocks, true)) {
                $s->castLocks[] = $id;
            }
        }
        sort($s->castLocks);
        $s->emit('CastLockApplied', ['players' => $players, 'source' => $source]);
    }

    /** Orim's Chant avec kicker : les créatures ne peuvent pas attaquer ce tour ; `AttacksLocked`. */
    private function lockAttacks(GameState $s, string $source): void
    {
        $s->attackLocked = true;
        $s->emit('AttacksLocked', ['source' => $source]);
    }

    /** Borne Upon a Wind : le joueur lance ses sorts comme s'ils avaient le flash ce tour ; `FlashGranted`. */
    private function grantFlash(GameState $s, int $player, string $source): void
    {
        if (!in_array($player, $s->flashGrants, true)) {
            $s->flashGrants[] = $player;
            sort($s->flashGrants);
        }
        $s->emit('FlashGranted', ['player' => $player, 'source' => $source]);
    }

    /** Gift (Into the Flood Maw) : l'adversaire choisi crée le jeton promis, engagé (`TokenCreated`). */
    private function gift(GameState $s, string $target, string $token, string $source): void
    {
        $player = $this->livingPlayer($s, $target);
        if ($player === null) {
            return;
        }
        ['name' => $name, 'power' => $power, 'toughness' => $toughness] = ActivatedAbility::parseTokenSpec($token);
        $s->createToken($player, $name, CardInstance::CREATURE, $power, $toughness, subtypes: [CardInstance::CREATURE => [$name]], tapped: true);
    }

    /**
     * Spec du trigger Storm : id de pile du sort, mode, X et cibles (`s3;0;0;stack:s1|player:2`), pour copier le sort tel qu'il
     * a été lancé même s'il a quitté la pile (information de dernière connaissance).
     */
    public static function stormSpec(\App\Engine\Game\StackItem $item): string
    {
        return implode(';', [$item->id, $item->mode, $item->x, implode('|', $item->targets)]);
    }

    /** Storm : `amount` copies du sort, avec ses cibles (CR 702.40, 707.10 ; elles ne sont pas lancées) ; `SpellCopied` chacune. */
    private function stormCopies(GameState $s, \App\Engine\Game\StackItem $item): void
    {
        [$original, $mode, $x, $targets] = explode(';', $item->trigger->spec, 4) + [3 => ''];
        $targets = $targets === '' ? [] : explode('|', $targets);
        for ($i = 0; $i < $item->trigger->amount; $i++) {
            $copy = new \App\Engine\Game\StackItem('s' . $s->nextStackId++, clone $item->card, $item->controller, $targets, (int) $x, mode: (int) $mode, copy: true);
            $s->stack[] = $copy;
            $s->emit('SpellCopied', ['player' => $item->controller, 'card' => $copy->card->toPublic(), 'stackId' => $copy->id, 'targets' => $targets, 'source' => $item->card->name, 'copyOf' => $original]);
        }
    }

    /**
     * Mobilize (Voice of Victory) : `amount` jetons Warrior 1/1 engagés et attaquants (même défenseur que la source, sinon le
     * premier adversaire en vie), sacrifiés au début de la prochaine étape de fin (trigger retardé `next_end`).
     */
    private function mobilize(GameState $s, \App\Engine\Game\StackItem $item): void
    {
        $defender = $s->combat->attackers[$item->card->id] ?? null;
        if ($defender === null || $s->player($defender)->lost) {
            $defender = null;
            foreach ($s->alivePlayerIds() as $id) {
                $defender ??= $id !== $item->controller ? $id : null;
            }
        }
        if ($defender === null) {
            return;
        }
        $ids = [];
        for ($i = 0; $i < $item->trigger->amount; $i++) {
            $token = $s->createToken($item->controller, 'Warrior', CardInstance::CREATURE, 1, 1, subtypes: [CardInstance::CREATURE => ['Warrior']], tapped: true, attacking: $defender);
            $ids[] = $token->id;
        }
        $s->addDelayedTrigger($item->controller, $item->card, new \App\Engine\Ability\TriggeredAbility(\App\Engine\Ability\TriggeredAbility::NEXT_END, 'sacrifice_tokens', spec: implode(',', $ids)));
    }

    /** Sacrifie ceux des jetons `$spec` (ids séparés par des virgules) encore sur le champ de bataille du joueur. */
    private function sacrificeTokens(GameState $s, int $player, string $spec): void
    {
        $ids = array_values(array_filter(explode(',', $spec), static fn (string $id) => $s->player($player)->findOnBattlefield($id) !== null));
        if ($ids !== []) {
            $s->sacrifice($player, $ids);
        }
    }

    /** Valley Floodcaller : ses créatures des sous-types `$spec` gagnent +1/+1 jusqu'à la fin du tour et se dégagent. */
    private function pumpUntap(GameState $s, int $player, string $spec): void
    {
        $untapped = [];
        foreach ($s->player($player)->battlefield as $card) {
            if (!$card->isCreature() || array_filter(explode('|', $spec), static fn (string $t) => $card->hasSubtype($t, CardInstance::CREATURE)) === []) {
                continue;
            }
            $card->powerBonus++;
            $card->toughnessBonus++;
            $s->emit('CreatureBoosted', ['player' => $player, 'card' => $card->id, 'power' => $card->currentPower(), 'toughness' => $card->currentToughness()]);
            if ($card->tapped) {
                $card->tapped = false;
                $untapped[] = $card->id;
            }
        }
        if ($untapped !== []) {
            $s->emit('PermanentsUntapped', ['player' => $player, 'cards' => $untapped]);
        }
    }

    /** Channel (Otawara) : le permanent ciblé (hors terrain) retourne dans la main de son propriétaire ; sinon `SpellFizzled`. */
    private function bounceAbilityTarget(GameState $s, string $target, string $source): void
    {
        $id = str_starts_with($target, 'permanent:') ? substr($target, 10) : null;
        foreach ($s->players as $p) {
            $card = $id === null || $p->lost ? null : $p->findOnBattlefield($id);
            if ($card !== null && !$card->isLand()) {
                $s->returnToHand($p->id, $card->id, $source);

                return;
            }
        }
        $s->emit('SpellFizzled', ['target' => $target]);
    }

    /** Détruit le permanent ciblé s'il est encore là ; `blue: if` (Pyroblast) : seulement s'il est bleu. */
    private function destroyTarget(GameState $s, string $target, SpellEffect $effect, string $source): void
    {
        $hit = $this->targetPermanent($s, $target, $effect);
        if ($hit === null) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        if ($effect->blue === SpellEffect::BLUE_IF && !$hit[1]->isBlueColored()) {
            return;
        }
        $s->destroyPermanent($hit[0], $hit[1]->id, $source);
    }

    /** Rain of Filth : les terrains du joueur gagnent « sacrifier ce terrain : ajoutez … » jusqu'à la fin du tour (`AbilitiesGranted`). */
    private function grantSacrificeMana(GameState $s, int $player, string $adds): void
    {
        $ability = ActivatedAbility::sacrificeMana($adds);
        $ids = [];
        foreach ($s->player($player)->battlefield as $card) {
            if ($card->isLand()) {
                $card->grantedAbilities[] = $ability;
                $ids[] = $card->id;
            }
        }
        $s->emit('AbilitiesGranted', ['player' => $player, 'cards' => $ids, 'ability' => $ability->toArray()]);
    }

    /** City of Traitors : la source se sacrifie si elle est encore sur le champ de bataille. */
    private function sacrificeSelf(GameState $s, int $player, string $cardId): void
    {
        if ($s->player($player)->findOnBattlefield($cardId) !== null) {
            $s->sacrifice($player, [$cardId]);
        }
    }

    /** Ranger-Captain of Eos : les adversaires de `$player` ne peuvent pas lancer de sort non-créature jusqu'à la fin du tour. */
    private function lockNoncreature(GameState $s, int $player, string $source): void
    {
        if (!in_array($player, $s->noncreatureLocks, true)) {
            $s->noncreatureLocks[] = $player;
        }
        $s->emit('NoncreatureLockApplied', ['player' => $player, 'source' => $source]);
    }

    /** Tuteur à l'entrée : la carte choisie (`library:<id>`) doit convenir au filtre du trigger, sinon rien n'est trouvé. */
    private function tutorTrigger(GameState $s, int $player, string $target, \App\Engine\Ability\TriggeredAbility $trigger, string $source): void
    {
        $id = substr($target, strlen('library:'));
        foreach ($s->player($player)->library as $card) {
            if ($card->id === $id && $trigger->tutorMatches($card)) {
                $this->tutor($s, $player, $target, new SpellEffect('tutor'), $source);

                return;
            }
        }
    }

    /**
     * Mana d'un rituel : `adds`, ou `thresholdAdds` avec sept cartes ou plus au cimetière (Cabal Ritual), plus un mana du premier
     * symbole par carte homonyme dans chaque cimetière (Rite of Flame). Le sort lui-même est déjà au cimetière à ce stade, mais
     * il est encore sur la pile pendant sa résolution : il ne compte pas.
     */
    private function manaProduced(GameState $s, int $controller, CardInstance $spell, SpellEffect $effect): string
    {
        $adds = $effect->adds ?? '';
        if ($effect->thresholdAdds !== null) {
            $others = count(array_filter($s->player($controller)->graveyard, static fn (CardInstance $c) => $c !== $spell));
            return $others >= 7 ? $effect->thresholdAdds : $adds;
        }
        if ($effect->perGraveyardNamed && preg_match('/^\{[WUBRGC]\}/', $adds, $m)) {
            $n = 0;
            foreach ($s->players as $p) {
                $n += count(array_filter($p->graveyard, static fn (CardInstance $c) => $c->name === $spell->name && $c !== $spell));
            }

            return $adds . str_repeat($m[0], $n);
        }

        return $adds;
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
        if ($effect->discardRandom) {
            // Gamble : cherche, puis défausse une carte au hasard de sa main (celle qui vient d'être trouvée comprise).
            $effect = new SpellEffect('tutor', tutorTop: $effect->tutorTop, tutorFilter: $effect->tutorFilter, lifeLoss: $effect->lifeLoss);
            $this->tutor($s, $player, $target, $effect, $source);
            if ($p->hand !== []) {
                $s->discard($player, $p->hand[$s->rng->int(0, count($p->hand) - 1)]->id);
            }

            return;
        }
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
        if ($effect->blue === SpellEffect::BLUE_IF && !$victim->card->isBlueColored()) {
            return; // Pyroblast : « counter target spell if it's blue »
        }
        if ($effect->payCost !== null && $paid === true) {
            return; // le contrôleur du sort visé a payé (`CostPaid`)
        }
        $s->stack = array_values(array_filter($s->stack, static fn ($i) => $i->id !== $victim->id));
        $owner = $s->player($victim->ownerId());
        $extra = $victim->ownerId() !== $victim->controller ? ['owner' => $victim->ownerId()] : [];
        if ($victim->copy) {
            $destination = 'none'; // une copie cesse d'exister (CR 707.10a)
        } elseif ($effect->counterTo === SpellEffect::COUNTER_EXILE) {
            $destination = $s->exile($victim->ownerId(), $victim->card);
        } elseif ($effect->counterTo === SpellEffect::COUNTER_LIBRARY_TOP && $victim->card->commander) {
            $owner->command[] = $victim->card;
            $destination = 'command';
        } elseif ($effect->counterTo === SpellEffect::COUNTER_LIBRARY_TOP && !$victim->card->token) {
            array_unshift($owner->library, $victim->card);
            $destination = 'library';
            $extra += ['library' => count($owner->library)];
        } else {
            $destination = $s->sendToGraveyard($victim->ownerId(), $victim->card);
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
