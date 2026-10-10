<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
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
    public const ARTIFACT = 'artifact';

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
        /** @var list<ActivatedAbility> capacités activées déclarées (les terrains reçoivent les leurs de `produces`, voir abilities()) */
        public readonly array $abilities = [],
        public readonly ?string $adds = null, // mana ajouté à la résolution d'un sort `effect 'mana'` (rituel), ex. `{R}{R}`
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

    /**
     * Capacités activées, dans un ordre stable (l'index fait partie de l'id d'action). Un terrain a une capacité de mana
     * `{T} : {couleur}` par lettre de `produces` (`{C}` s'il est vide).
     *
     * @return list<ActivatedAbility>
     */
    public function abilities(): array
    {
        if (!$this->isLand()) {
            return $this->abilities;
        }

        return array_map(
            static fn (string $symbol) => ActivatedAbility::mana('{' . $symbol . '}'),
            $this->produces === [] ? ['C'] : $this->produces,
        );
    }

    /** Permanent : va sur le champ de bataille en se résolvant (créature, artefact) ou en étant joué (terrain). */
    public function isPermanent(): bool
    {
        return $this->kind !== self::INSTANT && $this->kind !== self::SORCERY;
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
            'abilities' => array_map(static fn (ActivatedAbility $a) => $a->toArray(), $this->abilities),
            'adds' => $this->adds,
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
            array_map([ActivatedAbility::class, 'fromArray'], $d['abilities'] ?? []),
            $d['adds'] ?? null,
        );
    }
}
