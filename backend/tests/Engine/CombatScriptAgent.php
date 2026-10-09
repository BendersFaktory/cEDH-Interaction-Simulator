<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Game\GameState;

/** Agent de test : attaque le joueur $target avec tout, une seule fois (un seul tour), ou bloque une fois avec la première option. */
final class CombatScriptAgent implements PlayerAgent
{
    private ?int $attackTurn = null;
    private bool $blocked = false;

    public function __construct(private readonly ?int $target = null, private readonly bool $block = false)
    {
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        foreach ($legal as $a) {
            if ($this->target !== null && $a instanceof DeclareAttacker && $a->defender === $this->target
                && ($this->attackTurn === null || $this->attackTurn === $state->turn)) {
                $this->attackTurn = $state->turn;

                return $a;
            }
            if ($this->block && !$this->blocked && $a instanceof DeclareBlocker) {
                $this->blocked = true;

                return $a;
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }
}
