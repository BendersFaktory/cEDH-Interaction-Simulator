<?php

declare(strict_types=1);

namespace App\Engine\Ability;

use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;

/**
 * Capacité activée d'un permanent (objet valeur). Toutes les capacités activées partagent ce modèle ;
 * `isManaAbility` décide du traitement : vrai = résolue immédiatement à l'activation (règle 605, jamais sur la pile),
 * faux = mise sur la pile comme un sort.
 *
 * Effets : `mana` (ajoute au pool : `options` = alternatives, chacune = ce que produit UN engagement) ou `damage`
 * (`amount` dégâts à un joueur ciblé).
 * Coût : `tap` ({T}) et/ou `manaCost`. Une capacité de mana à coût de mana (Signet) paie ce coût avec les AUTRES sources ;
 * l'auto-paiement la traite en « convertisseur » (`ManaPayment`).
 *
 * Forme sérialisée (`toArray`) : les options sont des chaînes `{R}{R}` ; seules les lettres WUBRGC sont écrites.
 */
final class ActivatedAbility
{
    /**
     * @param list<list<Color>> $options alternatives de mana produit (effet `mana`)
     */
    public function __construct(
        public readonly bool $isManaAbility,
        public readonly string $effect = 'mana',
        public readonly bool $tap = true,
        public readonly ?ManaCost $manaCost = null,
        public readonly array $options = [],
        public readonly int $amount = 0,
    ) {
    }

    /**
     * `{T}: ajoute …` ; `$spec` = alternatives séparées par `|`, ex. `{C}{C}` ou `{R}|{G}`. `$cost` = coût de mana en plus du
     * `{T}` (Signet : `{1}`).
     */
    public static function mana(string $spec, ?string $cost = null): self
    {
        return new self(true, 'mana', true, $cost === null ? null : ManaCost::parse($cost), array_map(self::parseOption(...), explode('|', $spec)));
    }

    /** `{T}: $amount dégâts à un joueur` (capacité non-mana, passe par la pile). */
    public static function pinger(int $amount = 1): self
    {
        return new self(false, 'damage', true, null, [], $amount);
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

    /** Libellé court, ex. `{T} : {R}`, `{1}, {T} : {U}{R}` ou `{T} : 1 dégât`. */
    public function label(int $option = 0): string
    {
        $cost = implode(', ', array_filter([$this->manaCost !== null ? (string) $this->manaCost : '', $this->tap ? '{T}' : ''], static fn (string $p) => $p !== ''));
        if ($this->effect === 'mana') {
            $gain = implode('', array_map(static fn (Color $c) => '{' . $c->symbol() . '}', $this->options[$option] ?? []));

            return "$cost : $gain";
        }

        return sprintf('%s : %d dégât%s', $cost, $this->amount, $this->amount > 1 ? 's' : '');
    }

    public function toArray(): array
    {
        return [
            'isManaAbility' => $this->isManaAbility,
            'effect' => $this->effect,
            'tap' => $this->tap,
            'manaCost' => $this->manaCost === null ? null : (string) $this->manaCost,
            'options' => array_map(
                static fn (array $o) => implode('', array_map(static fn (Color $c) => '{' . $c->symbol() . '}', $o)),
                $this->options,
            ),
            'amount' => $this->amount,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            (bool) $d['isManaAbility'],
            $d['effect'] ?? 'mana',
            (bool) ($d['tap'] ?? true),
            isset($d['manaCost']) ? ManaCost::parse($d['manaCost']) : null,
            array_map(self::parseOption(...), $d['options'] ?? []),
            (int) ($d['amount'] ?? 0),
        );
    }
}
