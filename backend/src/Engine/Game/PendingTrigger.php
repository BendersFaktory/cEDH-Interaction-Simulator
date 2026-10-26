<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\TriggeredAbility;

/**
 * Trigger en attente d'être mis sur la pile (`GameState::$pendingTriggers`). `source` est la carte telle
 * qu'elle était au moment du trigger (information de dernière connaissance : une créature morte n'est plus sur le champ de
 * bataille, un jeton a cessé d'exister). Trigger retardé (`delayed`, CR 603.7) : la capacité n'est pas portée par la carte
 * source (le sort qui l'a créé, Pact of Negation, Mana Drain) ; `index` vaut 0. Sert aussi aux triggers retardés qui attendent
 * leur événement (`GameState::$delayedTriggers`). `opponent` : l'adversaire dont le sort a déclenché un trigger `opponent_cast`
 * (Rhystic Study ; sérialisé sous `opponent`, omis quand null).
 */
final class PendingTrigger
{
    public function __construct(
        public readonly string $id,
        public readonly int $controller,
        public readonly CardInstance $source,
        public readonly int $index, // rang dans `$source->triggers`
        public readonly ?TriggeredAbility $delayed = null, // trigger retardé : la capacité elle-même (sérialisée sous `delayed`)
        public readonly ?int $opponent = null, // `opponent_cast` : le joueur qui a lancé le sort
    ) {
    }

    public function ability(): TriggeredAbility
    {
        return $this->delayed ?? $this->source->triggers[$this->index];
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'controller' => $this->controller, 'source' => $this->source->toArray(), 'index' => $this->index]
            + ($this->delayed === null ? [] : ['delayed' => $this->delayed->toArray()])
            + ($this->opponent === null ? [] : ['opponent' => $this->opponent]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d['id'], (int) $d['controller'], CardInstance::fromArray($d['source']), (int) $d['index'],
            isset($d['delayed']) ? TriggeredAbility::fromArray($d['delayed']) : null, isset($d['opponent']) ? (int) $d['opponent'] : null);
    }
}
