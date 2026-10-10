<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Déclaration d'un attaquant vers un joueur précis (étape « déclaration des attaquants »). Pas de pile. */
final class DeclareAttacker implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly int $defender,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('attack:%d:%s:%d', $this->player, $this->cardId, $this->defender);
    }

    public function describe(GameState $state): string
    {
        $name = $this->findName($state);

        return sprintf('Attaquer J%d avec %s (%s)', $this->defender, $name, $this->cardId);
    }

    private function findName(GameState $state): string
    {
        foreach ($state->player($this->player)->battlefield as $card) {
            if ($card->id === $this->cardId) {
                return $card->name;
            }
        }

        return $this->cardId;
    }

    public function toArray(): array
    {
        return ['type' => 'attack', 'player' => $this->player, 'cardId' => $this->cardId, 'defender' => $this->defender];
    }
}
