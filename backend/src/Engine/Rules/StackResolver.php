<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\ActivatedAbility;
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
                'pump' => $this->pump($s, $item->controller, $card->id, $item->ability),
                default => null,
            };

            return;
        }

        // Permanent -> champ de bataille ; sinon cimetière (ou zone de commandement pour un commandant).
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

        match ($card->effect) {
            'damage' => $this->damage($s, $item->targets[0] ?? '', $card->manaCost()->hasX() ? $item->x : $card->amount, $card->name),
            'counter' => $this->counter($s, $item->targets[0] ?? '', $item->id),
            'mana' => $this->addMana($s, $item->controller, $card->adds ?? '', $item->id),
            'treasure' => $s->createTreasure($item->controller),
            default => null,
        };
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

    /** Pioche : une bibliothèque vide élimine le joueur (comme à l'étape de pioche). */
    private function draw(GameState $s, int $player, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            if (!$s->drawCard($player)) {
                (new StateBasedActions())->eliminate($s, $player, 'decked');

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

    private function counter(GameState $s, string $target, string $byStackId): void
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
            'destination' => $destination, // 'graveyard' | 'command'
        ]);
    }
}
