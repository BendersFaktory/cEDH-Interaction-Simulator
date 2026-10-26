<?php

declare(strict_types=1);

namespace App\Engine\Ability;

use App\Engine\Game\CardInstance;

/**
 * Trigger d'un permanent (objet valeur). `event` : `etb` (la carte entre sur le champ de bataille), `dies`
 * (la créature meurt : champ de bataille -> cimetière, jeton compris), `upkeep` (début de l'entretien de son
 * contrôleur), `attacks` (cette créature est déclarée attaquante), `end_step` (début de l'étape de fin de son
 * contrôleur), `cast` (son contrôleur lance un sort, restreint par `filter`) ou `discard` (son contrôleur défausse une
 * carte). Une fois survenu, le trigger attend dans `GameState::$pendingTriggers` jusqu'à ce qu'un joueur reçoive la
 * priorité ; `TriggerManager` la met alors sur la pile (ordre APNAP).
 *
 * Effets (même vocabulaire que `ActivatedAbility`) : `damage` (`amount` dégâts à un joueur ciblé), `draw` (piocher
 * `amount` cartes) ou `token` (`amount` jetons créature décrits par `spec` = `Nom:F/E`) ou `treasure` (`amount` jetons Treasure), `self_damage` (`amount` dégâts à son contrôleur) ou `imprint` (Chrome Mox : exiler une carte
 * non-artefact non-terrain de sa main, choisie en mettant le trigger sur la pile — cible `card:<id>`, absente = ne rien exiler).
 *
 * `opponent_cast` (un adversaire de son contrôleur lance un sort ; le lanceur est retenu par `PendingTrigger::$opponent` et
 * devient la cible `player:<id>` du trigger) avec l'effet `draw_unless` (Rhystic Study : son contrôleur pioche `amount` cartes
 * sauf si le lanceur paie `spec`, décision du lanceur à la résolution comme Daze ; le « you may » est choisi sauf si la bibliothèque est vide).
 *
 * `postcombat_main` (début de chacune des phases principales d'après combat de son contrôleur) avec l'effet `pay_life_draw`
 * (Tymna the Weaver : X = nombre d'adversaires blessés au combat ce tour, `GameState::$combatDamaged` ; son contrôleur paie X PV
 * et pioche X cartes ; le « you may » est choisi tant que X > 0 et que les PV restent positifs).
 *
 * Filtre (`filter`) : pour `opponent_cast`, `second_spell` = seulement le deuxième sort du lanceur ce tour (Kraum,
 * `GameState::$spellsCast`) ; pour `cast`, `''` = tout sort, `instant_sorcery` = éphémère ou rituel ; pour les autres événements, `tapped` =
 * seulement si la source est engagée (Mana Vault au début de l'étape de pioche).
 *
 * Triggers retardés (CR 603.7, créés par la résolution d'un sort, gardés dans `GameState::$delayedTriggers` jusqu'à leur
 * événement, jamais portés par une carte) : `next_upkeep` (prochain entretien de son contrôleur) et `next_main` (début de sa
 * prochaine phase principale). Effets : `pact` (payer `spec` ou perdre la partie, décision à la résolution) et `mana`
 * (ajouter `amount` {C}, Mana Drain).
 *
 * Lot H3 : `storm` (trigger du sort lancé lui-même, jamais porté par une carte : `amount` copies du sort ; `spec` = son id de
 * pile, son mode, son X et ses cibles, `PriorityManager::stormSpec()`), `counter_spell` (Lavinia : contre le sort ciblé
 * `stack:<id>`, filtre `no_mana` = aucun mana dépensé pour le lancer), `mobilize` (Voice of Victory, `attacks` : `amount`
 * jetons Warrior 1/1 rouges engagés et attaquants, sacrifiés au début de la prochaine étape de fin par un trigger retardé
 * `next_end` d'effet `sacrifice_tokens`, `spec` = leurs ids) et `pump_untap` (Valley Floodcaller, `cast` filtré `noncreature` :
 * ses créatures des sous-types `spec` (séparés par `|`) gagnent +1/+1 jusqu'à la fin du tour et se dégagent).
 *
 * Lot H4 : `opponent_draw` (un adversaire de son contrôleur pioche une carte, retenu par `PendingTrigger::$opponent` ; filtre
 * `second_draw` = sa deuxième carte du tour, `GameState::$cardsDrawn` (Faerie Mastermind) ; `not_draw_step_first` = sauf la
 * première qu'il pioche dans chacune de ses étapes de pioche (Orcish Bowmasters)). `ping_amass` (Orcish Bowmasters : `amount`
 * dégâts à un joueur ciblé, puis Amass `spec` = `Type:N`, CR 701.47). `cumulative_upkeep` (Mystic Remora, `upkeep` : un marqueur
 * d'âge, puis payer `spec` par marqueur d'âge ou sacrifier la source ; décision `PayCost` à la résolution). Pour `opponent_cast`,
 * `first_noncreature` = premier sort non-créature du lanceur ce tour (Esper Sentinel, `GameState::$noncreatureCast`) et
 * `noncreature` = tout sort non-créature (Mystic Remora). `draw_unless` accepte `spec` = `{X}` : X = force de la source (Esper
 * Sentinel ; X = 0 : rien à payer, pas de pioche).
 *
 * `pay_untap` (Mana Vault, `upkeep`) : son contrôleur peut payer `spec` pour dégager la source (décision `PayCost` à la
 * résolution, proposée seulement si la source est encore engagée sur le champ de bataille).
 *
 * Forme sérialisée (`toArray`) : `spec` et `filter` omis s'ils sont vides.
 */
final class TriggeredAbility
{
    public const ETB = 'etb';
    public const DIES = 'dies';
    public const UPKEEP = 'upkeep';
    public const ATTACKS = 'attacks';
    public const END_STEP = 'end_step';
    public const CAST = 'cast';
    public const OPPONENT_CAST = 'opponent_cast';
    public const DISCARD = 'discard';
    public const DRAW_STEP = 'draw_step';
    public const POSTCOMBAT_MAIN = 'postcombat_main';
    /** Son contrôleur joue un autre terrain (City of Traitors). */
    public const LAND_PLAYED = 'land_played';
    /** Triggers retardés (voir l'en-tête). */
    public const NEXT_UPKEEP = 'next_upkeep';
    public const NEXT_MAIN = 'next_main';
    /** Début de la prochaine étape de fin (Mobilize : sacrifier les jetons). */
    public const NEXT_END = 'next_end';
    /** Un adversaire de son contrôleur pioche une carte (Faerie Mastermind, Orcish Bowmasters). */
    public const OPPONENT_DRAW = 'opponent_draw';

    /** Coût `{X}` de `draw_unless` : X = force de la source (Esper Sentinel). */
    public const X_POWER = '{X}';

    /** Filtre de `cast` : le sort lancé est un éphémère ou un rituel. */
    public const INSTANT_SORCERY = 'instant_sorcery';
    /** Filtre de `opponent_cast` : le sort est le deuxième que le lanceur lance ce tour (Kraum). */
    public const SECOND_SPELL = 'second_spell';
    /** Filtre de `cast` : le sort lancé n'est pas une créature (Valley Floodcaller). */
    public const NONCREATURE = 'noncreature';
    /** Filtre de `opponent_cast` : aucun mana n'a été dépensé pour lancer le sort (Lavinia, Azorius Renegade). */
    public const NO_MANA = 'no_mana';

    /** Filtre de `opponent_cast` : premier sort non-créature du lanceur ce tour (Esper Sentinel). */
    public const FIRST_NONCREATURE = 'first_noncreature';
    /** Filtre de `opponent_draw` : deuxième carte piochée par cet adversaire ce tour (Faerie Mastermind). */
    public const SECOND_DRAW = 'second_draw';
    /** Filtre de `opponent_draw` : sauf la première carte piochée dans chacune de ses étapes de pioche (Orcish Bowmasters). */
    public const NOT_DRAW_STEP_FIRST = 'not_draw_step_first';

    /** Filtre « si la source est engagée » (clause « if ~ is tapped », CR 603.4 : vérifiée à l'arrivée du trigger et à sa résolution). */
    public const TAPPED = 'tapped';

    private const EVENTS = [self::ETB, self::DIES, self::UPKEEP, self::ATTACKS, self::END_STEP, self::CAST, self::OPPONENT_CAST, self::DISCARD, self::DRAW_STEP, self::POSTCOMBAT_MAIN, self::LAND_PLAYED, self::NEXT_UPKEEP, self::NEXT_MAIN, self::NEXT_END, self::OPPONENT_DRAW];
    private const EFFECTS = ['damage', 'draw', 'token', 'treasure', 'self_damage', 'imprint', 'pact', 'mana', 'draw_unless', 'pay_life_draw', 'sacrifice_self', 'tutor',
        'storm', 'counter_spell', 'mobilize', 'sacrifice_tokens', 'pump_untap', 'pay_untap', 'ping_amass', 'cumulative_upkeep'];
    private const FILTERS = ['', self::INSTANT_SORCERY, self::TAPPED, self::SECOND_SPELL, self::NONCREATURE, self::NO_MANA, self::FIRST_NONCREATURE, self::SECOND_DRAW, self::NOT_DRAW_STEP_FIRST];

    public function __construct(
        public readonly string $event,
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly string $spec = '',
        public readonly string $filter = '',
    ) {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Événement de trigger inconnu : $event");
        }
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new \InvalidArgumentException("Effet de trigger inconnu : $effect");
        }
        if (!in_array($filter, self::FILTERS, true) || ($filter === self::INSTANT_SORCERY && $event !== self::CAST)
            || ($filter === self::TAPPED && $event === self::CAST) || ($filter === self::SECOND_SPELL && $event !== self::OPPONENT_CAST)
            || ($filter === self::NONCREATURE && $event !== self::CAST && $event !== self::OPPONENT_CAST) || ($filter === self::NO_MANA && $event !== self::OPPONENT_CAST)
            || ($filter === self::FIRST_NONCREATURE && $event !== self::OPPONENT_CAST)
            || (in_array($filter, [self::SECOND_DRAW, self::NOT_DRAW_STEP_FIRST], true) && $event !== self::OPPONENT_DRAW)) {
            throw new \InvalidArgumentException("Filtre de trigger invalide : $filter ($event)");
        }
        if ($effect === 'token') {
            ActivatedAbility::parseTokenSpec($spec);
        }
        if (in_array($effect, ['pact', 'draw_unless', 'pay_untap', 'cumulative_upkeep'], true) && !preg_match('/^(\{(?:\d+|[WUBRGC])\})+$/', $spec)
            && !($effect === 'draw_unless' && $spec === self::X_POWER)) {
            throw new \InvalidArgumentException("Coût de pacte invalide : $spec");
        }
        if ($effect === 'ping_amass' && !preg_match('/^[A-Z][a-z]+:\d+$/', $spec)) {
            throw new \InvalidArgumentException("Amass invalide : $spec");
        }
        if ($effect === 'tutor' && !preg_match('/^(creature|artifact|enchantment|instant|sorcery|land)?<=\d+$/', $spec)) {
            throw new \InvalidArgumentException("Filtre de tuteur invalide : $spec");
        }
    }

    /** Le sort lancé `$spell` satisfait-il le filtre (`cast`) ? Sans filtre, tout sort convient. */
    public function matches(CardInstance $spell): bool
    {
        return match ($this->filter) {
            self::INSTANT_SORCERY => $spell->hasType(CardInstance::INSTANT) || $spell->hasType(CardInstance::SORCERY),
            self::NONCREATURE, self::FIRST_NONCREATURE => !$spell->isCreature(),
            default => true,
        };
    }

    /** Choisit-elle une carte de la main du contrôleur en étant mise sur la pile (Chrome Mox) ? */
    public function choosesHandCard(): bool
    {
        return $this->effect === 'imprint';
    }

    /** Choisit-elle une carte de la bibliothèque en étant mise sur la pile (tuteur à l'entrée : Ranger-Captain of Eos) ? */
    public function choosesLibraryCard(): bool
    {
        return $this->effect === 'tutor';
    }

    /** La carte de la bibliothèque `$card` convient-elle à ce tuteur (`spec` = `[type]<=valeur de mana`) ? */
    public function tutorMatches(CardInstance $card): bool
    {
        [$type, $max] = explode('<=', $this->spec, 2);

        return ($type === '' || $card->hasType($type)) && $card->manaCost()->manaValue() <= (int) $max;
    }

    /** La capacité cible-t-elle un joueur (à choisir en la mettant sur la pile) ? */
    public function targetsPlayer(): bool
    {
        return $this->effect === 'damage' || $this->effect === 'ping_amass';
    }

    /** Libellé court, ex. `Quand elle entre : piocher 1`, `Quand elle meurt : 2 dégâts`. */
    public function label(): string
    {
        $when = match ($this->event) {
            self::ETB => 'Quand elle entre',
            self::DIES => 'Quand elle meurt',
            self::UPKEEP => 'Au début de votre entretien',
            self::ATTACKS => 'Quand elle attaque',
            self::END_STEP => 'Au début de votre étape de fin',
            self::CAST => match ($this->filter) {
                self::INSTANT_SORCERY => 'Quand vous lancez un éphémère ou un rituel',
                self::NONCREATURE => 'Quand vous lancez un sort non-créature',
                default => 'Quand vous lancez un sort',
            },
            self::OPPONENT_CAST => match ($this->filter) {
                self::SECOND_SPELL => 'Quand un adversaire lance son deuxième sort du tour',
                self::NO_MANA => 'Quand un adversaire lance un sort sans dépenser de mana',
                self::FIRST_NONCREATURE => 'Quand un adversaire lance son premier sort non-créature du tour',
                self::NONCREATURE => 'Quand un adversaire lance un sort non-créature',
                default => 'Quand un adversaire lance un sort',
            },
            self::DISCARD => 'Quand vous défaussez une carte',
            self::DRAW_STEP => 'Au début de votre étape de pioche',
            self::POSTCOMBAT_MAIN => 'Au début de votre phase principale d\'après combat',
            self::LAND_PLAYED => 'Quand vous jouez un autre terrain',
            self::NEXT_UPKEEP => 'Au début de votre prochain entretien',
            self::NEXT_MAIN => 'Au début de votre prochaine phase principale',
            self::NEXT_END => 'Au début de la prochaine étape de fin',
            self::OPPONENT_DRAW => match ($this->filter) {
                self::SECOND_DRAW => 'Quand un adversaire pioche sa deuxième carte du tour',
                self::NOT_DRAW_STEP_FIRST => 'Quand un adversaire pioche une carte (sauf la première de son étape de pioche)',
                default => 'Quand un adversaire pioche une carte',
            },
        } . ($this->filter === self::TAPPED ? ', si elle est engagée' : '');
        $what = match ($this->effect) {
            'damage' => sprintf('%d dégât%s', $this->amount, $this->amount > 1 ? 's' : ''),
            'draw' => sprintf('piocher %d', $this->amount),
            'token' => sprintf('%d jeton%s %s', $this->amount, $this->amount > 1 ? 's' : '', str_replace(':', ' ', $this->spec)),
            'treasure' => sprintf('%d Treasure', $this->amount),
            'self_damage' => sprintf('%d dégât%s à vous', $this->amount, $this->amount > 1 ? 's' : ''),
            'imprint' => 'exiler une carte de votre main (imprint)',
            'pact' => sprintf('payer %s ou perdre la partie', $this->spec),
            'draw_unless' => sprintf('piocher %d sauf s\'il paie %s', $this->amount, $this->spec === self::X_POWER ? '{X} (X = sa force)' : $this->spec),
            'pay_life_draw' => 'payer X PV et piocher X (X = adversaires blessés au combat ce tour)',
            'mana' => sprintf('ajouter %s', $this->amount > 0 ? str_repeat('{C}', $this->amount) : 'rien'),
            'sacrifice_self' => 'la sacrifier',
            'tutor' => sprintf('chercher une carte (%s) et la mettre dans la main', $this->spec),
            'storm' => sprintf('Storm : copier le sort %d fois', $this->amount),
            'counter_spell' => 'contrer ce sort',
            'mobilize' => sprintf('créer %d jetons Warrior 1/1 engagés et attaquants', $this->amount),
            'sacrifice_tokens' => 'sacrifier les jetons Warrior',
            'pump_untap' => sprintf('vos %s gagnent +1/+1 et se dégagent', str_replace('|', ', ', $this->spec)),
            'pay_untap' => sprintf('vous pouvez payer %s pour la dégager', $this->spec),
            'ping_amass' => sprintf('%d dégât%s, puis Amass %s', $this->amount, $this->amount > 1 ? 's' : '', str_replace(':', ' ', $this->spec)),
            'cumulative_upkeep' => sprintf('un marqueur d\'âge, puis payer %s par marqueur ou la sacrifier', $this->spec),
        };

        return "$when : $what";
    }

    public function toArray(): array
    {
        return ['event' => $this->event, 'effect' => $this->effect, 'amount' => $this->amount]
            + ($this->spec === '' ? [] : ['spec' => $this->spec])
            + ($this->filter === '' ? [] : ['filter' => $this->filter]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d['event'], $d['effect'], (int) ($d['amount'] ?? 0), (string) ($d['spec'] ?? ''), (string) ($d['filter'] ?? ''));
    }
}
