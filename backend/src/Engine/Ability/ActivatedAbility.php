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
 * Effets : `mana` (ajoute au pool : `options` = alternatives, chacune = ce que produit UN engagement), `damage`
 * (`amount` dégâts à un joueur ciblé), `draw` (piocher `amount` cartes), `token` (crée `amount` jetons créature
 * décrits par `spec` = `Nom:F/E`, ex. `Kobold:0/1`) ou `pump` (la source gagne `spec` = `+F/+E` jusqu'à la fin du tour).
 * Coût : `tap` ({T}), `manaCost`, `sacrifice` (sacrifier la source, ex. Treasure), `sacrificeCreature` (sacrifier une
 * créature qu'on contrôle, choisie dans l'action) et/ou `discard` (défausser N cartes choisies dans l'action) ; tout
 * sacrifice passe par `GameState::sacrifice()`. Une capacité de mana à coût de mana (Signet) paie ce coût avec les AUTRES
 * sources ; l'auto-paiement la traite en « convertisseur » (`ManaPayment`). `sorcerySpeed` : activable seulement quand on
 * pourrait lancer un rituel (son tour, phase principale, pile vide).
 *
 * Forme sérialisée (`toArray`) : les options sont des chaînes `{R}{R}` ; seules les lettres WUBRGC sont écrites ; les
 * champs `sacrificeCreature`, `discard`, `sorcerySpeed` et `spec` sont omis à leur valeur par défaut.
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
        public readonly bool $sacrifice = false,
        public readonly bool $sacrificeCreature = false,
        public readonly int $discard = 0,
        public readonly bool $sorcerySpeed = false,
        public readonly string $spec = '',
    ) {
        if ($effect === 'token') {
            $this->tokenSpec();
        }
        if ($effect === 'pump') {
            $this->boost();
        }
    }

    /**
     * `{T}: ajoute …` ; `$spec` = alternatives séparées par `|`, ex. `{C}{C}` ou `{R}|{G}`. `$cost` = coût de mana en plus du
     * `{T}` (Signet : `{1}`).
     */
    public static function mana(string $spec, ?string $cost = null): self
    {
        return new self(true, 'mana', true, $cost === null ? null : ManaCost::parse($cost), array_map(self::parseOption(...), explode('|', $spec)));
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
            $this->sacrifice ? 'sacrifier' : '',
            $this->sacrificeCreature ? 'sacrifier une créature' : '',
            $this->discard > 0 ? "défausser {$this->discard}" : '',
        ], static fn (string $p) => $p !== ''));
        $what = match ($this->effect) {
            'mana' => implode('', array_map(static fn (Color $c) => '{' . $c->symbol() . '}', $this->options[$option] ?? [])),
            'damage' => sprintf('%d dégât%s', $this->amount, $this->amount > 1 ? 's' : ''),
            'draw' => sprintf('piocher %d', $this->amount),
            'token' => sprintf('%d jeton%s %s', $this->amount, $this->amount > 1 ? 's' : '', str_replace(':', ' ', $this->spec)),
            'pump' => $this->spec,
            default => $this->effect,
        };

        return "$cost : $what" . ($this->sorcerySpeed ? ' (rituel)' : '');
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
            'sacrifice' => $this->sacrifice,
        ] + array_filter([
            'sacrificeCreature' => $this->sacrificeCreature,
            'discard' => $this->discard,
            'sorcerySpeed' => $this->sorcerySpeed,
            'spec' => $this->spec,
        ]);
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
            (bool) ($d['sacrifice'] ?? false),
            (bool) ($d['sacrificeCreature'] ?? false),
            (int) ($d['discard'] ?? 0),
            (bool) ($d['sorcerySpeed'] ?? false),
            (string) ($d['spec'] ?? ''),
        );
    }
}
