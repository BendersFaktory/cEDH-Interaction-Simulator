<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Game\CardInstance;

/**
 * Decks de démo codés en dur. TODO: DeckLoader branché sur Scryfall (cache en base) + CardDefinition.
 */
final class Decks
{
    /**
     * Mini-Commander de démonstration : 1 commandant + 39 cartes (un vrai deck en compte 99).
     * Volontairement court pour garder des parties et des snapshots légers pendant le développement.
     *
     * @return array{commander: CardInstance, library: list<CardInstance>}
     */
    public static function commanderDemo(int $playerId, int $librarySize = 39): array
    {
        $n = 0;
        $make = static function (string $name, string $kind, int $cost, ?string $effect = null, int $amount = 0, bool $commander = false, int $power = 0, int $toughness = 0) use ($playerId, &$n): CardInstance {
            return new CardInstance(sprintf('p%d_c%d', $playerId, $n++), $name, $kind, $cost, $effect, $amount, false, $commander, $power, $toughness);
        };

        $commander = $make("Commandant J$playerId", CardInstance::CREATURE, 3, null, 0, true, 3, 3);

        $library = [];
        for ($i = 0; $i < 14; $i++) {
            $library[] = $make('Land', CardInstance::LAND, 0);
        }
        for ($i = 0; $i < 6; $i++) {
            $library[] = $make('Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3);
        }
        for ($i = 0; $i < 3; $i++) {
            $library[] = $make('Counterspell', CardInstance::INSTANT, 2, 'counter');
        }
        while (count($library) < $librarySize) {
            $library[] = $make('Grizzly Bears', CardInstance::CREATURE, 2, null, 0, false, 2, 2);
        }

        return ['commander' => $commander, 'library' => array_slice($library, 0, $librarySize)];
    }
}
