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
 * Au plus un effet ciblé par carte (`damage`, `counter` ou `exile`) : la carte a une seule cible (voir la case CR 608.2b de la roadmap).
 */
final class SpellEffect
{
    public const KINDS = ['damage', 'counter', 'mana', 'treasure', 'draw', 'exile', 'tutor', 'scry'];
    public const TARGETED = ['damage', 'counter', 'exile', 'tutor'];
    /** Genres de permanents qu'un effet `exile` peut cibler. */
    public const PERMANENT_TARGETS = ['creature', 'permanent'];
    /** Types de sorts qu'un effet `counter` peut viser (`target`, alternatives séparées par `|`) ; null = tout sort (Counterspell). */
    public const COUNTER_TARGETS = [CardInstance::ENCHANTMENT, CardInstance::INSTANT, CardInstance::SORCERY, 'noncreature'];
    /** Mots-clés qu'un jeton créé par un contresort peut recevoir (Swan Song : Bird 2/2 volant). */
    public const TOKEN_KEYWORDS = [CardInstance::FLYING];

    public function __construct(
        public readonly string $effect,
        public readonly int $amount = 0,
        public readonly ?string $adds = null, // mana ajouté par un effet `mana`, ex. `{R}{R}`
        public readonly ?string $target = null, // effet `exile` : `creature` | `permanent` (null = `permanent`)
        public readonly bool $powerLife = false, // effet `exile` : le contrôleur de la cible gagne PV = sa force (Swords to Plowshares)
        public readonly ?string $token = null, // effet `counter` : jeton `Nom:F/E` créé pour le contrôleur du sort contré (Swan Song)
        public readonly array $tokenKeywords = [], // mots-clés du jeton (`TOKEN_KEYWORDS`)
    ) {
        if (!in_array($effect, self::KINDS, true)) {
            throw new \InvalidArgumentException("Effet de sort inconnu « $effect »");
        }
        $validTarget = match ($effect) {
            'exile' => in_array($target, self::PERMANENT_TARGETS, true),
            'counter' => $target !== null && array_diff(explode('|', $target), self::COUNTER_TARGETS) === [],
            default => false,
        };
        if ($target !== null && !$validTarget) {
            throw new \InvalidArgumentException("Cible « $target » invalide pour l'effet « $effect »");
        }
        if ($powerLife && $effect !== 'exile') {
            throw new \InvalidArgumentException("« powerLife » n'a de sens que pour l'effet « exile »");
        }
        if ($token !== null) {
            ActivatedAbility::parseTokenSpec($token); // lève une exception si le jeton est mal décrit
        }
        if (($token === null && $tokenKeywords !== []) || ($token !== null && $effect !== 'counter')
            || array_diff($tokenKeywords, self::TOKEN_KEYWORDS) !== []) {
            throw new \InvalidArgumentException("Jeton « $token » invalide pour l'effet « $effect »");
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

    /** Le sort `$card` (sur la pile) est-il une cible légale de cet effet `counter` (Dispel : instant seulement) ? */
    public function canCounter(CardInstance $card): bool
    {
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
        return $this->target !== null || $this->powerLife || $this->token !== null;
    }

    /** Forme sérialisée : `['effect' => …, 'amount' => …, 'adds' => …, 'target' => …]`, `amount`, `adds` et `target` omis à leur défaut. */
    public function toArray(): array
    {
        return ['effect' => $this->effect] + ($this->amount !== 0 ? ['amount' => $this->amount] : []) + ($this->adds !== null ? ['adds' => $this->adds] : [])
            + ($this->target !== null ? ['target' => $this->target] : []) + ($this->powerLife ? ['powerLife' => true] : [])
            + ($this->token !== null ? ['token' => $this->token] : []) + ($this->tokenKeywords !== [] ? ['tokenKeywords' => $this->tokenKeywords] : []);
    }

    public static function fromArray(array $d): self
    {
        return new self((string) $d['effect'], (int) ($d['amount'] ?? 0), $d['adds'] ?? null, $d['target'] ?? null, (bool) ($d['powerLife'] ?? false),
            $d['token'] ?? null, $d['tokenKeywords'] ?? []);
    }
}
