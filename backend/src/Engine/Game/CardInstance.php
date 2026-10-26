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
    /** Force of Negation : exiler une carte bleue de sa main (sans PV), seulement si ce n'est pas le tour du lanceur. */
    public const ALT_PITCH_BLUE_OFF_TURN = 'pitch_blue_off_turn';
    /** Daze (`return_islands:1`), Gush (`return_islands:2`) : renvoyer N Îles que l'on contrôle dans la main de leur propriétaire. */
    public const ALT_RETURN_ISLANDS = 'return_islands:';

    /** Misdirection / Snapback / Commandeer : exiler une carte bleue de sa main, sans PV (`ALT_PITCH_BLUE` en demande 1). */
    public const ALT_PITCH_BLUE_FREE = 'pitch_blue_free';

    /** Commandeer : exiler deux cartes bleues de sa main (`CastSpell::ALT_PITCH` suivi des deux ids séparés par une virgule). */
    public const ALT_PITCH_TWO_BLUE = 'pitch_two_blue';
    /** Mindbreak Trap : gratuit si un adversaire a lancé trois sorts ou plus ce tour (`CastSpell::ALT_FREE`). */
    public const ALT_OPPONENT_THREE_SPELLS = 'opponent_three_spells';

    /**
     * Capacités statiques (`statics`, lot H3), lues par `GameState::canCast()` et `GameState::castsAtInstantSpeed()` tant que
     * le permanent est sur le champ de bataille : Valley Floodcaller (son contrôleur lance ses sorts non-créature comme s'ils
     * avaient le flash), Lavinia (chaque adversaire ne peut pas lancer de sort non-créature de valeur de mana supérieure à son
     * nombre de terrains), Voice of Victory (les adversaires ne peuvent pas lancer de sort pendant le tour de son contrôleur).
     */
    public const STATIC_NONCREATURE_FLASH = 'noncreature_flash';
    public const STATIC_OPPONENTS_MV_LANDS = 'opponents_mv_lands';
    public const STATIC_OPPONENTS_NO_CAST_YOUR_TURN = 'opponents_no_cast_your_turn';
    public const STATICS = [self::STATIC_NONCREATURE_FLASH, self::STATIC_OPPONENTS_MV_LANDS, self::STATIC_OPPONENTS_NO_CAST_YOUR_TURN];

    /** Coût additionnel (`extraCost`) : sacrifier une créature que l'on contrôle (Culling the Weak, Diabolic Intent). */
    public const EXTRA_SACRIFICE_CREATURE = 'sacrifice_creature';

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
    /** Partner (CR 702.124) : deux commandants si les deux l'ont (`Decks::validate()`). Sans effet en jeu. */
    public const PARTNER = 'partner';
    /** Storm (CR 702.40) : en le lançant, un trigger le copie une fois par sort lancé avant lui ce tour (`GameState::$spellsCast`). */
    public const STORM = 'storm';

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

    /**
     * Couleurs (lettres WUBRG) de la carte exilée par l'imprint (Chrome Mox) ; vide = rien d'exilé ou carte incolore. État de
     * partie mutable : sérialisé sous `imprint` par `toArray()` (pas par `toPublic()`), omis quand vide.
     *
     * @var list<string>
     */
    public array $imprint = [];

    /**
     * Capacités accordées jusqu'à la fin du tour (Rain of Filth), placées après les capacités de la carte dans `abilities()` ;
     * vidées au cleanup et quand la carte quitte le champ de bataille. Sérialisé sous `granted` par `toArray()`, omis quand vide.
     *
     * @var list<ActivatedAbility>
     */
    public array $grantedAbilities = [];

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
        public readonly ?string $altCost = null, // coût alternatif (ALT_PITCH_BLUE | ALT_COMMANDER | ALT_PITCH_BLUE_OFF_TURN | ALT_RETURN_ISLANDS . N), null = coût de mana seul
        public readonly bool $noUntap = false,   // « ne se dégage pas pendant votre étape de dégagement » (Mana Vault, Grim Monolith)
        public readonly ?string $extraCost = null, // coût additionnel du sort (EXTRA_SACRIFICE_CREATURE), null = aucun
        public readonly int $tappedAfterOwnTurn = 0, // terrain qui arrive engagé après son N-ième tour (Starting Town : 3), 0 = jamais
        /** @var list<string> capacités statiques (constantes STATIC_…) */
        public readonly array $statics = [],
        public readonly ?string $kicker = null, // coût de kicker (Orim's Chant : `{W}`), null = pas de kicker
    ) {
        if (array_diff($statics, self::STATICS) !== []) {
            throw new \InvalidArgumentException("$name : capacité statique inconnue");
        }
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
    public function targetedEffects(int $mode = 0): array
    {
        return array_values(array_filter($this->effectsForMode($mode), static fn (SpellEffect $e) => $e->isTargeted()));
    }

    /** Sort modal (« choisissez un mode ») : au moins un de ses effets porte un mode (`SpellEffect::$mode`). */
    public function isModal(): bool
    {
        foreach ($this->effects as $e) {
            if ($e->mode > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> modes du sort (1, 2...), vide s'il n'est pas modal */
    public function modes(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (SpellEffect $e) => $e->mode, $this->effects))));
    }

    /**
     * Effets joués pour le mode choisi : ceux du mode `$mode` d'un sort modal, tous les effets sinon.
     *
     * @return list<SpellEffect>
     */
    public function effectsForMode(int $mode = 0): array
    {
        if ($mode === 0 || !$this->isModal()) {
            return $this->effects;
        }

        return array_values(array_filter($this->effects, static fn (SpellEffect $e) => $e->mode === $mode));
    }

    /** Bleue (CR 105.2) : un symbole bleu dans son coût de mana (Pyroblast ; ≠ identité de couleurs, voir `isBlue()`). */
    public function isBlueColored(): bool
    {
        return in_array('U', $this->colors(), true);
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

    /** Nombre d'Îles à renvoyer en main pour le coût alternatif (`ALT_RETURN_ISLANDS`), 0 sans ce coût. */
    public function islandsToReturn(): int
    {
        return $this->altCost !== null && str_starts_with($this->altCost, self::ALT_RETURN_ISLANDS) ? (int) substr($this->altCost, strlen(self::ALT_RETURN_ISLANDS)) : 0;
    }

    /** Île : terrain de sous-type Island (Volcanic Island compris) ; terrain de base des decks de démo, construit sans sous-type, reconnu par son nom. */
    public function isIsland(): bool
    {
        return $this->isLand() && ($this->hasSubtype('Island', self::LAND) || $this->name === 'Island');
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

    public function hasStatic(string $static): bool
    {
        return in_array($static, $this->statics, true);
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


    /** @param list<list<Color>> $options vrai si ce sont exactement les cinq couleurs, chaque alternative d'une seule couleur (un ou plusieurs mana) */
    private static function isAnyColor(array $options): bool
    {
        $symbols = array_map(static fn (array $o): string => $o !== [] && count(array_unique(array_map(static fn (Color $c) => $c->symbol(), $o))) === 1 ? $o[0]->symbol() : '', $options);
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

    /**
     * Couleurs de la carte (CR 105.2) : celles des symboles de son coût de mana (hybride = les deux, phyrexien = sa couleur ;
     * `{C}` et le générique n'en sont pas). Contrairement à `colorIdentity()`, ignore les capacités.
     *
     * @return list<string> lettres dans l'ordre WUBRG
     */
    public function colors(): array
    {
        if ($this->isLand()) {
            return [];
        }
        $cost = $this->manaCost();
        $found = array_fill_keys(array_keys($cost->colored), true);
        foreach ($cost->hybrid as [$a, $b]) {
            $found[$a->symbol()] = true;
            $found[$b->symbol()] = true;
        }
        foreach ($cost->phyrexian as $color) {
            $found[$color->symbol()] = true;
        }

        return array_values(array_filter(['W', 'U', 'B', 'R', 'G'], static fn (string $c) => isset($found[$c])));
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
        $base = $this->declaredAbilities();

        return $this->grantedAbilities === [] ? $base : [...$base, ...$this->grantedAbilities];
    }

    /** @return list<ActivatedAbility> */
    private function declaredAbilities(): array
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
            + ($this->altCost === null ? [] : ['altCost' => $this->altCost]) // idem
            + ($this->noUntap ? ['noUntap' => true] : []) // idem
            + ($this->extraCost === null ? [] : ['extraCost' => $this->extraCost]) // idem
            + ($this->tappedAfterOwnTurn > 0 ? ['tappedAfterOwnTurn' => $this->tappedAfterOwnTurn] : []) // idem
            + ($this->statics === [] ? [] : ['statics' => $this->statics]) // idem
            + ($this->kicker === null ? [] : ['kicker' => $this->kicker]); // idem
    }

    public function toArray(): array
    {
        $key = ($this->tapped ? 't' : '') . ($this->deathtouchDamage ? 'd' : '') . "|$this->damage|$this->enteredTurn|$this->powerBonus|$this->toughnessBonus" . ($this->imprint === [] ? '' : '|' . implode('', $this->imprint)) . ($this->grantedAbilities === [] ? '' : '|g' . count($this->grantedAbilities));
        if ($this->arrayMemo !== null && $this->arrayMemoKey === $key) {
            return $this->arrayMemo;
        }
        $this->arrayMemoKey = $key;

        // `boost` omis sans bonus : au-delà de 16 clés, PHP double la table (voir toPublic).
        return $this->arrayMemo = $this->toPublic() + ['tapped' => $this->tapped, 'damage' => $this->damage, 'enteredTurn' => $this->enteredTurn]
            + ($this->powerBonus !== 0 || $this->toughnessBonus !== 0 ? ['boost' => [$this->powerBonus, $this->toughnessBonus]] : [])
            + ($this->deathtouchDamage ? ['deathtouchDamage' => true] : [])
            + ($this->imprint === [] ? [] : ['imprint' => $this->imprint])
            + ($this->grantedAbilities === [] ? [] : ['granted' => array_map(static fn (ActivatedAbility $a) => $a->toArray(), $this->grantedAbilities)]);
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
            (bool) ($d['noUntap'] ?? false),
            $d['extraCost'] ?? null,
            (int) ($d['tappedAfterOwnTurn'] ?? 0),
            $d['statics'] ?? [],
            $d['kicker'] ?? null,
        );
        [$card->powerBonus, $card->toughnessBonus] = $d['boost'] ?? [0, 0];
        $card->deathtouchDamage = (bool) ($d['deathtouchDamage'] ?? false);
        $card->imprint = $d['imprint'] ?? [];
        $card->grantedAbilities = array_map([ActivatedAbility::class, 'fromArray'], $d['granted'] ?? []);

        return $card;
    }
}
