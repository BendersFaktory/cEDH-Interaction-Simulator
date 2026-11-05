<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;
use App\Engine\Rules\DamageAssignment;

/**
 * Répartition des dégâts de combat d'un attaquant bloqué par plusieurs créatures (ou avec piétinement), choisie par
 * son contrôleur humain (CR 510.1c, 702.19b, 702.2c : voir DamageAssignment). Décision hors priorité, sans pile ni
 * ronde, comme les déclarations. Les répartitions légales sont énumérées par DamageAssignment::options ; l'id porte
 * le vecteur complet, le serveur recalcule la liste et retrouve l'action (jamais de confiance au client).
 */
final class AssignCombatDamage implements Action
{
    /** @param array<string, int> $assignment id de bloqueur ou `player:N` => dégâts (toute la force de l'attaquant) */
    public function __construct(
        private readonly int $player,
        public readonly string $attackerId,
        public readonly array $assignment,
        public readonly bool $isDefault = false,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('assign:%d:%s:%s', $this->player, $this->attackerId, DamageAssignment::key($this->assignment));
    }

    public function describe(GameState $state): string
    {
        $parts = [];
        foreach ($this->assignment as $target => $amount) {
            $target = (string) $target;
            $parts[] = sprintf('%d → %s', $amount, str_starts_with($target, 'player:') ? 'joueur ' . substr($target, 7) : $this->nameOf($state, $target));
        }

        return sprintf('Dégâts de %s : %s%s', $this->nameOf($state, $this->attackerId), implode(', ', $parts), $this->isDefault ? ' (par défaut)' : '');
    }

    private function nameOf(GameState $state, string $cardId): string
    {
        foreach ($state->players as $p) {
            foreach ($p->battlefield as $card) {
                if ($card->id === $cardId) {
                    return sprintf('%s (%s)', $card->name, $card->id);
                }
            }
        }

        return $cardId;
    }

    public function toArray(): array
    {
        $assignment = [];
        foreach ($this->assignment as $target => $amount) {
            $assignment[] = ['target' => (string) $target, 'amount' => $amount];
        }

        return ['type' => 'assign', 'player' => $this->player, 'attackerId' => $this->attackerId, 'assignment' => $assignment, 'default' => $this->isDefault];
    }
}
