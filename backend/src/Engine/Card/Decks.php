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
        // Deux couleurs par joueur, répartition fixe selon l'id (modulo 4) : J0 R+G, J1 U+R, J2 G+U, J3 R+U.
        $profiles = [
            ['colors' => ['R', 'G'], 'bear' => 'G'],
            ['colors' => ['U', 'R'], 'bear' => 'U'],
            ['colors' => ['G', 'U'], 'bear' => 'G'],
            ['colors' => ['R', 'U'], 'bear' => 'U'],
        ];
        $profile = $profiles[$playerId % 4];
        $colors = $profile['colors'];
        $hasRed = in_array('R', $colors, true);
        $hasBlue = in_array('U', $colors, true);
        $basics = ['W' => 'Plains', 'U' => 'Island', 'B' => 'Swamp', 'R' => 'Mountain', 'G' => 'Forest'];

        $n = 0;
        $make = static function (string $name, string $kind, int $cost, ?string $effect = null, int $amount = 0, bool $commander = false, int $power = 0, int $toughness = 0, ?string $manaCost = null, array $produces = []) use ($playerId, &$n): CardInstance {
            return new CardInstance(sprintf('p%d_c%d', $playerId, $n++), $name, $kind, $cost, $effect, $amount, false, $commander, $power, $toughness, 0, 0, $manaCost, $produces);
        };

        $commander = $make("Commandant J$playerId", CardInstance::CREATURE, 3, null, 0, true, 3, 3, '{1}{' . $colors[0] . '}{' . $colors[1] . '}');

        $library = [];
        for ($i = 0; $i < 14; $i++) {
            $color = $colors[$i % 2];
            $library[] = $make($basics[$color], CardInstance::LAND, 0, null, 0, false, 0, 0, null, [$color]);
        }
        if ($hasRed) {
            for ($i = 0; $i < 6; $i++) {
                $library[] = $make('Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, false, 0, 0, '{R}');
            }
            // Boule de feu : X dégâts, X choisi au lancement (montant 0 = résolu à partir de X).
            for ($i = 0; $i < 2; $i++) {
                $library[] = $make('Fireball', CardInstance::INSTANT, 1, 'damage', 0, false, 0, 0, '{X}{R}');
            }
        }
        if ($hasBlue) {
            for ($i = 0; $i < ($hasRed ? 3 : 5); $i++) {
                $library[] = $make('Counterspell', CardInstance::INSTANT, 2, 'counter', 0, false, 0, 0, '{U}{U}');
            }
        }
        for ($i = 0; $i < 2; $i++) {
            $library[] = $make('Hybrid Bear', CardInstance::CREATURE, 2, null, 0, false, 2, 2, '{' . $colors[0] . '/' . $colors[1] . '}{1}');
        }
        $bearName = $profile['bear'] === 'G' ? 'Grizzly Bears' : 'Mist Bears';
        while (count($library) < $librarySize) {
            $library[] = $make($bearName, CardInstance::CREATURE, 2, null, 0, false, 2, 2, '{1}{' . $profile['bear'] . '}');
        }

        return ['commander' => $commander, 'library' => array_slice($library, 0, $librarySize)];
    }
}
