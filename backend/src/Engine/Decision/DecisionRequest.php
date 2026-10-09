<?php

declare(strict_types=1);

namespace App\Engine\Decision;

use App\Engine\Action\Action;
use App\Engine\Game\GameState;

/** Ce que le moteur renvoie quand il est suspendu en attente d'un joueur humain. */
final class DecisionRequest
{
    /** @param list<Action> $legalActions */
    private function __construct(
        public readonly int $player,
        public readonly array $legalActions,
        public readonly int $eventSeq,
        private readonly array $payload,
    ) {
    }

    /** @param list<Action> $legal */
    public static function from(GameState $s, int $player, array $legal): self
    {
        return new self($player, $legal, $s->eventSeq, [
            'turn' => $s->turn,
            'phase' => $s->phase->value,
            'activePlayer' => $s->activePlayer,
            'priority' => $s->priority->toArray(),
            'stack' => array_map(static fn ($i) => $i->toArray(), $s->stack),
            'actions' => array_map(
                static fn (Action $a) => ['id' => $a->id(), 'label' => $a->describe($s)] + $a->toArray(),
                $legal,
            ),
        ]);
    }

    public function toArray(): array
    {
        return ['player' => $this->player, 'eventSeq' => $this->eventSeq] + $this->payload;
    }
}
