<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\GameState;
use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;

/** Résout le sommet de la pile. TODO: dispatch par carte (handlers) au lieu de flags 'damage'/'counter'. */
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
            // Capacité activée : la source reste où elle est, seuls les effets s'appliquent.
            $s->emit('AbilityResolved', [
                'stackId' => $item->id,
                'controller' => $item->controller,
                'card' => $card->toPublic(),
                'abilityIndex' => $item->abilityIndex,
            ]);
            if ($item->ability->effect === 'damage') {
                $this->damage($s, $item->targets[0] ?? '', $item->ability->amount, $card->name);
            }

            return;
        }

        // Permanent -> champ de bataille ; sinon cimetière (ou zone de commandement pour un commandant).
        if ($card->isPermanent()) {
            $card->enteredTurn = $s->turn;
            $s->player($item->controller)->battlefield[] = $card;
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
        $s->emit('ManaAdded', ['player' => $player, 'mana' => $cost->colored, 'pool' => $p->pool->toArray(), 'source' => $stackId]);
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
