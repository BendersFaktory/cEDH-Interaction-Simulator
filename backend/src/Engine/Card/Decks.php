<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
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
        $make = static function (string $name, string $type, int $cost, ?string $effect = null, int $amount = 0, bool $commander = false, int $power = 0, int $toughness = 0, ?string $manaCost = null, array $produces = [], array $abilities = [], ?string $adds = null, array $triggers = []) use ($playerId, &$n): CardInstance {
            return new CardInstance(sprintf('p%d_c%d', $playerId, $n++), $name, $type, $cost, $effect, $amount, false, $commander, $power, $toughness, 0, 0, $manaCost, $produces, $abilities, $adds, false, $triggers);
        };

        $commander = $make("Commandant J$playerId", CardInstance::CREATURE, 3, null, 0, true, 3, 3, '{1}{' . $colors[0] . '}{' . $colors[1] . '}');

        $library = [];
        for ($i = 0; $i < 12; $i++) {
            $color = $colors[$i % 2];
            $library[] = $make($basics[$color], CardInstance::LAND, 0, null, 0, false, 0, 0, null, [$color]);
        }
        // Terrain dual non-basique (remplace deux terrains de base).
        $duals = ['GR' => 'Taiga', 'RU' => 'Volcanic Island', 'GU' => 'Tropical Island'];
        $pair = [$colors[0], $colors[1]];
        sort($pair);
        $library[] = $make($duals[implode('', $pair)] ?? 'Dual Land', CardInstance::LAND, 0, null, 0, false, 0, 0, null, $colors);
        // Signet : convertisseur `{1}, {T}` -> les deux couleurs du joueur.
        $signets = ['GR' => 'Gruul Signet', 'RU' => 'Izzet Signet', 'GU' => 'Simic Signet'];
        $library[] = $make($signets[implode('', $pair)] ?? 'Signet', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::mana('{' . $colors[0] . '}{' . $colors[1] . '}', '{1}')]);
        // Mind Stone : {T} : {C} ; {1}, {T}, sacrifier : piocher une carte.
        $library[] = $make('Mind Stone', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::mana('{C}'), ActivatedAbility::draw(1, '{1}', true, true)]);
        $library[] = $make('Sol Ring', CardInstance::ARTIFACT, 1, null, 0, false, 0, 0, '{1}', [], [ActivatedAbility::mana('{C}{C}')]);
        $library[] = $make('Prodigal Pinger', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::pinger(1)]);
        if ($hasRed) {
            // Kher Keep : terrain {T} : {C} ; {1}{R}, {T} : créer un jeton Kobold 0/1.
            $library[] = $make('Kher Keep', CardInstance::LAND, 0, null, 0, false, 0, 0, null, [], [ActivatedAbility::token('Kobold:0/1', 1, '{1}{R}')]);
            // Goblin Bombardment : sacrifier une créature : 1 dégât (à un joueur ici).
            $library[] = $make('Goblin Bombardment', CardInstance::ENCHANTMENT, 2, null, 0, false, 0, 0, '{1}{R}', [], [ActivatedAbility::bombardment(1)]);
            for ($i = 0; $i < 5; $i++) {
                $library[] = $make('Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, false, 0, 0, '{R}');
            }
            for ($i = 0; $i < 2; $i++) {
                $library[] = $make('Rite of Flame', CardInstance::INSTANT, 1, 'mana', 0, false, 0, 0, '{R}', [], [], '{R}{R}');
            }
            // Strike It Rich : rituel qui crée un jeton Treasure ({T}, sacrifier : un mana de la couleur de son choix).
            for ($i = 0; $i < 2; $i++) {
                $library[] = $make('Strike It Rich', CardInstance::SORCERY, 1, 'treasure', 0, false, 0, 0, '{R}');
            }
            // Boule de feu : X dégâts, X choisi au lancement (montant 0 = résolu à partir de X).
            for ($i = 0; $i < 2; $i++) {
                $library[] = $make('Fireball', CardInstance::INSTANT, 1, 'damage', 0, false, 0, 0, '{X}{R}');
            }
        }
        if ($hasBlue) {
            // Carte factice de démonstration : pioche activable seulement en rituel.
            $library[] = $make("Tome de l'Archiviste", CardInstance::ARTIFACT, 3, null, 0, false, 0, 0, '{3}', [], [ActivatedAbility::draw(1, '{2}', true, false, true)]);
            for ($i = 0; $i < ($hasRed ? 3 : 5); $i++) {
                $library[] = $make('Counterspell', CardInstance::INSTANT, 2, 'counter', 0, false, 0, 0, '{U}{U}');
            }
        }
        // Créature à mana : soumise au mal d'invocation comme toute créature qui s'engage pour un coût.
        if (in_array('G', $colors, true)) {
            for ($i = 0; $i < 2; $i++) {
                $library[] = $make('Llanowar Elves', CardInstance::CREATURE, 1, null, 0, false, 1, 1, '{G}', [], [ActivatedAbility::mana('{G}')]);
            }
            // Wild Mongrel : défausser une carte : +1/+1 jusqu'à la fin du tour.
            $library[] = $make('Wild Mongrel', CardInstance::CREATURE, 2, null, 0, false, 2, 2, '{1}{G}', [], [ActivatedAbility::pump('+1/+1', null, 1)]);
            // Elvish Visionary : quand elle entre sur le champ de bataille, piocher une carte.
            $library[] = $make('Elvish Visionary', CardInstance::CREATURE, 2, null, 0, false, 1, 1, '{1}{G}', triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)]);
        }
        // Perilous Myr : quand il meurt, 2 dégâts (à un joueur ciblé ici).
        $library[] = $make('Perilous Myr', CardInstance::CREATURE, 2, null, 0, false, 1, 1, '{2}', triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'damage', 2)]);
        // Carte factice de démonstration : au début de votre entretien, piocher une carte.
        $library[] = $make("Idole de l'Érudit", CardInstance::ARTIFACT, 3, null, 0, false, 0, 0, '{3}', triggers: [new TriggeredAbility(TriggeredAbility::UPKEEP, 'draw', 1)]);
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
