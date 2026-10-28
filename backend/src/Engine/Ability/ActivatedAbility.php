<?php

declare(strict_types=1);

namespace App\Engine\Ability;

use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaRestriction;

/**
 * Capacité activée d'un permanent (objet valeur). Toutes les capacités activées partagent ce modèle ;
 * `isManaAbility` décide du traitement : vrai = résolue immédiatement à l'activation (règle 605, jamais sur la pile),
 * faux = mise sur la pile comme un sort.
 *
 * Effets : `mana` (ajoute au pool : `options` = alternatives, chacune = ce que produit UN engagement), `damage`
 * (`amount` dégâts à un joueur ciblé), `draw` (piocher `amount` cartes), `token` (crée `amount` jetons créature
 * décrits par `spec` = `Nom:F/E`, ex. `Kobold:0/1`) ou `pump` (la source gagne `spec` = `+F/+E` jusqu'à la fin du tour).
 * Coût : `tap` ({T}), `manaCost`, `sacrifice` (sacrifier la source, ex. Treasure), `sacrificeCreature` (sacrifier une
 * créature qu'on contrôle, choisie dans l'action) et/ou `discard` (défausser N cartes choisies dans l'action) ; tout
 * sacrifice passe par `GameState::sacrifice()`. Une capacité de mana à coût de mana (Signet) paie ce coût avec les AUTRES
 * sources ; l'auto-paiement la traite en « convertisseur » (`ManaPayment`). `sorcerySpeed` : activable seulement quand on
 * pourrait lancer un rituel (son tour, phase principale, pile vide).
 *
 * Mana conditionnel : une option peut porter une restriction d'usage (`restrictions[option]`, voir `ManaRestriction`),
 * écrite en suffixe : `{C}{C}@eldrazi` (Eldrazi Temple). Ce mana va dans la partie restreinte de la réserve.
 *
 * Forme sérialisée (`toArray`) : les options sont des chaînes `{R}{R}` (suffixe `@kind` si restreintes) ; seules les lettres WUBRGC sont écrites ; les
 * champs `sacrificeCreature`, `discard`, `sorcerySpeed`, `spec`, `upkeepOnly`... sont omis à leur valeur par défaut.
 */
final class ActivatedAbility
{
    /** @var array<string, self> mémo de `mana()` (poids mouche) */
    private static array $manaMemo = [];

    /**
     * @param list<list<Color>>            $options      alternatives de mana produit (effet `mana`)
     * @param array<int, ManaRestriction>  $restrictions index d'option => restriction (options restreintes seulement)
     */
    public function __construct(
        public readonly bool $isManaAbility,
        public readonly string $effect = 'mana',
        public readonly bool $tap = true,
        public readonly ?ManaCost $manaCost = null,
        public readonly array $options = [],
        public readonly int $amount = 0,
        public readonly bool $sacrifice = false,
        public readonly bool $sacrificeCreature = false,
        public readonly int $discard = 0,
        public readonly bool $sorcerySpeed = false,
        public readonly string $spec = '',
        public readonly array $restrictions = [],
        public readonly int $lifeCost = 0,   // PV payés à l'activation (Mana Confluence, fetchlands) ; impossible avec moins de PV (CR 119.4)
        public readonly int $selfDamage = 0, // dégâts infligés à son contrôleur par la capacité (Ancient Tomb, Talismans, pain lands)
        public readonly bool $upkeepOnly = false, // activable seulement pendant l'entretien de son contrôleur (Mana Vault : « à l'entretien, payez {4} : dégagez-la »)
        public readonly string $fromHand = '',    // capacité activée DEPUIS LA MAIN : `exile` (exiler cette carte : Simian Spirit Guide) ou `discard` (la défausser : Channel)
        public readonly bool $discardHand = false, // coût : défausser toute sa main (Lion's Eye Diamond)
        public readonly ?string $requires = null,  // condition d'activation : REQ_METALCRAFT (Mox Opal : trois artefacts ou plus)
        public readonly int $legendaryDiscount = 0, // coût réduit de {N} générique par créature légendaire contrôlée (Otawara : 1)
        public readonly string $removeCounter = '', // coût : retirer un marqueur de cette sorte de la source (Wishclaw Talisman : `wish`)
    ) {
        if ($fromHand !== '' && !in_array($fromHand, ['exile', 'discard'], true)) {
            throw new \InvalidArgumentException("Coût depuis la main inconnu : $fromHand");
        }
        if ($requires !== null && !in_array($requires, [self::REQ_METALCRAFT, self::REQ_YOUR_TURN], true)) {
            throw new \InvalidArgumentException("Condition d'activation inconnue : $requires");
        }
        if ($effect === 'fetch') {
            $this->fetchSpec();
        }
        if ($effect === 'token') {
            $this->tokenSpec();
        }
        if ($effect === 'pump') {
            $this->boost();
        }
    }

    public const REQ_METALCRAFT = 'metalcraft';
    /** « Activate only during your turn. » (Wishclaw Talisman). */
    public const REQ_YOUR_TURN = 'your_turn';

    /**
     * Wishclaw Talisman : `$cost, {T}, retirer un marqueur $counter : chercher une carte, la mettre en main, mélanger ; un
     * adversaire prend le contrôle de la source` (effet `tutor_give_control`), seulement pendant son tour.
     */
    public static function tutorGiveControl(string $cost, string $counter): self
    {
        return new self(false, 'tutor_give_control', true, ManaCost::parse($cost), requires: self::REQ_YOUR_TURN, removeCounter: $counter);
    }

    /**
     * `{T}: ajoute …` ; `$spec` = alternatives séparées par `|`, ex. `{C}{C}`, `{R}|{G}` ou `{C}{C}@eldrazi` (restreinte).
     * `$cost` = coût de mana en plus du `{T}` (Signet : `{1}`).
     */
    public static function mana(string $spec, ?string $cost = null, int $selfDamage = 0, int $lifeCost = 0, ?string $requires = null): self
    {
        // Objet valeur immuable, fonction pure de ses arguments : instances partagées (poids mouche), aucun état de partie.
        return self::$manaMemo[$spec . "\0" . $cost . "\0" . $selfDamage . "\0" . $lifeCost . "\0" . $requires] ??= (function () use ($spec, $cost, $selfDamage, $lifeCost, $requires): self {
            [$options, $restrictions] = self::parseOptions(explode('|', $spec));

            return new self(true, 'mana', true, $cost === null ? null : ManaCost::parse($cost), $options, restrictions: $restrictions,
                lifeCost: $lifeCost, selfDamage: $selfDamage, requires: $requires);
        })();
    }

    /** Simian Spirit Guide : exiler cette carte de sa main pour un mana (capacité de mana depuis la main, sans {T}). */
    public static function exileFromHandMana(string $spec): self
    {
        [$options] = self::parseOptions(explode('|', $spec));

        return new self(true, 'mana', false, null, $options, fromHand: 'exile');
    }

    /** Lion's Eye Diamond : défausser sa main et sacrifier la source pour trois mana d'une couleur (`$spec` = les cinq alternatives `{W}{W}{W}|…`). */
    public static function discardHandMana(string $spec): self
    {
        [$options] = self::parseOptions(explode('|', $spec));

        return new self(true, 'mana', false, null, $options, sacrifice: true, discardHand: true);
    }

    /**
     * Channel (Otawara) : depuis la main, `[coût]` et défausser cette carte pour renvoyer un permanent ciblé dans la main de
     * son propriétaire ; `$discount` = réduction de coût générique par créature légendaire contrôlée.
     */
    public static function channelBounce(string $cost, int $discount = 0): self
    {
        return new self(false, 'bounce', false, ManaCost::parse($cost), fromHand: 'discard', legendaryDiscount: $discount);
    }

    /** `Sacrifice ~: Add …` (capacité de mana accordée aux terrains par Rain of Filth, sans {T}). */
    public static function sacrificeMana(string $spec): self
    {
        [$options] = self::parseOptions(explode('|', $spec));

        return new self(true, 'mana', false, null, $options, sacrifice: true);
    }

    /** Ranger-Captain of Eos : sacrifier la source, les adversaires ne peuvent pas lancer de sort non-créature ce tour. */
    public static function noncreatureLock(): self
    {
        return new self(false, 'lock_noncreature', false, null, sacrifice: true);
    }

    /** Mana produit dynamique : `spec` d'une capacité de mana dont les alternatives (les cinq couleurs) sont filtrées à l'usage. */
    public const DYNAMIC_OPPONENT_LAND = 'oppland'; // Fellwar Stone, Exotic Orchard : couleurs que produit un terrain adverse
    public const DYNAMIC_IMPRINT = 'imprint';       // Chrome Mox : couleurs de la carte exilée
    public const DYNAMIC_LEGENDARY = 'legendary';   // Mox Amber : couleurs des créatures et planeswalkers légendaires que l'on contrôle
    public const DYNAMIC_LUCK = 'luck';             // Gemstone Caverns : {C}, ou n'importe quelle couleur avec un marqueur de chance

    /**
     * `{T}: un mana d'une couleur parmi celles que `$kind` autorise` : cinq alternatives à une couleur, dont seules les
     * autorisées sont utilisables (voir `PlayerState::allowedColors()`).
     */
    public static function dynamicMana(string $kind): self
    {
        if (!in_array($kind, [self::DYNAMIC_OPPONENT_LAND, self::DYNAMIC_IMPRINT, self::DYNAMIC_LEGENDARY, self::DYNAMIC_LUCK], true)) {
            throw new \InvalidArgumentException("Mana dynamique inconnu : $kind");
        }

        return new self(true, 'mana', true, null, array_map(self::parseOption(...), [...($kind === self::DYNAMIC_LUCK ? ['{C}'] : []), '{W}', '{U}', '{B}', '{R}', '{G}']), spec: $kind);
    }

    /** Vrai pour une capacité de mana dont les couleurs dépendent de l'état de la partie (`dynamicMana()`). */
    public function isDynamicMana(): bool
    {
        return $this->isManaAbility && in_array($this->spec, [self::DYNAMIC_OPPONENT_LAND, self::DYNAMIC_IMPRINT, self::DYNAMIC_LEGENDARY, self::DYNAMIC_LUCK], true);
    }

    /** `[coût] : dégager la source` (Grim Monolith : `{4}` ; Mana Vault : `{4}` pendant son entretien seulement). Non-mana, passe par la pile. */
    public static function untap(string $cost, bool $upkeepOnly = false): self
    {
        return new self(false, 'untap', false, ManaCost::parse($cost), upkeepOnly: $upkeepOnly);
    }

    /**
     * Fetchland : `{T}, [payer $lifeCost PV], sacrifier : chercher un terrain` de l'un des types `$spec` (`Island|Swamp`, carte
     * dont un sous-type de terrain convient, duals comprises) ou `basic` (terrain de base), mis sur le champ de bataille
     * (engagé si `$tapped`), puis mélanger. Le terrain cherché est choisi à l'activation (cible `library:<id>`).
     */
    public static function fetch(string $spec, bool $tapped = false, int $lifeCost = 0): self
    {
        return new self(false, 'fetch', true, null, [], 0, true, spec: $spec . ($tapped ? ':tapped' : ''), lifeCost: $lifeCost);
    }

    /** @return array{types: list<string>, basic: bool, tapped: bool} ce que cherche une capacité `fetch` */
    public function fetchSpec(): array
    {
        if (!preg_match('/^(basic|[A-Z][a-z]+(?:\|[A-Z][a-z]+)*)(:tapped)?$/', $this->spec, $m)) {
            throw new \InvalidArgumentException("Recherche invalide : {$this->spec}");
        }

        return ['types' => $m[1] === 'basic' ? [] : explode('|', $m[1]), 'basic' => $m[1] === 'basic', 'tapped' => isset($m[2])];
    }

    /** Le terrain `$land` convient-il à cette recherche (`fetch`) ? */
    public function fetches(\App\Engine\Game\CardInstance $land): bool
    {
        if (!$land->isLand()) {
            return false;
        }
        ['types' => $types, 'basic' => $basic] = $this->fetchSpec();
        if ($basic) {
            return in_array($land->name, \App\Engine\Card\Decks::BASIC_LANDS, true);
        }
        foreach ($types as $type) {
            // Terrain de base des decks de démo : construit sans sous-type, reconnu par son nom.
            if ($land->hasSubtype($type, \App\Engine\Game\CardInstance::LAND) || $land->name === $type) {
                return true;
            }
        }

        return false;
    }

    /** Treasure : `{T}, sacrifier : un mana de la couleur de son choix`. */
    public static function treasure(): self
    {
        return new self(true, 'mana', true, null, array_map(self::parseOption(...), ['{W}', '{U}', '{B}', '{R}', '{G}']), 0, true);
    }

    /** `{T}: $amount dégâts à un joueur` (capacité non-mana, passe par la pile). */
    public static function pinger(int $amount = 1): self
    {
        return new self(false, 'damage', true, null, [], $amount);
    }

    /** Goblin Bombardment : `sacrifier une créature : $amount dégât à un joueur`. */
    public static function bombardment(int $amount = 1): self
    {
        return new self(false, 'damage', false, null, [], $amount, sacrificeCreature: true);
    }

    /** `[coût], [{T}], [sacrifier] : piocher $n cartes` (Mind Stone : `{1}`, {T}, sacrifier). */
    public static function draw(int $n, ?string $cost, bool $tap = true, bool $sacrifice = false, bool $sorcerySpeed = false): self
    {
        return new self(false, 'draw', $tap, $cost === null ? null : ManaCost::parse($cost), [], $n, $sacrifice, sorcerySpeed: $sorcerySpeed);
    }

    /** `$cost : chaque joueur pioche $n cartes` (Faerie Mastermind : `{3}{U}`), sans engagement. */
    public static function eachDraw(int $n, string $cost): self
    {
        return new self(false, 'each_draw', false, ManaCost::parse($cost), [], $n);
    }

    /** `$cost : deux joueurs ciblés piochent chacun $n cartes` (Gleaming Splendor : `{2}{W}`), sans engagement. */
    public static function twoPlayersDraw(int $n, string $cost): self
    {
        return new self(false, 'two_players_draw', false, ManaCost::parse($cost), [], $n);
    }

    /**
     * Necropotence : `Pay $life life` : exiler face cachée la carte du dessus de sa bibliothèque ; elle va dans sa main au début
     * de sa prochaine étape de fin (effet `necro`, trigger retardé `necro_return`).
     */
    public static function necro(int $life): self
    {
        return new self(false, 'necro', false, lifeCost: $life);
    }

    /** `[coût], [{T}] : créer $n jetons $spec` (`Nom:F/E`, ex. Kher Keep : `{1}{R}`, {T} -> `Kobold:0/1`). */
    public static function token(string $spec, int $n, ?string $cost, bool $tap = true): self
    {
        return new self(false, 'token', $tap, $cost === null ? null : ManaCost::parse($cost), [], $n, spec: $spec);
    }

    /** `[coût], [défausser $discard] : la source gagne $boost (`+F/+E`) jusqu'à la fin du tour` (Wild Mongrel). */
    public static function pump(string $boost, ?string $cost = null, int $discard = 0): self
    {
        return new self(false, 'pump', false, $cost === null ? null : ManaCost::parse($cost), [], 0, discard: $discard, spec: $boost);
    }

    /** @return array{name: string, power: int, toughness: int} jeton décrit par `spec` (`Nom:F/E`) */
    public function tokenSpec(): array
    {
        return self::parseTokenSpec($this->spec);
    }

    /** @return array{name: string, power: int, toughness: int} jeton décrit par `Nom:F/E` (partagé avec `TriggeredAbility`) */
    public static function parseTokenSpec(string $spec): array
    {
        if (!preg_match('#^([^:]+):(\d+)/(\d+)$#', $spec, $m)) {
            throw new \InvalidArgumentException("Jeton invalide : $spec");
        }

        return ['name' => $m[1], 'power' => (int) $m[2], 'toughness' => (int) $m[3]];
    }

    /** @return array{0: int, 1: int} bonus de force/endurance décrit par `spec` (`+F/+E`, ex. `+1/+1`, `-1/+0`) */
    public function boost(): array
    {
        if (!preg_match('#^([+-]\d+)/([+-]\d+)$#', $this->spec, $m)) {
            throw new \InvalidArgumentException("Bonus invalide : {$this->spec}");
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @param list<string> $texts
     * @return array{0: list<list<Color>>, 1: array<int, ManaRestriction>}
     */
    private static function parseOptions(array $texts): array
    {
        $options = [];
        $restrictions = [];
        foreach ($texts as $i => $text) {
            $parts = explode('@', $text, 2);
            $options[] = self::parseOption($parts[0]);
            if (isset($parts[1])) {
                $restrictions[$i] = new ManaRestriction($parts[1]);
            }
        }

        return [$options, $restrictions];
    }

    /** Restriction d'usage du mana produit par l'alternative `$option` (null = mana libre). */
    public function restriction(int $option): ?ManaRestriction
    {
        return $this->restrictions[$option] ?? null;
    }

    /** @return list<Color> */
    private static function parseOption(string $text): array
    {
        if (!preg_match('/^(\{[WUBRGC]\})+$/', $text)) {
            throw new \InvalidArgumentException("Mana produit invalide : $text");
        }
        preg_match_all('/\{([WUBRGC])\}/', $text, $m);

        return array_map(Color::fromSymbol(...), $m[1]);
    }

    /** Mana produit par l'alternative `$option`, par symbole : `['C' => 2]`. */
    public function produced(int $option = 0): array
    {
        $out = [];
        foreach ($this->options[$option] ?? [] as $color) {
            $out[$color->symbol()] = ($out[$color->symbol()] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Libellé court, ex. `{T} : {R}`, `{1}, {T} : {U}{R}`, `{T}, sacrifier : {G}`, `{T} : 1 dégât`,
     * `sacrifier une créature : 1 dégât`, `défausser 1 : +1/+1`.
     */
    public function label(int $option = 0): string
    {
        $cost = implode(', ', array_filter([
            $this->manaCost !== null ? (string) $this->manaCost : '',
            $this->tap ? '{T}' : '',
            $this->fromHand === 'exile' ? 'exiler cette carte de votre main' : '',
            $this->fromHand === 'discard' ? 'défausser cette carte' : '',
            $this->discardHand ? 'défausser votre main' : '',
            $this->sacrifice ? 'sacrifier' : '',
            $this->sacrificeCreature ? 'sacrifier une créature' : '',
            $this->discard > 0 ? "défausser {$this->discard}" : '',
            $this->lifeCost > 0 ? "payer {$this->lifeCost} PV" : '',
            $this->removeCounter !== '' ? "retirer un marqueur {$this->removeCounter}" : '',
        ], static fn (string $p) => $p !== ''));
        $what = match ($this->effect) {
            'mana' => implode('', array_map(static fn (Color $c) => '{' . $c->symbol() . '}', $this->options[$option] ?? []))
                . ($this->restriction($option) === null ? '' : ' (' . $this->restriction($option)->label() . ')'),
            'damage' => sprintf('%d dégât%s', $this->amount, $this->amount > 1 ? 's' : ''),
            'draw' => sprintf('piocher %d', $this->amount),
            'each_draw' => sprintf('chaque joueur pioche %d', $this->amount),
            'two_players_draw' => sprintf('deux joueurs ciblés piochent %d', $this->amount),
            'necro' => 'exiler face cachée la carte du dessus ; elle arrive en main à votre prochaine étape de fin',
            'tutor_give_control' => 'chercher une carte ; un adversaire prend le contrôle de cette carte (pendant votre tour)',
            'token' => sprintf('%d jeton%s %s', $this->amount, $this->amount > 1 ? 's' : '', str_replace(':', ' ', $this->spec)),
            'pump' => $this->spec,
            'untap' => $this->upkeepOnly ? 'dégager (entretien)' : 'dégager',
            'bounce' => 'renvoyer en main' . ($this->legendaryDiscount > 0 ? ' (moins cher par créature légendaire)' : ''),
            'lock_noncreature' => 'les adversaires ne peuvent pas lancer de sort non-créature ce tour',
            'fetch' => 'chercher ' . ($this->fetchSpec()['basic'] ? 'un terrain de base' : implode(' ou ', $this->fetchSpec()['types'])) . ($this->fetchSpec()['tapped'] ? ' (engagé)' : ''),
            default => $this->effect,
        };

        return "$cost : $what" . ($this->selfDamage > 0 ? ", {$this->selfDamage} dégât" . ($this->selfDamage > 1 ? 's' : '') . ' à vous' : '')
            . ($this->sorcerySpeed ? ' (rituel)' : '');
    }

    public function toArray(): array
    {
        return [
            'isManaAbility' => $this->isManaAbility,
            'effect' => $this->effect,
            'tap' => $this->tap,
            'manaCost' => $this->manaCost === null ? null : (string) $this->manaCost,
            'options' => array_map($this->optionText(...), array_keys($this->options)),
            'amount' => $this->amount,
            'sacrifice' => $this->sacrifice,
        ] + array_filter([
            'sacrificeCreature' => $this->sacrificeCreature,
            'discard' => $this->discard,
            'sorcerySpeed' => $this->sorcerySpeed,
            'spec' => $this->spec,
            'lifeCost' => $this->lifeCost,
            'selfDamage' => $this->selfDamage,
            'upkeepOnly' => $this->upkeepOnly,
            'fromHand' => $this->fromHand,
            'discardHand' => $this->discardHand,
            'requires' => $this->requires,
            'legendaryDiscount' => $this->legendaryDiscount,
            'removeCounter' => $this->removeCounter,
        ]);
    }

    public static function fromArray(array $d): self
    {
        [$options, $restrictions] = self::parseOptions($d['options'] ?? []);

        return new self(
            (bool) $d['isManaAbility'],
            $d['effect'] ?? 'mana',
            (bool) ($d['tap'] ?? true),
            isset($d['manaCost']) ? ManaCost::parse($d['manaCost']) : null,
            $options,
            (int) ($d['amount'] ?? 0),
            (bool) ($d['sacrifice'] ?? false),
            (bool) ($d['sacrificeCreature'] ?? false),
            (int) ($d['discard'] ?? 0),
            (bool) ($d['sorcerySpeed'] ?? false),
            (string) ($d['spec'] ?? ''),
            $restrictions,
            (int) ($d['lifeCost'] ?? 0),
            (int) ($d['selfDamage'] ?? 0),
            (bool) ($d['upkeepOnly'] ?? false),
            (string) ($d['fromHand'] ?? ''),
            (bool) ($d['discardHand'] ?? false),
            $d['requires'] ?? null,
            (int) ($d['legendaryDiscount'] ?? 0),
            (string) ($d['removeCounter'] ?? ''),
        );
    }

    /** Alternative `$option` sous forme texte : `{C}{C}`, suffixée `@kind` si restreinte. */
    private function optionText(int $option): string
    {
        $text = implode('', array_map(static fn (Color $c) => '{' . $c->symbol() . '}', $this->options[$option] ?? []));
        $restriction = $this->restriction($option);

        return $restriction === null ? $text : $text . '@' . $restriction->kind;
    }
}
