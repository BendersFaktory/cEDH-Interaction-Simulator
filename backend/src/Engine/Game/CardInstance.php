<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;

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
        public readonly int $power = 0,
        public readonly int $toughness = 0,
        public int $damage = 0,        // dégâts marqués ce tour (effacés au cleanup)
        public int $enteredTurn = 0,   // tour d'arrivée sur le champ de bataille (mal d'invocation)
        public readonly ?string $manaCost = null, // forme canonique `{X}{2}{R}` ; null = `{cost}` générique
        /** @var list<string> lettres des couleurs produites par un terrain ; vide = incolore (`C`) */
        public readonly array $produces = [],
    ) {
    }

    /** Coût de mana structuré (les terrains n'ont pas de coût). */
    public function manaCost(): ManaCost
    {
        return ManaCost::parse($this->manaCost ?? '{' . $this->cost . '}');
    }

    /** @return list<Color> couleurs qu'un terrain peut produire (incolore par défaut) ; vide pour un non-terrain */
    public function producedColors(): array
    {
        if (!$this->isLand()) {
            return [];
        }

        return $this->produces === [] ? [Color::Colorless] : array_map(Color::fromSymbol(...), $this->produces);
    }

    public function isLand(): bool
    {
        return $this->kind === self::LAND;
    }

    public function isCreature(): bool
    {
        return $this->kind === self::CREATURE;
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
            'manaCost' => $this->isLand() ? null : (string) $this->manaCost(),
            'produces' => $this->produces,
            'effect' => $this->effect,
            'amount' => $this->amount,
            'commander' => $this->commander,
            'power' => $this->power,
            'toughness' => $this->toughness,
        ];
    }

    public function toArray(): array
    {
        return $this->toPublic() + ['tapped' => $this->tapped, 'damage' => $this->damage, 'enteredTurn' => $this->enteredTurn];
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
            (int) ($d['power'] ?? 0),
            (int) ($d['toughness'] ?? 0),
            (int) ($d['damage'] ?? 0),
            (int) ($d['enteredTurn'] ?? 0),
            $d['manaCost'] ?? null,
            $d['produces'] ?? [],
        );
    }
}
