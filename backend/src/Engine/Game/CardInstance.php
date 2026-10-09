<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * Une carte physique dans une partie (pas la définition Scryfall).
 * Squelette : les effets sont de simples drapeaux ('damage', 'counter').
 * TODO: remplacer par CardDefinition + Ability (handlers par carte ou DSL).
 */
final class CardInstance
{
    public const LAND = 'land';
    public const INSTANT = 'instant';
    public const SORCERY = 'sorcery';
    public const CREATURE = 'creature';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $cost = 0,
        public readonly ?string $effect = null, // 'damage' | 'counter' | null
        public readonly int $amount = 0,
        public bool $tapped = false,
        public readonly bool $commander = false,
    ) {
    }

    public function isLand(): bool
    {
        return $this->kind === self::LAND;
    }

    public function isInstantSpeed(): bool
    {
        return $this->kind === self::INSTANT;
    }

    /** Représentation exposée dans les événements (tout est public dans ce simulateur). */
    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'cost' => $this->cost,
            'effect' => $this->effect,
            'amount' => $this->amount,
            'commander' => $this->commander,
        ];
    }

    public function toArray(): array
    {
        return $this->toPublic() + ['tapped' => $this->tapped];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            $d['id'],
            $d['name'],
            $d['kind'],
            (int) $d['cost'],
            $d['effect'] ?? null,
            (int) ($d['amount'] ?? 0),
            (bool) ($d['tapped'] ?? false),
            (bool) ($d['commander'] ?? false),
        );
    }
}
