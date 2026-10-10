<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Active une capacité d'un permanent. Capacité de mana : résolue sur-le-champ (ni pile ni nouvelle ronde, le joueur
 * garde la priorité, comme PlayLand). Autre capacité : mise sur la pile, remet les passes à zéro.
 */
final class ActivateAbility implements Action
{
    /**
     * @param list<string> $targets
     * @param list<string> $costChoices ids choisis pour le coût : créature sacrifiée (`sacrificeCreature`) puis cartes
     *                                  défaussées (`discard`), dans cet ordre
     */
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly int $abilityIndex,
        public readonly int $option = 0, // alternative de mana choisie (capacité de mana)
        public readonly array $targets = [],
        public readonly bool $mana = false, // capacité de mana (information d'affichage, hors id)
        public readonly array $costChoices = [],
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('ability:%d:%s:%d:%d:%s', $this->player, $this->cardId, $this->abilityIndex, $this->option, implode(',', $this->targets))
            . ($this->costChoices === [] ? '' : ':' . implode(',', $this->costChoices));
    }

    public function describe(GameState $state): string
    {
        $card = null;
        foreach ($state->player($this->player)->battlefield as $c) {
            if ($c->id === $this->cardId) {
                $card = $c;
            }
        }
        $ability = $card?->abilities()[$this->abilityIndex] ?? null;
        $label = ($card?->name ?? $this->cardId) . ($ability === null ? '' : ' (' . $ability->label($this->option) . ')');
        if ($this->costChoices !== []) {
            $names = [];
            $p = $state->player($this->player);
            foreach ($this->costChoices as $id) {
                $names[] = ($p->findOnBattlefield($id) ?? $p->findInHand($id))?->name ?? $id;
            }
            $label .= ' en ' . ($ability?->sacrificeCreature ? 'sacrifiant ' : 'défaussant ') . implode(', ', $names);
        }
        if ($this->targets !== []) {
            $label .= ' → joueur ' . substr($this->targets[0], 7);
        }

        return 'Activer ' . $label;
    }

    /** Vrai si la capacité visée est une capacité de mana (utilisé par l'auto-pass humain). */
    public function isManaAbility(GameState $state): bool
    {
        foreach ($state->player($this->player)->battlefield as $c) {
            if ($c->id === $this->cardId) {
                return ($c->abilities()[$this->abilityIndex] ?? null)?->isManaAbility ?? false;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return ['type' => 'ability', 'player' => $this->player, 'cardId' => $this->cardId, 'ability' => $this->abilityIndex, 'option' => $this->option, 'targets' => $this->targets, 'mana' => $this->mana, 'costChoices' => $this->costChoices];
    }
}
