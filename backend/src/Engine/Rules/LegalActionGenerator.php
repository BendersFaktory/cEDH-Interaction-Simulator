<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * Calcule les actions légales d'un joueur À CET INSTANT (état du GameState fait foi).
 * Alimente à la fois les agents IA et l'ActionPanel du front.
 * TODO: coûts colorés, X, coûts alternatifs, capacités activées, vitesse « flash », ciblage riche.
 */
final class LegalActionGenerator
{
    /** @return list<Action> */
    public function for(GameState $s, int $playerId): array
    {
        $p = $s->player($playerId);
        $actions = [new PassPriority($playerId)];

        $sorcerySpeed = $playerId === $s->activePlayer && $s->phase->isMain() && $s->stack === [];

        // Terrain : action spéciale, une par tour, timing sorcery.
        if ($sorcerySpeed && $s->landsPlayed < 1) {
            $seen = [];
            foreach ($p->hand as $card) {
                if ($card->isLand() && !isset($seen[$card->name])) {
                    $seen[$card->name] = true;
                    $actions[] = new PlayLand($playerId, $card->id);
                }
            }
        }

        // Sorts (main + zone de commandement), dédoublonnés par (nom, cibles) pour ne pas lister 4 Bolt identiques.
        // Le coût d'un commandant inclut la taxe : +2 par lancement précédent.
        $seen = [];
        foreach ([...$p->hand, ...$p->command] as $card) {
            if ($card->isLand() || $p->castCost($card) > $p->availableMana()) {
                continue;
            }
            if (!$card->isInstantSpeed() && !$sorcerySpeed) {
                continue;
            }
            foreach ($this->targetSets($s, $card) as $targets) {
                $key = $card->name . '|' . implode(',', $targets);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $actions[] = new CastSpell($playerId, $card->id, $targets);
            }
        }

        return $actions;
    }

    /** @return list<list<string>> */
    private function targetSets(GameState $s, CardInstance $card): array
    {
        return match ($card->effect) {
            'damage' => array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds()),
            'counter' => array_map(static fn ($item) => ['stack:' . $item->id], $s->stack),
            default => [[]],
        };
    }
}
