<?php

declare(strict_types=1);

namespace App\Engine\Game;

/** Combat en cours : déclarations (attaquants, bloqueurs) du tour. Entièrement sérialisable. */
final class CombatState
{
    public const ATTACKERS = 'attackers';
    public const BLOCKERS = 'blockers';
    /** Répartition des dégâts par le contrôleur humain de l'attaquant (étape de dégâts, voir DamageAssignment). */
    public const DAMAGE = 'damage';

    /**
     * @param string|null                $declaring 'attackers' | 'blockers' | 'damage' tant qu'une déclaration est en cours
     * @param array<string, int>         $attackers id de l'attaquant => joueur défenseur, dans l'ordre de déclaration
     * @param array<string, list<string>> $blockers id de l'attaquant => ids des bloqueurs, dans l'ordre de déclaration
     * @param list<int>                  $queue     défenseurs qui doivent encore déclarer leurs bloqueurs (ordre du tour)
     * @param array<string, array<string, int>> $assignments id de l'attaquant => répartition choisie pour l'étape de
     *                                          dégâts en cours (vidé une fois les dégâts infligés)
     */
    public function __construct(
        public ?string $declaring = null,
        public array $attackers = [],
        public array $blockers = [],
        public array $queue = [],
        public array $assignments = [],
    ) {
    }

    public function isBlocking(string $cardId): bool
    {
        foreach ($this->blockers as $list) {
            if (in_array($cardId, $list, true)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        $out = [
            'declaring' => $this->declaring,
            'attackers' => $this->attackers,
            'blockers' => $this->blockers,
            'queue' => $this->queue,
        ];
        // Omis quand vide (le cas de toutes les parties d'IA) : les snapshots existants restent identiques.
        if ($this->assignments !== []) {
            $out['assignments'] = $this->assignments;
        }

        return $out;
    }

    public static function fromArray(array $d): self
    {
        $attackers = [];
        foreach ($d['attackers'] ?? [] as $id => $defender) {
            $attackers[(string) $id] = (int) $defender;
        }

        $assignments = [];
        foreach ($d['assignments'] ?? [] as $id => $vector) {
            foreach ($vector as $target => $amount) {
                $assignments[(string) $id][(string) $target] = (int) $amount;
            }
        }

        return new self(
            $d['declaring'] ?? null,
            $attackers,
            $d['blockers'] ?? [],
            array_map('intval', $d['queue'] ?? []),
            $assignments,
        );
    }
}
