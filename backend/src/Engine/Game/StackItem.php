<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;

final class StackItem
{
    /**
     * Sort (`$ability` et `$trigger` null), capacité activée (la carte reste sur le champ de bataille, ne change pas de
     * zone) ou capacité déclenchée (`$trigger` ; la source peut avoir quitté le champ de bataille). `$abilityIndex` est le
     * rang de la capacité dans `abilities()` ou dans `triggers`.
     *
     * @param list<string> $targets ex: ['player:1'] ou ['stack:s3']
     */
    public function __construct(
        public readonly string $id,
        public readonly CardInstance $card,
        public readonly int $controller,
        public readonly array $targets = [],
        public readonly int $x = 0, // valeur choisie pour {X}
        public readonly ?ActivatedAbility $ability = null,
        public readonly int $abilityIndex = 0,
        public readonly ?TriggeredAbility $trigger = null,
    ) {
    }

    /** Capacité (activée ou déclenchée) : un contresort ne la cible pas. */
    public function isAbility(): bool
    {
        return $this->ability !== null || $this->trigger !== null;
    }

    public function isTrigger(): bool
    {
        return $this->trigger !== null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'card' => $this->card->toArray(),
            'controller' => $this->controller,
            'targets' => $this->targets,
            'x' => $this->x,
            'ability' => $this->ability?->toArray(),
            'abilityIndex' => $this->abilityIndex,
        ] + ($this->trigger === null ? [] : ['trigger' => $this->trigger->toArray()]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d['id'], CardInstance::fromArray($d['card']), (int) $d['controller'], $d['targets'], (int) ($d['x'] ?? 0), isset($d['ability']) ? ActivatedAbility::fromArray($d['ability']) : null, (int) ($d['abilityIndex'] ?? 0), isset($d['trigger']) ? TriggeredAbility::fromArray($d['trigger']) : null);
    }
}
