<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Game\GameState;

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

        // Permanent -> champ de bataille ; sinon cimetière (ou zone de commandement pour un commandant).
        if ($card->kind === 'creature') {
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
            'damage' => $this->damage($s, $item->targets[0] ?? '', $card->amount, $card->name),
            'counter' => $this->counter($s, $item->targets[0] ?? '', $item->id),
            default => null,
        };
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
        if ($victim === null) {
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
