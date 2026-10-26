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
 * Effets d'un sort : liste de `SpellEffect` (résolus dans l'ordre). Forme JSON : `spell` = `['effect' => …, 'amount' => int,
 * 'adds' => '{R}{R}']` pour un seul effet (clés omises à leur défaut, format historique), `effects` = liste de ces objets à
 * partir de deux. Les capacités activées et les triggers gardent leur propre format (`toArray()`).
 * Pas d'interprétation du texte Oracle : une carte non modélisée est simplement refusée par le chargeur.
 */
final class CardDefinition
{
    /** @var list<SpellEffect> */
    public readonly array $effects;

    /**
     * @param string|list<string> $type
     * @param list<string> $produces
     * @param list<ActivatedAbility> $abilities
     * @param list<TriggeredAbility> $triggers
     * @param array<string, list<string>> $subtypes
     * @param list<string> $keywords
     * @param list<string> $supertypes
     * @param list<SpellEffect> $effects effets du sort ; vide : `effect` / `amount` / `adds` (raccourci pour un seul effet)
     */
    public function __construct(
        public readonly string $name,
        public readonly string|array $type,
        public readonly int $cost = 0,
        public readonly ?string $manaCost = null,
        public readonly int $power = 0,
        public readonly int $toughness = 0,
        ?string $effect = null,
        int $amount = 0,
        ?string $adds = null,
        public readonly array $produces = [],
        public readonly array $abilities = [],
        public readonly array $triggers = [],
        public readonly array $subtypes = [],
        public readonly array $keywords = [],
        public readonly array $supertypes = [],
        array $effects = [],
        public readonly int $untappedLifeCost = 0,
        public readonly ?string $altCost = null,
        public readonly bool $noUntap = false,
        public readonly ?string $extraCost = null,
        public readonly int $tappedAfterOwnTurn = 0,
        public readonly array $statics = [],
        public readonly ?string $kicker = null,
    ) {
        $this->effects = $effects !== [] ? array_values($effects) : ($effect === null ? [] : [new SpellEffect($effect, $amount, $adds)]);
    }

    public function instantiate(string $id, bool $commander = false): CardInstance
    {
        return new CardInstance(
            $id, $this->name, $this->type, $this->cost, null, 0, false, $commander,
            $this->power, $this->toughness, 0, 0, $this->manaCost, $this->produces, $this->abilities, null, false,
            $this->triggers, $this->subtypes, $this->keywords, $this->supertypes, $this->effects, $this->untappedLifeCost, $this->altCost, $this->noUntap, $this->extraCost, $this->tappedAfterOwnTurn,
            $this->statics, $this->kicker,
        );
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'cost' => $this->cost, 'manaCost' => $this->manaCost,
            'power' => $this->power, 'toughness' => $this->toughness, 'produces' => $this->produces,
            'abilities' => array_map(static fn (ActivatedAbility $a) => $a->toArray(), $this->abilities),
            'triggers' => array_map(static fn (TriggeredAbility $t) => $t->toArray(), $this->triggers),
            'subtypes' => $this->subtypes, 'keywords' => $this->keywords, 'supertypes' => $this->supertypes,
        ] + ($this->untappedLifeCost > 0 ? ['untappedLifeCost' => $this->untappedLifeCost] : []) + ($this->altCost === null ? [] : ['altCost' => $this->altCost]) + ($this->noUntap ? ['noUntap' => true] : []) + ($this->extraCost === null ? [] : ['extraCost' => $this->extraCost]) + ($this->tappedAfterOwnTurn > 0 ? ['tappedAfterOwnTurn' => $this->tappedAfterOwnTurn] : [])
            + ($this->statics === [] ? [] : ['statics' => $this->statics]) + ($this->kicker === null ? [] : ['kicker' => $this->kicker]) + (count($this->effects) > 1
            ? ['effects' => array_map(static fn (SpellEffect $e) => $e->toArray(), $this->effects)]
            : ['spell' => isset($this->effects[0]) ? $this->effects[0]->toArray() : []]);
    }

    public static function fromArray(array $d): self
    {
        $effects = array_map([SpellEffect::class, 'fromArray'], $d['effects'] ?? (isset($d['spell']['effect']) ? [$d['spell']] : []));

        return new self(
            $d['name'], $d['type'], (int) ($d['cost'] ?? 0), $d['manaCost'] ?? null, (int) ($d['power'] ?? 0), (int) ($d['toughness'] ?? 0),
            null, 0, null, $d['produces'] ?? [],
            array_map([ActivatedAbility::class, 'fromArray'], $d['abilities'] ?? []),
            array_map([TriggeredAbility::class, 'fromArray'], $d['triggers'] ?? []),
            $d['subtypes'] ?? [], $d['keywords'] ?? [], $d['supertypes'] ?? [], $effects, (int) ($d['untappedLifeCost'] ?? 0), $d['altCost'] ?? null, (bool) ($d['noUntap'] ?? false), $d['extraCost'] ?? null, (int) ($d['tappedAfterOwnTurn'] ?? 0),
            $d['statics'] ?? [], $d['kicker'] ?? null,
        );
    }
}
