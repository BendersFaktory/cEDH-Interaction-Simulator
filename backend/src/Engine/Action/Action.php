<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/**
 * Une décision qu'un joueur peut prendre quand il a la priorité.
 * L'id est déterministe : le front poste un actionId, le serveur recalcule
 * les actions légales et retrouve l'action correspondante (jamais de confiance au client).
 */
interface Action
{
    public function player(): int;

    public function id(): string;

    public function describe(GameState $state): string;

    public function toArray(): array;
}
