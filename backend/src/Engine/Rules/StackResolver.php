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
    public function resolveTop(GameState $s): void
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

        // Effets dans l'ordre du texte (CR 608.2c) ; la cible unique sert l'effet ciblé.
        foreach ($card->effects as $effect) {
            match ($effect->effect) {
                'damage' => $this->damage($s, $item->targets[0] ?? '', $card->manaCost()->hasX() ? $item->x : $effect->amount, $card->name),
                'counter' => $this->counter($s, $item->targets[0] ?? '', $item->id, $effect),
                'mana' => $this->addMana($s, $item->controller, $effect->adds ?? '', $item->id),
                'treasure' => $s->createTreasure($item->controller),
                'draw' => $this->draw($s, $item->controller, $effect->amount),
                'exile' => $this->exileTarget($s, $item->targets[0] ?? '', $effect, $card->name),
            };
        }
    }

    /**
     * Exile le permanent ciblé (`permanent:<id>`) s'il est encore sur le champ de bataille et toujours une cible légale
     * (créature pour `target: creature`) ; sinon `SpellFizzled`.
     */
    private function exileTarget(GameState $s, string $target, SpellEffect $effect, string $source): void
    {
        $id = str_starts_with($target, 'permanent:') ? substr($target, 10) : null;
        foreach ($s->players as $p) {
            $card = $id === null || $p->lost ? null : $p->findOnBattlefield($id);
            if ($card !== null && $effect->canTargetPermanent($card)) {
                $power = $card->currentPower();
                $s->exilePermanent($p->id, $id, $source);
                if ($effect->powerLife) {
                    $s->gainLife($p->id, $power, $source); // Swords to Plowshares : la force est celle d'avant l'exil
                }

                return;
            }
        }
        $s->emit('SpellFizzled', ['target' => $target]);
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

    private function counter(GameState $s, string $target, string $byStackId, SpellEffect $effect): void
    {
        $victim = str_starts_with($target, 'stack:') ? $s->findStackItem(substr($target, 6)) : null;
        if ($victim === null || $victim->isAbility()) {
            $s->emit('SpellFizzled', ['target' => $target]);

            return;
        }
        $s->stack = array_values(array_filter($s->stack, static fn ($i) => $i->id !== $victim->id));
        $destination = $s->sendToGraveyard($victim->controller, $victim->card);
        $s->emit('SpellCountered', [
            'stackId' => $victim->id,
            'card' => $victim->card->toPublic(),
            'controller' => $victim->controller,
            'by' => $byStackId,
            'destination' => $destination, // 'graveyard' (un commandant y passe avant les SBA, CR 903.9a)
        ]);
        if ($effect->token !== null) {
            // Swan Song : « si ce sort est contré ainsi, son contrôleur crée un jeton » (le sort contré, pas le contresort).
            ['name' => $name, 'power' => $power, 'toughness' => $toughness] = ActivatedAbility::parseTokenSpec($effect->token);
            $s->createToken($victim->controller, $name, CardInstance::CREATURE, $power, $toughness, keywords: $effect->tokenKeywords);
        }
    }
}
