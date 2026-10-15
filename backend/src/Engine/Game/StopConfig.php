<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * Stops de l'auto-pass d'un joueur (`human:auto`) : quand l'agent rend la main à l'humain. Valeur immuable, stockée
 * dans `GameState::$stops` (l'agent reste sans état).
 *
 * Sémantique (voir `HumanAgent::decide`) :
 * - Sans config (`null`) : comportement historique, l'agent s'arrête dès qu'une action utile existe. Une config égale
 *   à `all()` lui est strictement équivalente ; `GameState::setStops` la normalise donc en `null`.
 * - Ronde de priorité (« passer » est légal) : arrêt si une action utile existe (autre que passer ou une capacité de
 *   mana) ET (l'étape courante est dans `$steps` OU (`$onOpponentSpell` et la pile contient un objet contrôlé par un
 *   adversaire)). Sinon, passe.
 * - Déclaration d'attaquants / de bloqueurs : arrêt seulement si `$onDeclareAttackers` / `$onDeclareBlockers` ; sinon
 *   l'agent termine la déclaration sans rien déclarer (n'attaque pas / ne bloque pas).
 * - Décisions obligatoires (défausse, ordre ou cible des triggers) : arrêt toujours, quelle que soit la config.
 *
 * JSON (`toArray`) : `{"steps": ["upkeep", "main1", ...], "onOpponentSpell": bool, "onDeclareAttackers": bool,
 * "onDeclareBlockers": bool}` ; `steps` contient des valeurs de `Phase`, dans l'ordre du tour, sans doublon.
 */
final class StopConfig
{
    /** @var list<Phase> étapes cochées, dans l'ordre du tour */
    public readonly array $steps;

    /** @param list<Phase> $steps */
    public function __construct(
        array $steps = [],
        public readonly bool $onOpponentSpell = false,
        public readonly bool $onDeclareAttackers = false,
        public readonly bool $onDeclareBlockers = false,
    ) {
        $this->steps = array_values(array_filter(Phase::cases(), static fn (Phase $p) => in_array($p, $steps, true)));
    }

    /** Équivalent du comportement par défaut : toutes les étapes, toutes les options. */
    public static function all(): self
    {
        return new self(Phase::cases(), true, true, true);
    }

    public function isDefault(): bool
    {
        return $this->onOpponentSpell && $this->onDeclareAttackers && $this->onDeclareBlockers
            && count($this->steps) === count(Phase::cases());
    }

    public function stopsAt(Phase $phase): bool
    {
        return in_array($phase, $this->steps, true);
    }

    public function toArray(): array
    {
        return [
            'steps' => array_map(static fn (Phase $p) => $p->value, $this->steps),
            'onOpponentSpell' => $this->onOpponentSpell,
            'onDeclareAttackers' => $this->onDeclareAttackers,
            'onDeclareBlockers' => $this->onDeclareBlockers,
        ];
    }

    /** @throws \ValueError si une étape est inconnue */
    public static function fromArray(array $d): self
    {
        return new self(
            array_map(static fn ($v) => Phase::from((string) $v), $d['steps'] ?? []),
            (bool) ($d['onOpponentSpell'] ?? false),
            (bool) ($d['onDeclareAttackers'] ?? false),
            (bool) ($d['onDeclareBlockers'] ?? false),
        );
    }
}
