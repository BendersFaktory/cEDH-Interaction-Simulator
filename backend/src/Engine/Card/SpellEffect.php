<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Game\CardInstance;

/**
 * Un effet d'un sort à sa résolution (dans l'ordre de la liste `CardInstance::$effects`, CR 608.2c) :
 * `damage` (`amount`, ou X du coût), `counter` (`target` = `instant` : seulement un sort instantané, Dispel), `mana` (`adds`, rituel), `treasure`, `draw` (`amount` cartes),
 * `exile` (exile le permanent ciblé ; `target` = `creature` ou `permanent`, défaut `permanent` ; `powerLife` : son contrôleur gagne
 * autant de PV que sa force avant l'exil, Swords to Plowshares).
 * `copy` (cible une créature que le lanceur contrôle : il crée un jeton qui en est une copie, CR 707.2 ; les supertypes, dont légendaire, sont copiés).
 * `tutor` (cible `library:<id>`) : la carte cherchée va en main, ou sur le dessus de la bibliothèque après un mélange (`tutorTop`, Vampiric Tutor) ;
 * `tutorFilter` = types acceptés (alternatives séparées par `|`, null = toute carte) ; `lifeLoss` = PV perdus par le lanceur, même si rien n'est trouvé.
 * Plusieurs effets ciblés possibles : une cible par effet ciblé, dans l'ordre du texte (CR 608.2b, `StackResolver`).
 * Précisions d'un `counter` : `payCost` (« sauf si son contrôleur paie », Daze, Mana Leak : décision du contrôleur du sort visé à la
 * résolution), `manaValue` (seulement un sort de cette valeur de mana, Mental Misstep), `counterTo` (`exile` : Force of Negation ;
 * `library_top` : Memory Lapse ; null = cimetière), `drainMana` (Mana Drain : au début de la prochaine phase principale du lanceur,
 * il ajoute {C} égal à la valeur de mana du sort contré, trigger retardé).
 * `pact` (non ciblé, Pact of Negation) : trigger retardé « au début de votre prochain entretien, payez `payCost` ou perdez la partie ».
 * Lot H2 : `bounce` (renvoie la créature ciblée dans la main de son propriétaire, Snapback), `destroy` (détruit le permanent ciblé),
 * `blue` (Pyroblast : `if`, l'effet n'a lieu que si la cible est bleue ; Red Elemental Blast : `target`, la cible doit l'être),
 * `mode` (sort modal : chaque effet porte son mode, le lanceur en choisit un, voir `CardInstance::effectsForMode()`),
 * `mana` + `thresholdAdds` (Cabal Ritual) / `perGraveyardNamed` (Rite of Flame), `tutor` + `discardRandom` (Gamble).
 * `grant_sac_mana` (non ciblé, Rain of Filth) : jusqu'à la fin du tour, les terrains que le lanceur contrôle à la résolution gagnent
 * « sacrifier ce terrain : ajoutez `adds` » (CR 611.2c : l'ensemble est figé à la résolution ; `CardInstance::$grantedAbilities`).
 * Sélection de cartes (lot G3, décision du lanceur au milieu de la résolution, `GameState::$selection`) : `put_back` (remettre
 * `amount` cartes de sa main sur le dessus de sa bibliothèque, dans l'ordre de son choix : Brainstorm, après `draw` 3) et
 * `arrange` (regarder les `amount` cartes du dessus, les remettre dans l'ordre de son choix, mélange permis : Ponder). Seuls des
 * effets `draw` peuvent suivre un effet de sélection (ils se résolvent après la décision).
 * Lot H3 (pile et restrictions) : `redirect` (cible un objet de pile à une seule cible et lui en donne une nouvelle, choisie au
 * lancement et encodée dans la cible : `stack:<id>><nouvelle cible>` ; `target` = `spell` : un sort seulement, Misdirection ;
 * `any` : sort ou capacité, Deflecting Swat), `steal` (prend le contrôle du sort non-créature ciblé, Commandeer), `exile_spells`
 * (exile les sorts ciblés, `stack:<id>+<id>…`, Mindbreak Trap), `mill` (le joueur ciblé meule `amount` cartes, Brain Freeze),
 * `silence` (non ciblé : les adversaires du lanceur ne peuvent pas lancer de sorts ce tour), `silence_target` (le joueur ciblé
 * ne peut pas lancer de sorts ce tour), `no_attacks` (non ciblé : les créatures ne peuvent pas attaquer ce tour), `flash_grant`
 * (non ciblé : le lanceur lance ses sorts comme s'ils avaient le flash ce tour), `gift` (Gift, CR 702.174 : l'adversaire ciblé
 * reçoit le jeton `token`, engagé, avant les autres effets ; ce n'est pas une cible au sens de CR 608.2b). `bounce` accepte aussi
 * `nonland`, `opponent_creature` et `opponent_nonland` ; `chain` (Chain of Vapor) : le contrôleur du permanent renvoyé peut
 * sacrifier un terrain pour copier le sort (`GameState::$selection`, kind `chain`). `kicked` : l'effet n'a lieu que si le kicker
 * a été payé (Orim's Chant).
 * Étape 13 : `treasure` crée `amount` Treasure (0 = 1) pour le lanceur, ou, avec `target` = `spell_controller`, pour le contrôleur
 * du sort visé par le sort (An Offer You Can't Refuse : « its controller creates two Treasure tokens » ; rien si le sort fizzle).
 */
final class SpellEffect
{
    public const KINDS = ['damage', 'counter', 'mana', 'treasure', 'draw', 'exile', 'tutor', 'scry', 'copy', 'pact', 'put_back', 'arrange', 'bounce', 'destroy', 'grant_sac_mana',
        'redirect', 'steal', 'exile_spells', 'mill', 'silence', 'silence_target', 'no_attacks', 'flash_grant', 'gift'];
    /** Effets qui demandent une décision de sélection au milieu de la résolution (voir l'en-tête). */
    public const SELECTION = ['put_back', 'arrange'];
    public const TARGETED = ['damage', 'counter', 'exile', 'tutor', 'copy', 'bounce', 'destroy', 'redirect', 'steal', 'exile_spells', 'mill', 'silence_target', 'gift'];
    /** Genres de permanents qu'un effet `exile` / `bounce` / `destroy` peut cibler (`opponent_…` : contrôlé par un adversaire du lanceur). */
    public const PERMANENT_TARGETS = ['creature', 'permanent', 'nonland', 'opponent_creature', 'opponent_nonland'];
    /** Objets de pile qu'un effet `redirect` peut viser : un sort, ou un sort ou une capacité. */
    public const REDIRECT_TARGETS = ['spell', 'any'];
    /** Types de sorts qu'un effet `counter` peut viser (`target`, alternatives séparées par `|`) ; null = tout sort (Counterspell). */
    public const COUNTER_TARGETS = [CardInstance::ENCHANTMENT, CardInstance::INSTANT, CardInstance::SORCERY, 'noncreature'];
    /** Mots-clés qu'un jeton créé par un contresort peut recevoir (Swan Song : Bird 2/2 volant). */
    public const TOKEN_KEYWORDS = [CardInstance::FLYING];
    /** Types qu'un filtre de tuteur peut accepter. */
    public const TUTOR_TYPES = [CardInstance::CREATURE, CardInstance::ARTIFACT, CardInstance::ENCHANTMENT, CardInstance::INSTANT, CardInstance::SORCERY];
    /** Couleur bleue (Pyroblast, Red Elemental Blast) : `target` = la cible doit être bleue ; `if` = l'effet n'a lieu que si la cible est bleue. */
    public const BLUE_TARGET = 'target';
    public const BLUE_IF = 'if';
    /** Destinations d'un sort contré autres que le cimetière (`counterTo`). */
    public const COUNTER_EXILE = 'exile';
    public const COUNTER_LIBRARY_TOP = 'library_top';
    /** `target` d'un effet `treasure` : les jetons vont au contrôleur du sort visé par le sort (An Offer You Can't Refuse) ; `amount` = nombre (0 = 1). */
    public const TREASURE_SPELL_CONTROLLER = 'spell_controller';

    public function __construct(
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly ?string $adds = null, // mana ajouté par un effet `mana`, ex. `{R}{R}`
        public readonly ?string $target = null, // effet `exile` : `creature` | `permanent` (null = `permanent`)
        public readonly bool $powerLife = false, // effet `exile` : le contrôleur de la cible gagne PV = sa force (Swords to Plowshares)
        public readonly ?string $token = null, // effet `counter` : jeton `Nom:F/E` créé pour le contrôleur du sort contré (Swan Song)
        public readonly array $tokenKeywords = [], // mots-clés du jeton (`TOKEN_KEYWORDS`)
        public readonly bool $tutorTop = false, // effet `tutor` : carte remise sur le dessus de la bibliothèque (après mélange)
        public readonly ?string $tutorFilter = null, // effet `tutor` : types acceptés, alternatives séparées par `|`
        public readonly int $lifeLoss = 0, // effet `tutor` : PV perdus par le lanceur (Vampiric Tutor)
        public readonly ?string $payCost = null, // `counter` : « sauf si son contrôleur paie » ; `pact` : coût de l'entretien suivant
        public readonly ?int $manaValue = null, // `counter` : seulement un sort de cette valeur de mana (Mental Misstep)
        public readonly ?string $counterTo = null, // `counter` : COUNTER_EXILE | COUNTER_LIBRARY_TOP ; null = cimetière
        public readonly bool $drainMana = false, // `counter` : Mana Drain
        public readonly ?string $thresholdAdds = null, // `mana` : mana ajouté À LA PLACE de `adds` avec sept cartes ou plus au cimetière (Cabal Ritual)
        public readonly bool $perGraveyardNamed = false, // `mana` : +1 mana du premier symbole de `adds` par carte homonyme dans chaque cimetière (Rite of Flame)
        public readonly bool $discardRandom = false, // `tutor` : défausse d'une carte au hasard de sa main après la recherche (Gamble)
        public readonly ?string $blue = null, // BLUE_TARGET | BLUE_IF (`counter`, `destroy`)
        public readonly int $mode = 0, // 0 = sort non modal ; N >= 1 : l'effet appartient au mode N (« choisissez un mode », Pyroblast)
        public readonly bool $chain = false, // `bounce` : Chain of Vapor (voir l'en-tête)
        public readonly bool $kicked = false, // l'effet n'a lieu que si le kicker a été payé
    ) {
        if (!in_array($effect, self::KINDS, true)) {
            throw new \InvalidArgumentException("Effet de sort inconnu « $effect »");
        }
        $validTarget = match ($effect) {
            'exile', 'bounce', 'destroy' => in_array($target, self::PERMANENT_TARGETS, true),
            'counter', 'steal' => $target !== null && array_diff(explode('|', $target), self::COUNTER_TARGETS) === [],
            'redirect' => in_array($target, self::REDIRECT_TARGETS, true),
            'treasure' => $target === self::TREASURE_SPELL_CONTROLLER,
            default => false,
        };
        if ($target !== null && !$validTarget) {
            throw new \InvalidArgumentException("Cible « $target » invalide pour l'effet « $effect »");
        }
        if (($tutorTop || $tutorFilter !== null || $lifeLoss !== 0) && $effect !== 'tutor') {
            throw new \InvalidArgumentException("Options de tuteur sur l'effet « $effect »");
        }
        if ($tutorFilter !== null && array_diff(explode('|', $tutorFilter), self::TUTOR_TYPES) !== []) {
            throw new \InvalidArgumentException("Filtre de tuteur « $tutorFilter » invalide");
        }
        if ($lifeLoss < 0) {
            throw new \InvalidArgumentException('Perte de PV négative');
        }
        if ($payCost !== null && (!in_array($effect, ['counter', 'pact'], true) || !preg_match('/^(\{(?:\d+|[WUBRGC])\})+$/', $payCost))) {
            throw new \InvalidArgumentException("Coût à payer « $payCost » invalide pour l'effet « $effect »");
        }
        if (in_array($effect, self::SELECTION, true) && $amount < 1) {
            throw new \InvalidArgumentException("Effet « $effect » sans nombre de cartes");
        }
        if ($effect === 'pact' && $payCost === null) {
            throw new \InvalidArgumentException('Effet « pact » sans coût');
        }
        if (($manaValue !== null || $counterTo !== null || $drainMana) && $effect !== 'counter') {
            throw new \InvalidArgumentException("Options de contresort sur l'effet « $effect »");
        }
        if ($counterTo !== null && !in_array($counterTo, [self::COUNTER_EXILE, self::COUNTER_LIBRARY_TOP], true)) {
            throw new \InvalidArgumentException("Destination de sort contré « $counterTo » invalide");
        }
        if (($thresholdAdds !== null || $perGraveyardNamed) && $effect !== 'mana') {
            throw new \InvalidArgumentException("Options de rituel sur l'effet « $effect »");
        }
        if ($discardRandom && $effect !== 'tutor') {
            throw new \InvalidArgumentException("« discardRandom » n'a de sens que pour l'effet « tutor »");
        }
        if ($blue !== null && (!in_array($blue, [self::BLUE_TARGET, self::BLUE_IF], true) || !in_array($effect, ['counter', 'destroy'], true))) {
            throw new \InvalidArgumentException("Couleur « $blue » invalide pour l'effet « $effect »");
        }
        if ($mode < 0) {
            throw new \InvalidArgumentException('Mode négatif');
        }
        if ($powerLife && $effect !== 'exile') {
            throw new \InvalidArgumentException("« powerLife » n'a de sens que pour l'effet « exile »");
        }
        if ($effect === 'redirect' && $target === null) {
            throw new \InvalidArgumentException('Effet « redirect » sans genre de cible');
        }
        if ($chain && $effect !== 'bounce') {
            throw new \InvalidArgumentException("« chain » n'a de sens que pour l'effet « bounce »");
        }
        if ($token !== null) {
            ActivatedAbility::parseTokenSpec($token); // lève une exception si le jeton est mal décrit
        }
        if ($effect === 'gift' && $token === null) {
            throw new \InvalidArgumentException('Effet « gift » sans jeton');
        }
        if (($token === null && $tokenKeywords !== []) || ($token !== null && !in_array($effect, ['counter', 'gift'], true))
            || array_diff($tokenKeywords, self::TOKEN_KEYWORDS) !== []) {
            throw new \InvalidArgumentException("Jeton « $token » invalide pour l'effet « $effect »");
        }
    }

    /** Le même effet rattaché au mode `$mode` d'un sort modal. */
    public function withMode(int $mode): self
    {
        return self::fromArray(['mode' => $mode] + $this->toArray());
    }

    public function isTargeted(): bool
    {
        return in_array($this->effect, self::TARGETED, true);
    }

    /**
     * Le permanent `$card`, contrôlé par `$controller`, est-il une cible légale de cet effet pour le lanceur `$caster` (créature
     * seulement si `target` = `creature`, non-terrain pour `nonland`, adversaire du lanceur pour `opponent_…`) ?
     */
    public function canTargetPermanent(CardInstance $card, ?int $controller = null, ?int $caster = null): bool
    {
        $kind = $this->target;
        if ($kind !== null && str_starts_with($kind, 'opponent_')) {
            if ($controller === null || $controller === $caster) {
                return false;
            }
            $kind = substr($kind, 9);
        }

        return ($kind !== 'creature' || $card->isCreature()) && ($kind !== 'nonland' || !$card->isLand())
            && ($this->blue !== self::BLUE_TARGET || $card->isBlueColored());
    }

    /** Le sort `$card` (sur la pile, `$x` choisi) est-il une cible légale de cet effet `counter` (Dispel : instant seulement ; Mental Misstep : valeur de mana 1) ? */
    public function canCounter(CardInstance $card, int $x = 0): bool
    {
        if ($this->manaValue !== null && $card->manaCost()->manaValue($x) !== $this->manaValue) {
            return false;
        }
        if ($this->blue === self::BLUE_TARGET && !$card->isBlueColored()) {
            return false; // Red Elemental Blast : « counter target blue spell »
        }
        if ($this->target === null) {
            return true;
        }
        if ($this->target === 'noncreature') {
            return !$card->isCreature(); // Fierce Guardianship
        }
        foreach (explode('|', $this->target) as $type) {
            if ($card->hasType($type)) {
                return true;
            }
        }

        return false;
    }

    /** Porte-t-il une précision absente des clés historiques (`effect`, `amount`, `adds`) ? Alors `CardInstance::toArray()` sérialise `effects`. */
    public function isPrecise(): bool
    {
        return $this->target !== null || $this->powerLife || $this->token !== null || $this->tutorTop || $this->tutorFilter !== null || $this->lifeLoss !== 0
            || $this->payCost !== null || $this->manaValue !== null || $this->counterTo !== null || $this->drainMana
            || $this->thresholdAdds !== null || $this->perGraveyardNamed || $this->discardRandom || $this->blue !== null || $this->mode !== 0 || $this->chain || $this->kicked;
    }

    /** Forme sérialisée : `['effect' => …, 'amount' => …, 'adds' => …, 'target' => …]`, `amount`, `adds` et `target` omis à leur défaut. */
    public function toArray(): array
    {
        return ['effect' => $this->effect] + ($this->amount !== 0 ? ['amount' => $this->amount] : []) + ($this->adds !== null ? ['adds' => $this->adds] : [])
            + ($this->target !== null ? ['target' => $this->target] : []) + ($this->powerLife ? ['powerLife' => true] : [])
            + ($this->token !== null ? ['token' => $this->token] : []) + ($this->tokenKeywords !== [] ? ['tokenKeywords' => $this->tokenKeywords] : [])
            + ($this->tutorTop ? ['tutorTop' => true] : []) + ($this->tutorFilter !== null ? ['tutorFilter' => $this->tutorFilter] : [])
            + ($this->lifeLoss !== 0 ? ['lifeLoss' => $this->lifeLoss] : [])
            + ($this->payCost !== null ? ['payCost' => $this->payCost] : []) + ($this->manaValue !== null ? ['manaValue' => $this->manaValue] : [])
            + ($this->counterTo !== null ? ['counterTo' => $this->counterTo] : []) + ($this->drainMana ? ['drainMana' => true] : [])
            + ($this->thresholdAdds !== null ? ['thresholdAdds' => $this->thresholdAdds] : []) + ($this->perGraveyardNamed ? ['perGraveyardNamed' => true] : [])
            + ($this->discardRandom ? ['discardRandom' => true] : []) + ($this->blue !== null ? ['blue' => $this->blue] : []) + ($this->mode !== 0 ? ['mode' => $this->mode] : [])
            + ($this->chain ? ['chain' => true] : []) + ($this->kicked ? ['kicked' => true] : []);
    }

    public static function fromArray(array $d): self
    {
        return new self((string) $d['effect'], (int) ($d['amount'] ?? 0), $d['adds'] ?? null, $d['target'] ?? null, (bool) ($d['powerLife'] ?? false),
            $d['token'] ?? null, $d['tokenKeywords'] ?? [], (bool) ($d['tutorTop'] ?? false), $d['tutorFilter'] ?? null, (int) ($d['lifeLoss'] ?? 0),
            $d['payCost'] ?? null, isset($d['manaValue']) ? (int) $d['manaValue'] : null, $d['counterTo'] ?? null, (bool) ($d['drainMana'] ?? false),
            $d['thresholdAdds'] ?? null, (bool) ($d['perGraveyardNamed'] ?? false), (bool) ($d['discardRandom'] ?? false), $d['blue'] ?? null, (int) ($d['mode'] ?? 0),
            (bool) ($d['chain'] ?? false), (bool) ($d['kicked'] ?? false));
    }
}
