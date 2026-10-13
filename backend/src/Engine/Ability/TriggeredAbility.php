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
 * `amount` cartes) ou `token` (`amount` jetons créature décrits par `spec` = `Nom:F/E`).
 *
 * Filtre (`filter`, seulement pour `cast`) : `''` = tout sort, `instant_sorcery` = éphémère ou rituel.
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
    public const DISCARD = 'discard';

    /** Filtre de `cast` : le sort lancé est un éphémère ou un rituel. */
    public const INSTANT_SORCERY = 'instant_sorcery';

    private const EVENTS = [self::ETB, self::DIES, self::UPKEEP, self::ATTACKS, self::END_STEP, self::CAST, self::DISCARD];
    private const EFFECTS = ['damage', 'draw', 'token'];
    private const FILTERS = ['', self::INSTANT_SORCERY];

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
        if (!in_array($filter, self::FILTERS, true) || ($filter !== '' && $event !== self::CAST)) {
            throw new \InvalidArgumentException("Filtre de trigger invalide : $filter ($event)");
        }
        if ($effect === 'token') {
            ActivatedAbility::parseTokenSpec($spec);
        }
    }

    /** Le sort lancé `$spell` satisfait-il le filtre (`cast`) ? Sans filtre, tout sort convient. */
    public function matches(CardInstance $spell): bool
    {
        return match ($this->filter) {
            self::INSTANT_SORCERY => $spell->type === CardInstance::INSTANT || $spell->type === CardInstance::SORCERY,
            default => true,
        };
    }

    /** La capacité cible-t-elle un joueur (à choisir en la mettant sur la pile) ? */
    public function targetsPlayer(): bool
    {
        return $this->effect === 'damage';
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
            self::CAST => $this->filter === self::INSTANT_SORCERY ? 'Quand vous lancez un éphémère ou un rituel' : 'Quand vous lancez un sort',
            self::DISCARD => 'Quand vous défaussez une carte',
        };
        $what = match ($this->effect) {
            'damage' => sprintf('%d dégât%s', $this->amount, $this->amount > 1 ? 's' : ''),
            'draw' => sprintf('piocher %d', $this->amount),
            'token' => sprintf('%d jeton%s %s', $this->amount, $this->amount > 1 ? 's' : '', str_replace(':', ' ', $this->spec)),
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
