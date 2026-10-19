<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Game\CardInstance;

/**
 * Définition immuable d'une carte (données, sans état de partie) : ce que fournit un deck de démo ou un `DeckLoader`.
 * `instantiate()` en fait une `CardInstance` ; le format sérialisé d'une partie ne change pas.
 *
 * Effet d'un sort : `spell` = `['effect' => 'damage|counter|mana|treasure', 'amount' => int, 'adds' => '{R}{R}']`
 * (clés omises à leur défaut). Les capacités activées et les triggers gardent leur propre format (`toArray()`).
 * Pas d'interprétation du texte Oracle : une carte non modélisée est simplement refusée par le chargeur.
 */
final class CardDefinition
{
    private const SPELL_EFFECTS = ['damage', 'counter', 'mana', 'treasure'];

    /**
     * @param string|list<string> $type
     * @param list<string> $produces
     * @param list<ActivatedAbility> $abilities
     * @param list<TriggeredAbility> $triggers
     * @param array<string, list<string>> $subtypes
     * @param list<string> $keywords
     * @param list<string> $supertypes
     */
    public function __construct(
        public readonly string $name,
        public readonly string|array $type,
        public readonly int $cost = 0,
        public readonly ?string $manaCost = null,
        public readonly int $power = 0,
        public readonly int $toughness = 0,
        public readonly ?string $effect = null,
        public readonly int $amount = 0,
        public readonly ?string $adds = null,
        public readonly array $produces = [],
        public readonly array $abilities = [],
        public readonly array $triggers = [],
        public readonly array $subtypes = [],
        public readonly array $keywords = [],
        public readonly array $supertypes = [],
    ) {
        if ($effect !== null && !in_array($effect, self::SPELL_EFFECTS, true)) {
            throw new \InvalidArgumentException("$name : effet de sort inconnu « $effect »");
        }
    }

    public function instantiate(string $id, bool $commander = false): CardInstance
    {
        return new CardInstance(
            $id, $this->name, $this->type, $this->cost, $this->effect, $this->amount, false, $commander,
            $this->power, $this->toughness, 0, 0, $this->manaCost, $this->produces, $this->abilities, $this->adds, false,
            $this->triggers, $this->subtypes, $this->keywords, $this->supertypes,
        );
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'cost' => $this->cost, 'manaCost' => $this->manaCost,
            'power' => $this->power, 'toughness' => $this->toughness, 'produces' => $this->produces,
            'abilities' => array_map(static fn (ActivatedAbility $a) => $a->toArray(), $this->abilities),
            'triggers' => array_map(static fn (TriggeredAbility $t) => $t->toArray(), $this->triggers),
            'subtypes' => $this->subtypes, 'keywords' => $this->keywords, 'supertypes' => $this->supertypes,
            'spell' => array_filter(['effect' => $this->effect, 'amount' => $this->amount ?: null, 'adds' => $this->adds], static fn ($v) => $v !== null),
        ];
    }

    public static function fromArray(array $d): self
    {
        $spell = $d['spell'] ?? [];

        return new self(
            $d['name'], $d['type'], (int) ($d['cost'] ?? 0), $d['manaCost'] ?? null, (int) ($d['power'] ?? 0), (int) ($d['toughness'] ?? 0),
            $spell['effect'] ?? null, (int) ($spell['amount'] ?? 0), $spell['adds'] ?? null, $d['produces'] ?? [],
            array_map([ActivatedAbility::class, 'fromArray'], $d['abilities'] ?? []),
            array_map([TriggeredAbility::class, 'fromArray'], $d['triggers'] ?? []),
            $d['subtypes'] ?? [], $d['keywords'] ?? [], $d['supertypes'] ?? [],
        );
    }
}
