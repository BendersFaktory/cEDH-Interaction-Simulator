<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Game\GameState;

/**
 * Agent de test : lance une fois le premier sort de la main qui porte ce nom, puis passe toujours.
 */
final class ScriptedAgent implements PlayerAgent
{
    private bool $done = false;

    /** @param string|null $target null = sort sans cible (ex: un commandant) */
    public function __construct(private readonly string $spellName, private readonly ?string $target)
    {
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if (!$this->done && $state->stack === []) {
            foreach ($legal as $action) {
                if ($action instanceof CastSpell
                    && $state->player($playerId)->findCastable($action->cardId)?->name === $this->spellName
                    && $action->targets === ($this->target === null ? [] : [$this->target])) {
                    $this->done = true;

                    return $action;
                }
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }
}
