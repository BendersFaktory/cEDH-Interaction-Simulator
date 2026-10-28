<?php

declare(strict_types=1);

namespace App\Engine\Card;

/**
 * Effet de remplacement (CR 614) : « si … devait …, à la place … ». L'événement remplacé (`event`) ne se produit pas ; `instead`
 * dit ce qui se passe à la place, et `otherwise` ce qui arrive si le remplacement optionnel n'est pas accompli.
 *
 * Seuls les remplacements « de soi » (CR 614.15, portés par la carte qu'ils modifient : `CardInstance::$replacements`) existent
 * pour l'instant, lus par `GameState::replacementFor()`. Mox Diamond : `enters` / `discard_land` / `graveyard` (« If this
 * artifact would enter, you may discard a land card instead. If you do, put this artifact onto the battlefield. If you don't,
 * put it into its owner's graveyard. »). Le choix se fait au milieu de la résolution (`GameState::$selection`, kind `replace`,
 * décision `ReplaceChoice`), comme Chain of Vapor. Mockingbird : `enters` / `copy_creature` / `enter` (« You may have this
 * creature enter as a copy of any creature on the battlefield with mana value less than or equal to the amount of mana spent
 * to cast this creature, except it's a Bird in addition to its other types and it has flying. » ; sinon elle arrive telle quelle).
 */
final class ReplacementEffect
{
    /** Événement remplacé : arriver sur le champ de bataille. */
    public const ENTERS = 'enters';
    public const EVENTS = [self::ENTERS];

    /** À la place : défausser une carte de terrain (optionnel). */
    public const DISCARD_LAND = 'discard_land';
    /** À la place : arriver comme une copie d'une créature (optionnel ; Bird en plus et vol). */
    public const COPY_CREATURE = 'copy_creature';
    public const INSTEAD = [self::DISCARD_LAND, self::COPY_CREATURE];

    /** Sinon : au cimetière de son propriétaire. */
    public const GRAVEYARD = 'graveyard';
    /** Sinon : arriver telle quelle. */
    public const ENTER = 'enter';

    public function __construct(
        public readonly string $event,
        public readonly string $instead,
        public readonly string $otherwise = self::GRAVEYARD,
    ) {
        if (!in_array($event, self::EVENTS, true) || !in_array($instead, self::INSTEAD, true) || !in_array($otherwise, [self::GRAVEYARD, self::ENTER], true)) {
            throw new \InvalidArgumentException("Effet de remplacement inconnu : $event / $instead / $otherwise");
        }
    }

    /** @return array{event: string, instead: string, otherwise: string} */
    public function toArray(): array
    {
        return ['event' => $this->event, 'instead' => $this->instead, 'otherwise' => $this->otherwise];
    }

    /** @param array{event: string, instead: string, otherwise?: string} $d */
    public static function fromArray(array $d): self
    {
        return new self($d['event'], $d['instead'], $d['otherwise'] ?? self::GRAVEYARD);
    }
}
