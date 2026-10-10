<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Game\GameState;

/** Agent de test : active une fois la première capacité non-mana visant la cible donnée, puis passe toujours. */
final class PingAgent implements PlayerAgent
{
    private bool $done = false;

    public function __construct(private readonly string $target)
    {
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if (!$this->done) {
            foreach ($legal as $action) {
                if ($action instanceof ActivateAbility && !$action->mana && $action->targets === [$this->target]) {
                    $this->done = true;

                    return $action;
                }
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }
}
