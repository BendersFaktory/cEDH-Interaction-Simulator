<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\Subtypes;
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
    public const ENCHANTMENT = 'enchantment';

    /** @var list<string> types (artefact-créature : deux) ; sérialisé sous `type`, en chaîne s'il n'y en a qu'un */
    public readonly array $types;

    /** Bonus de force/endurance jusqu'à la fin du tour (`pump`), remis à zéro au cleanup. Voir power()/toughness(). */
    public int $powerBonus = 0;
    public int $toughnessBonus = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        string|array $type,
        public readonly int $cost = 0,
        public readonly ?string $effect = null, // 'damage' | 'counter' | 'mana' | 'treasure' | null
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
        /** @var list<ActivatedAbility> capacités activées déclarées (un terrain a d'abord celles de `produces`, voir abilities()) */
        public readonly array $abilities = [],
        public readonly ?string $adds = null, // mana ajouté à la résolution d'un sort `effect 'mana'` (rituel), ex. `{R}{R}`
        public readonly bool $token = false, // jeton : cesse d'exister en quittant le champ de bataille
        /** @var list<TriggeredAbility> triggers (l'index fait partie de l'id d'action PutTrigger) */
        public readonly array $triggers = [],
        /** @var array<string, list<string>> sous-types rattachés à leur type (règle 205.3), ex. `['creature' => ['Eldrazi']]` */
        public readonly array $subtypes = [],
    ) {
        $this->types = is_array($type) ? array_values($type) : [$type];
        if ($this->types === []) {
            throw new \InvalidArgumentException("$name : une carte a au moins un type");
        }
        if ($subtypes !== []) {
            Subtypes::assertValid($this->types, $subtypes, $name);
        }
    }

    public function hasType(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /** Sous-type porté par la carte, sous le type `$type` s'il est donné (sinon sous n'importe lequel de ses types). */
    public function hasSubtype(string $subtype, ?string $type = null): bool
    {
        foreach ($this->subtypes as $t => $list) {
            if (($type === null || $t === $type) && in_array($subtype, $list, true)) {
                return true;
            }
        }

        return false;
    }

    /** Incolore : aucun symbole coloré ni hybride dans le coût de mana (`{C}` est incolore). */
    public function isColorless(): bool
    {
        $cost = $this->manaCost();

        return $cost->hybrid === [] && array_diff(array_keys($cost->colored), ['C']) === [];
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
     * Capacités activées, dans un ordre stable (l'index fait partie de l'id d'action). Un terrain a d'abord une capacité de
     * mana `{T} : {couleur}` par lettre de `produces` (`{C}` s'il est vide), puis ses capacités déclarées (Kher Keep).
     *
     * @return list<ActivatedAbility>
     */
    public function abilities(): array
    {
        if (!$this->isLand()) {
            return $this->abilities;
        }

        return [...array_map(
            static fn (string $symbol) => ActivatedAbility::mana('{' . $symbol . '}'),
            $this->produces === [] ? ['C'] : $this->produces,
        ), ...$this->abilities];
    }

    /** Force actuelle (bonus du tour compris). */
    public function currentPower(): int
    {
        return $this->power + $this->powerBonus;
    }

    /** Endurance actuelle (bonus du tour compris). */
    public function currentToughness(): int
    {
        return $this->toughness + $this->toughnessBonus;
    }

    /** Permanent : va sur le champ de bataille en se résolvant (créature, artefact) ou en étant joué (terrain). */
    public function isPermanent(): bool
    {
        return !$this->hasType(self::INSTANT) && !$this->hasType(self::SORCERY);
    }

    public function isLand(): bool
    {
        return $this->hasType(self::LAND);
    }

    public function isCreature(): bool
    {
        return $this->hasType(self::CREATURE);
    }

    public function isInstantSpeed(): bool
    {
        return $this->hasType(self::INSTANT);
    }

    /** Représentation exposée dans les événements (tout est public dans ce simulateur). */
    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => count($this->types) === 1 ? $this->types[0] : $this->types,
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
        ] + ($this->token ? ['token' => true] : []) // omis si faux : au-delà de 16 clés, PHP double la table (+75 % de mémoire par état)
            + ($this->triggers === [] ? [] : ['triggers' => array_map(static fn (TriggeredAbility $t) => $t->toArray(), $this->triggers)]) // idem
            + ($this->subtypes === [] ? [] : ['subtypes' => $this->subtypes]); // idem
    }

    public function toArray(): array
    {
        // `boost` omis sans bonus : au-delà de 16 clés, PHP double la table (voir toPublic).
        return $this->toPublic() + ['tapped' => $this->tapped, 'damage' => $this->damage, 'enteredTurn' => $this->enteredTurn]
            + ($this->powerBonus !== 0 || $this->toughnessBonus !== 0 ? ['boost' => [$this->powerBonus, $this->toughnessBonus]] : []);
    }

    public static function fromArray(array $d): self
    {
        $card = new self(
            $d['id'],
            $d['name'],
            $d['type'],
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
            (bool) ($d['token'] ?? false),
            array_map([TriggeredAbility::class, 'fromArray'], $d['triggers'] ?? []),
            $d['subtypes'] ?? [],
        );
        [$card->powerBonus, $card->toughnessBonus] = $d['boost'] ?? [0, 0];

        return $card;
    }
}
