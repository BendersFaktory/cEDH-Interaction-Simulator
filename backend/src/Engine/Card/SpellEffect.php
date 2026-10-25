<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Game\CardInstance;

/**
 * Un effet d'un sort à sa résolution (dans l'ordre de la liste `CardInstance::$effects`, CR 608.2c) :
 * `damage` (`amount`, ou X du coût), `counter`, `mana` (`adds`, rituel), `treasure`, `draw` (`amount` cartes),
 * `exile` (exile le permanent ciblé ; `target` = `creature` ou `permanent`, défaut `permanent` ; `powerLife` : son contrôleur gagne
 * autant de PV que sa force avant l'exil, Swords to Plowshares).
 * Au plus un effet ciblé par carte (`damage`, `counter` ou `exile`) : la carte a une seule cible (voir la case CR 608.2b de la roadmap).
 */
final class SpellEffect
{
    public const KINDS = ['damage', 'counter', 'mana', 'treasure', 'draw', 'exile'];
    public const TARGETED = ['damage', 'counter', 'exile'];
    /** Genres de permanents qu'un effet `exile` peut cibler. */
    public const PERMANENT_TARGETS = ['creature', 'permanent'];

    public function __construct(
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly ?string $adds = null, // mana ajouté par un effet `mana`, ex. `{R}{R}`
        public readonly ?string $target = null, // effet `exile` : `creature` | `permanent` (null = `permanent`)
        public readonly bool $powerLife = false, // effet `exile` : le contrôleur de la cible gagne PV = sa force (Swords to Plowshares)
    ) {
        if (!in_array($effect, self::KINDS, true)) {
            throw new \InvalidArgumentException("Effet de sort inconnu « $effect »");
        }
        if ($target !== null && ($effect !== 'exile' || !in_array($target, self::PERMANENT_TARGETS, true))) {
            throw new \InvalidArgumentException("Cible « $target » invalide pour l'effet « $effect »");
        }
        if ($powerLife && $effect !== 'exile') {
            throw new \InvalidArgumentException("« powerLife » n'a de sens que pour l'effet « exile »");
        }
    }

    public function isTargeted(): bool
    {
        return in_array($this->effect, self::TARGETED, true);
    }

    /** Le permanent `$card` est-il une cible légale de cet effet `exile` (créature seulement si `target` = `creature`) ? */
    public function canTargetPermanent(CardInstance $card): bool
    {
        return $this->target !== 'creature' || $card->isCreature();
    }

    /**
     * Liste d'effets valide : au plus un effet ciblé.
     *
     * @param list<self> $effects
     * @return list<self>
     */
    public static function assertList(array $effects, string $cardName): array
    {
        if (count(array_filter($effects, static fn (self $e) => $e->isTargeted())) > 1) {
            throw new \InvalidArgumentException("$cardName : au plus un effet ciblé par sort");
        }

        return array_values($effects);
    }

    /** Porte-t-il une précision absente des clés historiques (`effect`, `amount`, `adds`) ? Alors `CardInstance::toArray()` sérialise `effects`. */
    public function isPrecise(): bool
    {
        return $this->target !== null || $this->powerLife;
    }

    /** Forme sérialisée : `['effect' => …, 'amount' => …, 'adds' => …, 'target' => …]`, `amount`, `adds` et `target` omis à leur défaut. */
    public function toArray(): array
    {
        return ['effect' => $this->effect] + ($this->amount !== 0 ? ['amount' => $this->amount] : []) + ($this->adds !== null ? ['adds' => $this->adds] : [])
            + ($this->target !== null ? ['target' => $this->target] : []) + ($this->powerLife ? ['powerLife' => true] : []);
    }

    public static function fromArray(array $d): self
    {
        return new self((string) $d['effect'], (int) ($d['amount'] ?? 0), $d['adds'] ?? null, $d['target'] ?? null, (bool) ($d['powerLife'] ?? false));
    }
}
