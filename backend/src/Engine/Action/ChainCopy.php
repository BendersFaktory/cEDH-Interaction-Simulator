<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Chain of Vapor (`GameState::$selection`, kind `chain`) : le contrôleur du permanent renvoyé sacrifie le terrain `landId` et
 * copie le sort, la copie visant `target` (`permanent:<id>`, un permanent non-terrain) ; ou décline (`landId` null). Une seule
 * décision, hors priorité, comme Ponder.
 */
final class ChainCopy implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly ?string $landId = null,
        public readonly ?string $target = null,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('chain:%d:%s', $this->player, $this->landId === null ? 'decline' : $this->landId . '>' . $this->target);
    }

    public function describe(GameState $state): string
    {
        if ($this->landId === null) {
            return 'Ne pas copier le sort';
        }
        $land = $state->player($this->player)->findOnBattlefield($this->landId)?->name ?? $this->landId;
        $id = substr((string) $this->target, strlen('permanent:'));
        $target = $id;
        foreach ($state->players as $p) {
            if (($card = $p->findOnBattlefield($id)) !== null) {
                $target = sprintf('%s (joueur %d)', $card->name, $p->id);
            }
        }

        return sprintf('Sacrifier %s et copier le sort → %s', $land, $target);
    }

    public function toArray(): array
    {
        return ['type' => 'chain', 'player' => $this->player] + ($this->landId === null ? [] : ['landId' => $this->landId, 'target' => $this->target]);
    }
}
