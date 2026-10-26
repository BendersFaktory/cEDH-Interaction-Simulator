<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Action\Action;
use App\Engine\Action\ArrangeTop;
use App\Engine\Action\BottomCard;
use App\Engine\Action\ChainCopy;
use App\Engine\Action\Discard;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\KeepHand;
use App\Engine\Action\KeepLegend;
use App\Engine\Action\ScryArrange;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PayCost;
use App\Engine\Action\PutBack;
use App\Engine\Action\PutTrigger;
use App\Engine\Game\GameState;

/** Passe toujours (ne déclare ni attaquant ni bloqueur). La décision est quand même prise et journalisée (PriorityPassed). */
final class AutoPassAgent implements PlayerAgent
{
    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        return self::pass($legal, $playerId);
    }

    /** @param list<Action> $legal */
    public static function pass(array $legal, int $playerId): Action
    {
        foreach ($legal as $action) {
            if ($action instanceof PassPriority) {
                return $action;
            }
        }

        // Pendant une déclaration (attaquants/bloqueurs), « passer » = ne rien déclarer.
        foreach ($legal as $action) {
            if ($action instanceof FinishDeclaration) {
                return $action;
            }
        }

        // Défausse obligatoire / trigger à poser / légende à garder / avant-partie (garder la main, puis la première carte au fond) /
        // paiement demandé à la résolution (payer s'il est possible) / sélection de cartes (Brainstorm : la première carte de la
        // main ; Ponder : l'ordre actuel) : le premier choix proposé (déterministe). Jamais de mulligan.
        foreach ($legal as $action) {
            if ($action instanceof Discard || $action instanceof PutTrigger || $action instanceof KeepLegend || $action instanceof KeepHand || $action instanceof BottomCard || $action instanceof ScryArrange || $action instanceof PayCost
                || $action instanceof PutBack || $action instanceof ArrangeTop || $action instanceof ChainCopy) {
                return $action;
            }
        }

        return new PassPriority($playerId);
    }
}
