<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Action spéciale : n'utilise pas la pile et n'ouvre PAS de ronde de priorité. */
final class PlayLand implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly ?bool $payLife = null, // shockland : payer les PV (true) ou arriver engagé (false) ; null sinon
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('land:%d:%s', $this->player, $this->cardId) . match ($this->payLife) {
            null => '',
            true => ':life',
            false => ':tapped',
        };
    }

    public function describe(GameState $state): string
    {
        $card = $state->player($this->player)->findInHand($this->cardId);

        return 'Jouer ' . ($card?->name ?? $this->cardId) . match ($this->payLife) {
            null => '',
            true => " (payer {$card?->untappedLifeCost} PV)",
            false => ' (engagé)',
        };
    }

    public function toArray(): array
    {
        return ['type' => 'land', 'player' => $this->player, 'cardId' => $this->cardId] + ($this->payLife === null ? [] : ['payLife' => $this->payLife]);
    }
}
