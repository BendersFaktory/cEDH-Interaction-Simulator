<?php

declare(strict_types=1);

namespace App\Engine\Game;

/** Combat en cours : déclarations (attaquants, bloqueurs) du tour. Entièrement sérialisable. */
final class CombatState
{
    public const ATTACKERS = 'attackers';
    public const BLOCKERS = 'blockers';

    /**
     * @param string|null                $declaring 'attackers' | 'blockers' tant qu'une déclaration est en cours
     * @param array<string, int>         $attackers id de l'attaquant => joueur défenseur, dans l'ordre de déclaration
     * @param array<string, list<string>> $blockers id de l'attaquant => ids des bloqueurs, dans l'ordre de déclaration
     * @param list<int>                  $queue     défenseurs qui doivent encore déclarer leurs bloqueurs (ordre du tour)
     */
    public function __construct(
        public ?string $declaring = null,
        public array $attackers = [],
        public array $blockers = [],
        public array $queue = [],
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
        return [
            'declaring' => $this->declaring,
            'attackers' => $this->attackers,
            'blockers' => $this->blockers,
            'queue' => $this->queue,
        ];
    }

    public static function fromArray(array $d): self
    {
        $attackers = [];
        foreach ($d['attackers'] ?? [] as $id => $defender) {
            $attackers[(string) $id] = (int) $defender;
        }

        return new self(
            $d['declaring'] ?? null,
            $attackers,
            $d['blockers'] ?? [],
            array_map('intval', $d['queue'] ?? []),
        );
    }
}
