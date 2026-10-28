<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;

final class StackItem
{
    /**
     * Sort (`$ability` et `$trigger` null), capacité activée (la carte reste sur le champ de bataille, ne change pas de
     * zone) ou trigger (`$trigger` ; la source peut avoir quitté le champ de bataille). `$abilityIndex` est le
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
        public readonly int $mode = 0, // mode choisi d'un sort modal (Pyroblast) ; 0 = sort non modal
        public readonly bool $copy = false, // copie d'un sort (Storm, Chain of Vapor) : jamais lancée, cesse d'exister en quittant la pile (CR 707.10a)
        public readonly bool $kicked = false, // kicker payé (Orim's Chant)
        public readonly ?int $owner = null, // propriétaire, s'il n'est pas le contrôleur (sort dont Commandeer a pris le contrôle)
        public readonly ?string $altCast = null, // lancé pour son coût de Flashback ou de Dash (CardInstance::ALT_CASTS)
    ) {
    }

    /** Le même objet de pile avec d'autres cibles (Deflecting Swat, Misdirection) ou un autre contrôleur (Commandeer). */
    public function with(?array $targets = null, ?int $controller = null): self
    {
        $controller ??= $this->controller;

        return new self($this->id, $this->card, $controller, $targets ?? $this->targets, $this->x, $this->ability, $this->abilityIndex, $this->trigger,
            $this->mode, $this->copy, $this->kicked, $controller === $this->ownerId() ? null : $this->ownerId(), $this->altCast);
    }

    /** Lancé pour son coût de Flashback : exilé au lieu d'aller où que ce soit en quittant la pile (CR 702.34a). */
    public function flashback(): bool
    {
        return $this->altCast === CardInstance::CAST_FLASHBACK;
    }

    /** Propriétaire de la carte : le joueur dans le cimetière duquel elle ira. */
    public function ownerId(): int
    {
        return $this->owner ?? $this->controller;
    }

    /**
     * Effets du sort joués (ceux du mode choisi pour un sort modal).
     *
     * @return list<\App\Engine\Card\SpellEffect>
     */
    public function effects(): array
    {
        return $this->card->effectsForMode($this->mode);
    }

    /** Capacité activée ou trigger : un contresort ne la cible pas. */
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
        ] + ($this->trigger === null ? [] : ['trigger' => $this->trigger->toArray()]) + ($this->mode === 0 ? [] : ['mode' => $this->mode])
            + ($this->copy ? ['copy' => true] : []) + ($this->kicked ? ['kicked' => true] : []) + ($this->owner === null ? [] : ['owner' => $this->owner])
            + ($this->altCast === null ? [] : ['altCast' => $this->altCast]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d['id'], CardInstance::fromArray($d['card']), (int) $d['controller'], $d['targets'], (int) ($d['x'] ?? 0), isset($d['ability']) ? ActivatedAbility::fromArray($d['ability']) : null, (int) ($d['abilityIndex'] ?? 0), isset($d['trigger']) ? TriggeredAbility::fromArray($d['trigger']) : null, (int) ($d['mode'] ?? 0),
            (bool) ($d['copy'] ?? false), (bool) ($d['kicked'] ?? false), isset($d['owner']) ? (int) $d['owner'] : null, $d['altCast'] ?? null);
    }
}
