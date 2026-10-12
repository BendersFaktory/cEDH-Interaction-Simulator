<?php

declare(strict_types=1);

namespace App\Engine\Ability;

/**
 * Trigger d'un permanent (objet valeur). `event` : `etb` (la carte entre sur le champ de bataille), `dies`
 * (la créature meurt : champ de bataille -> cimetière, jeton compris) ou `upkeep` (début de l'entretien de son
 * contrôleur). Une fois survenu, le trigger attend dans `GameState::$pendingTriggers` jusqu'à ce qu'un joueur reçoive la
 * priorité ; `TriggerManager` la met alors sur la pile (ordre APNAP).
 *
 * Effets (même vocabulaire que `ActivatedAbility`) : `damage` (`amount` dégâts à un joueur ciblé), `draw` (piocher
 * `amount` cartes) ou `token` (`amount` jetons créature décrits par `spec` = `Nom:F/E`).
 *
 * Forme sérialisée (`toArray`) : `spec` omis s'il est vide.
 */
final class TriggeredAbility
{
    public const ETB = 'etb';
    public const DIES = 'dies';
    public const UPKEEP = 'upkeep';

    private const EVENTS = [self::ETB, self::DIES, self::UPKEEP];
    private const EFFECTS = ['damage', 'draw', 'token'];

    public function __construct(
        public readonly string $event,
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly string $spec = '',
    ) {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Événement de trigger inconnu : $event");
        }
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new \InvalidArgumentException("Effet de trigger inconnu : $effect");
        }
        if ($effect === 'token') {
            ActivatedAbility::parseTokenSpec($spec);
        }
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
            + ($this->spec === '' ? [] : ['spec' => $this->spec]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d['event'], $d['effect'], (int) ($d['amount'] ?? 0), (string) ($d['spec'] ?? ''));
    }
}
