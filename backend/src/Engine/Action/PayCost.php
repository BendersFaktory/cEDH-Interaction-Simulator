<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;
use App\Engine\Rules\StackResolver;

/**
 * Paiement demandé à la résolution du sommet de pile (`GameState::$paymentPending`, voir `StackResolver::paymentDue()`) :
 * payer `cost` (`pay`, auto-paiement par les sources du joueur) ou refuser. Daze / Mana Leak : refuser = le sort visé est
 * contré ; Pact of Negation : refuser = perdre la partie ; Rhystic Study : refuser = son contrôleur pioche. Décision hors
 * priorité, comme le Scry.
 */
final class PayCost implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly bool $pay,
        public readonly string $cost,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('pay:%d:%s', $this->player, $this->pay ? 'yes' : 'no');
    }

    public function describe(GameState $state): string
    {
        $due = StackResolver::paymentDue($state);
        $source = $due['source'] ?? '?';
        $top = $state->topOfStack();
        if ($this->pay) {
            return sprintf('Payer %s (%s)', $this->cost, $source);
        }
        $outcome = match ($top?->trigger?->effect) {
            'pact' => 'perdre la partie',
            'draw_unless' => sprintf('J%d pioche', $top->controller), // Rhystic Study
            'pay_untap' => 'elle reste engagée', // Mana Vault
            'cumulative_upkeep' => 'la sacrifier', // Mystic Remora
            default => 'le sort est contré',
        };

        return sprintf('Ne pas payer %s (%s) : %s', $this->cost, $source, $outcome);
    }

    public function toArray(): array
    {
        return ['type' => 'pay', 'player' => $this->player, 'pay' => $this->pay, 'cost' => $this->cost];
    }
}
