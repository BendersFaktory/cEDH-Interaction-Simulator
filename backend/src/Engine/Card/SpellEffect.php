<?php

declare(strict_types=1);

namespace App\Engine\Card;

/**
 * Un effet d'un sort à sa résolution (dans l'ordre de la liste `CardInstance::$effects`, CR 608.2c) :
 * `damage` (`amount`, ou X du coût), `counter`, `mana` (`adds`, rituel), `treasure`, `draw` (`amount` cartes).
 * Au plus un effet ciblé par carte (`damage` ou `counter`) : la carte a une seule cible (voir la case CR 608.2b de la roadmap).
 */
final class SpellEffect
{
    public const KINDS = ['damage', 'counter', 'mana', 'treasure', 'draw'];
    public const TARGETED = ['damage', 'counter'];

    public function __construct(
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly ?string $adds = null, // mana ajouté par un effet `mana`, ex. `{R}{R}`
    ) {
        if (!in_array($effect, self::KINDS, true)) {
            throw new \InvalidArgumentException("Effet de sort inconnu « $effect »");
        }
    }

    public function isTargeted(): bool
    {
        return in_array($this->effect, self::TARGETED, true);
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

    /** Forme sérialisée : `['effect' => …, 'amount' => …, 'adds' => …]`, `amount` et `adds` omis à leur défaut. */
    public function toArray(): array
    {
        return ['effect' => $this->effect] + ($this->amount !== 0 ? ['amount' => $this->amount] : []) + ($this->adds !== null ? ['adds' => $this->adds] : []);
    }

    public static function fromArray(array $d): self
    {
        return new self((string) $d['effect'], (int) ($d['amount'] ?? 0), $d['adds'] ?? null);
    }
}
