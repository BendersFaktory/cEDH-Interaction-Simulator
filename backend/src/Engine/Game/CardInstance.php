<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\SpellEffect;
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

    // Coûts alternatifs (`altCost`) : Force of Will (1 PV + exiler un bleu de la main), Fierce Guardianship (gratuit si commandant).
    public const ALT_PITCH_BLUE = 'pitch_blue';
    public const ALT_COMMANDER = 'commander';

    // Mots-clés de combat (CR 702).
    public const FLYING = 'flying';
    public const REACH = 'reach';
    public const HASTE = 'haste';
    public const VIGILANCE = 'vigilance';
    public const DEFENDER = 'defender';
    public const MENACE = 'menace';
    public const FIRST_STRIKE = 'first_strike';
    public const DOUBLE_STRIKE = 'double_strike';
    public const TRAMPLE = 'trample';
    public const DEATHTOUCH = 'deathtouch';
    public const LIFELINK = 'lifelink';
    public const FLASH = 'flash';

    // Supertypes (CR 205.4).
    public const LEGENDARY = 'legendary';

    /** @var list<string> types (artefact-créature : deux) ; sérialisé sous `type`, en chaîne s'il n'y en a qu'un */
    public readonly array $types;

    /** Bonus de force/endurance jusqu'à la fin du tour (`pump`), remis à zéro au cleanup. Voir power()/toughness(). */
    public int $powerBonus = 0;
    public int $toughnessBonus = 0;

    /** Blessée par une source à contact mortel ce tour : détruite par les actions basées sur l'état (CR 704.5h), remis à faux au cleanup. */
    public bool $deathtouchDamage = false;

    /**
     * Mémo de `toPublic()` / `toArray()` : un même tableau rendu tant que la carte ne change pas est partagé (copie sur
     * écriture) entre tous les états sérialisés ; une bibliothèque de 99 cartes ne coûte alors presque rien par checkpoint.
     * Pur cache d'une fonction de l'objet, jamais sérialisé.
     */
    /** @var list<SpellEffect> effets d'un sort à sa résolution, dans l'ordre (vide : aucun effet de sort) */
    public readonly array $effects;

    private ?array $publicMemo = null;
    private ?array $arrayMemo = null;
    private string $arrayMemoKey = '';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        string|array $type,
        public readonly int $cost = 0,
        ?string $effect = null, // raccourci pour un sort à un seul effet ('damage' | 'counter' | 'mana' | 'treasure' | 'draw')
        int $amount = 0,
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
        ?string $adds = null, // raccourci : mana ajouté par l'effet `mana` (rituel), ex. `{R}{R}`
        public readonly bool $token = false, // jeton : cesse d'exister en quittant le champ de bataille
        /** @var list<TriggeredAbility> triggers (l'index fait partie de l'id d'action PutTrigger) */
        public readonly array $triggers = [],
        /** @var array<string, list<string>> sous-types rattachés à leur type (règle 205.3), ex. `['creature' => ['Eldrazi']]` */
        public readonly array $subtypes = [],
        /** @var list<string> mots-clés (constantes FLYING...) */
        public readonly array $keywords = [],
        /** @var list<string> supertypes (constante LEGENDARY) */
        public readonly array $supertypes = [],
        /** @var list<SpellEffect> effets du sort dans l'ordre (remplace `effect` / `amount` / `adds` s'il est non vide) */
        array $effects = [],
        /** PV à payer en arrivant pour ne pas arriver engagé (shockland : 2) ; 0 = terrain ordinaire */
        public readonly int $untappedLifeCost = 0,
        public readonly ?string $altCost = null, // coût alternatif (ALT_PITCH_BLUE | ALT_COMMANDER), null = coût de mana seul
    ) {
        $this->effects = $effects !== [] ? array_values($effects) : ($effect === null ? [] : [new SpellEffect($effect, $amount, $adds)]);
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

    /** Premier effet de sort du genre `$effect` (`damage`, `counter`, `mana`, `treasure`, `draw`), ou null. */
    public function effect(string $effect): ?SpellEffect
    {
        foreach ($this->effects as $e) {
            if ($e->effect === $effect) {
                return $e;
            }
        }

        return null;
    }

    public function hasEffect(string $effect): bool
    {
        return $this->effect($effect) !== null;
    }

    /**
     * Les effets ciblés du sort, dans l'ordre du texte : une cible par effet (CR 115.1, 608.2b). Vide si aucun.
     *
     * @return list<SpellEffect>
     */
    public function targetedEffects(): array
    {
        return array_values(array_filter($this->effects, static fn (SpellEffect $e) => $e->isTargeted()));
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

    /** Légendaire : soumis à la règle des légendes (CR 704.5j). */
    /** Bleue : une de ses couleurs d'identité est le bleu (Force of Will exile une carte bleue de la main). */
    public function isBlue(): bool
    {
        foreach ($this->colorIdentity() as $color) {
            if ($color->symbol() === 'U') {
                return true;
            }
        }

        return false;
    }

    public function isLegendary(): bool
    {
        return in_array(self::LEGENDARY, $this->supertypes, true);
    }

    public function hasKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->keywords, true);
    }

    /** Incolore : aucun symbole coloré ni hybride dans le coût de mana (`{C}` est incolore). */
    public function isColorless(): bool
    {
        $cost = $this->manaCost();

        return $cost->hybrid === [] && $cost->phyrexian === [] && array_diff(array_keys($cost->colored), ['C']) === [];
    }


    /** @param list<list<Color>> $options vrai si ce sont exactement les cinq couleurs, une par alternative */
    private static function isAnyColor(array $options): bool
    {
        $symbols = array_map(static fn (array $o): string => count($o) === 1 ? $o[0]->symbol() : '', $options);
        sort($symbols);

        return $symbols === ['B', 'G', 'R', 'U', 'W'];
    }

    /**
     * Identité de couleurs (CR 903.4) : couleurs des symboles du coût de mana (hybride `{U/R}` = U et R ; `{C}` et générique
     * n'ajoutent rien) et de ceux des capacités activées (coût et mana produit, dont `produces` d'un terrain). Calculée à la
     * demande, jamais sérialisée.
     *
     * @return list<Color> triée dans l'ordre WUBRG, sans doublon
     */
    public function colorIdentity(): array
    {
        $found = [];
        $addCost = static function (ManaCost $cost) use (&$found): void {
            foreach (array_keys($cost->colored) as $symbol) {
                $found[$symbol] = true;
            }
            foreach ($cost->hybrid as [$a, $b]) {
                $found[$a->symbol()] = true;
                $found[$b->symbol()] = true;
            }
            foreach ($cost->phyrexian as $color) {
                $found[$color->symbol()] = true; // {U/P} est bleu (CR 903.4 : le symbole phyrexian compte)
            }
        };
        if (!$this->isLand()) {
            $addCost($this->manaCost());
        }
        // « Add one mana of any color » (Treasure, Lotus Petal, Birds of Paradise, Command Tower) n'a aucun symbole de mana :
        // sans identité (CR 903.4). Ses cinq alternatives à une couleur ne comptent donc pas (terrain : `produces`).
        $anyColorLand = $this->isLand() && self::isAnyColor(array_map(static fn (string $s) => [Color::fromSymbol($s)], $this->produces));
        foreach ($anyColorLand ? $this->abilities : $this->abilities() as $ability) {
            if ($ability->manaCost !== null) {
                $addCost($ability->manaCost);
            }
            if (self::isAnyColor($ability->options)) {
                continue;
            }
            foreach ($ability->options as $option) {
                foreach ($option as $color) {
                    $found[$color->symbol()] = true;
                }
            }
        }
        foreach ($this->effects as $e) {
            if ($e->adds !== null) {
                $addCost(ManaCost::parse($e->adds));
            }
        }

        return array_values(array_filter(
            Color::cases(),
            static fn (Color $c) => $c !== Color::Colorless && isset($found[$c->symbol()]),
        ));
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

        // Sans `produces`, un terrain de démo donne {C} (Kher Keep ; Eldrazi Temple, dont la capacité propre est restreinte).
        // Pas un terrain chargé depuis Scryfall qui a sa propre capacité de mana libre (Ancient Tomb : seulement {C}{C} avec
        // dégâts), ni une fetchland, qui ne produit aucun mana.
        $produces = $this->produces;
        if ($produces === []) {
            $produces = ['C'];
            foreach ($this->abilities as $ability) {
                if ($ability->effect === 'fetch' || ($ability->isManaAbility && $ability->restrictions === [])) {
                    $produces = [];
                }
            }
        }

        return [...array_map(static fn (string $symbol) => ActivatedAbility::mana('{' . $symbol . '}'), $produces), ...$this->abilities];
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

    /** Lançable à tout moment où l'on a la priorité : éphémère ou carte avec flash. */
    public function isInstantSpeed(): bool
    {
        return $this->hasType(self::INSTANT) || in_array(self::FLASH, $this->keywords, true);
    }

    /** Représentation exposée dans les événements (tout est public dans ce simulateur). */
    public function toPublic(): array
    {
        // Ne lit que des propriétés readonly : calculé une fois.
        return $this->publicMemo ??= [
            'id' => $this->id,
            'name' => $this->name,
            'type' => count($this->types) === 1 ? $this->types[0] : $this->types,
            'cost' => $this->cost,
            'manaCost' => $this->isLand() ? null : (string) $this->manaCost(),
            'produces' => $this->produces,
            'abilities' => array_map(static fn (ActivatedAbility $a) => $a->toArray(), $this->abilities),
            // Premier effet sous les clés historiques (format inchangé pour un sort à un effet) ; `effects` seulement à partir de deux.
            'adds' => $this->effects[0]->adds ?? null,
            'effect' => $this->effects[0]->effect ?? null,
            'amount' => $this->effects[0]->amount ?? 0,
            'commander' => $this->commander,
            'power' => $this->power,
            'toughness' => $this->toughness,
        ] + ($this->token ? ['token' => true] : []) // omis si faux : au-delà de 16 clés, PHP double la table (+75 % de mémoire par état)
            + ($this->triggers === [] ? [] : ['triggers' => array_map(static fn (TriggeredAbility $t) => $t->toArray(), $this->triggers)]) // idem
            + ($this->subtypes === [] ? [] : ['subtypes' => $this->subtypes]) // idem
            + ($this->keywords === [] ? [] : ['keywords' => $this->keywords]) // idem
            + ($this->supertypes === [] ? [] : ['supertypes' => $this->supertypes]) // idem
            // `effects` à partir de deux, ou quand le premier porte une précision absente des clés historiques (`target`, `powerLife` d'un exil).
            + (count($this->effects) > 1 || ($this->effects[0] ?? null)?->isPrecise() === true ? ['effects' => array_map(static fn (SpellEffect $e) => $e->toArray(), $this->effects)] : []) // idem
            + ($this->untappedLifeCost > 0 ? ['untappedLifeCost' => $this->untappedLifeCost] : []) // idem
            + ($this->altCost === null ? [] : ['altCost' => $this->altCost]); // idem
    }

    public function toArray(): array
    {
        $key = ($this->tapped ? 't' : '') . ($this->deathtouchDamage ? 'd' : '') . "|$this->damage|$this->enteredTurn|$this->powerBonus|$this->toughnessBonus";
        if ($this->arrayMemo !== null && $this->arrayMemoKey === $key) {
            return $this->arrayMemo;
        }
        $this->arrayMemoKey = $key;

        // `boost` omis sans bonus : au-delà de 16 clés, PHP double la table (voir toPublic).
        return $this->arrayMemo = $this->toPublic() + ['tapped' => $this->tapped, 'damage' => $this->damage, 'enteredTurn' => $this->enteredTurn]
            + ($this->powerBonus !== 0 || $this->toughnessBonus !== 0 ? ['boost' => [$this->powerBonus, $this->toughnessBonus]] : [])
            + ($this->deathtouchDamage ? ['deathtouchDamage' => true] : []);
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
            $d['keywords'] ?? [],
            $d['supertypes'] ?? [],
            array_map([SpellEffect::class, 'fromArray'], $d['effects'] ?? []),
            (int) ($d['untappedLifeCost'] ?? 0),
            $d['altCost'] ?? null,
        );
        [$card->powerBonus, $card->toughnessBonus] = $d['boost'] ?? [0, 0];
        $card->deathtouchDamage = (bool) ($d['deathtouchDamage'] ?? false);

        return $card;
    }
}
