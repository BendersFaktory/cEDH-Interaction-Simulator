<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Game\CardInstance;
use App\Engine\Mana\Color;

/**
 * Decks de démo codés en dur. TODO: DeckLoader branché sur Scryfall (cache en base) + CardDefinition.
 */
final class Decks
{
    /** Taille d'une bibliothèque Commander : 99 cartes + le commandant (CR 903.5a). */
    public const LIBRARY_SIZE = 99;

    /** Terrains de base : seules cartes admises en plusieurs exemplaires (CR 903.5b). */
    public const BASIC_LANDS = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Wastes'];

    /**
     * Règles de construction Commander : 99 cartes en bibliothèque (CR 903.5a), singleton hors terrains de base
     * (CR 903.5b) et identité de couleurs (CR 903.4) : toute carte doit avoir une identité incluse dans celle du commandant.
     *
     * @param array{commander: CardInstance, library: list<CardInstance>} $deck
     * @throws \InvalidArgumentException
     */
    public static function validate(array $deck, int $playerId): void
    {
        if (count($deck['library']) !== self::LIBRARY_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Joueur %d : la bibliothèque compte %d cartes au lieu de %d.', $playerId, count($deck['library']), self::LIBRARY_SIZE,
            ));
        }
        $seen = [$deck['commander']->name => true];
        foreach ($deck['library'] as $card) {
            if (isset($seen[$card->name]) && !in_array($card->name, self::BASIC_LANDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Joueur %d : « %s » est en plusieurs exemplaires (singleton : seuls les terrains de base peuvent l\'être).', $playerId, $card->name,
                ));
            }
            $seen[$card->name] = true;
        }
        $allowed = array_map(static fn (Color $c) => $c->symbol(), $deck['commander']->colorIdentity());
        foreach ($deck['library'] as $card) {
            $outside = array_diff(array_map(static fn (Color $c) => $c->symbol(), $card->colorIdentity()), $allowed);
            if ($outside !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Joueur %d : « %s » (couleurs %s) sort de l\'identité de couleurs du commandant « %s » (%s).',
                    $playerId, $card->name, implode('', $outside), $deck['commander']->name, implode('', $allowed) ?: 'incolore',
                ));
            }
        }
    }

    /**
     * Deck Commander de démonstration : 1 commandant + 99 cartes singleton, complété par des terrains de base.
     * Les cartes réelles n'ont que leurs capacités modélisées (les autres sont ignorées, signalées en commentaire).
     *
     * @return array{commander: CardInstance, library: list<CardInstance>}
     */
    public static function commanderDemo(int $playerId): array
    {
        // Deux couleurs par joueur, répartition fixe selon l'id (modulo 4) : J0 R+G, J1 U+R, J2 G+U, J3 R+U.
        $profiles = [['R', 'G'], ['U', 'R'], ['G', 'U'], ['R', 'U']];
        $colors = $profiles[$playerId % 4];
        [$a, $b] = $colors;
        $hasRed = in_array('R', $colors, true);
        $hasBlue = in_array('U', $colors, true);
        $hasGreen = in_array('G', $colors, true);
        $basics = ['W' => 'Plains', 'U' => 'Island', 'B' => 'Swamp', 'R' => 'Mountain', 'G' => 'Forest'];
        $pair = $colors;
        sort($pair);
        $pair = implode('', $pair);

        $n = 0;
        $make = static function (string $name, string|array $type, int $cost, ?string $effect = null, int $amount = 0, bool $commander = false, int $power = 0, int $toughness = 0, ?string $manaCost = null, array $produces = [], array $abilities = [], ?string $adds = null, array $triggers = [], array $subtypes = [], array $keywords = []) use ($playerId, &$n): CardInstance {
            return new CardInstance(sprintf('p%d_c%d', $playerId, $n++), $name, $type, $cost, $effect, $amount, false, $commander, $power, $toughness, 0, 0, $manaCost, $produces, $abilities, $adds, false, $triggers, $subtypes, keywords: $keywords);
        };
        $land = static fn (string $name, array $produces = [], array $abilities = []): CardInstance => $make($name, CardInstance::LAND, 0, null, 0, false, 0, 0, null, $produces, $abilities);
        $creature = static fn (string $name, int $cost, string $manaCost, int $power, int $toughness, string ...$keywords): CardInstance => $make($name, CardInstance::CREATURE, $cost, null, 0, false, $power, $toughness, $manaCost, keywords: $keywords);
        $rock = static fn (string $name, int $cost, string $mana, ?string $activation = null): CardInstance => $make($name, CardInstance::ARTIFACT, $cost, null, 0, false, 0, 0, '{' . $cost . '}', [], [ActivatedAbility::mana($mana, $activation)]);
        $burn = static fn (string $name, string $type, int $cost, string $manaCost, int $amount): CardInstance => $make($name, $type, $cost, 'damage', $amount, false, 0, 0, $manaCost);
        $counter = static fn (string $name, int $cost, string $manaCost): CardInstance => $make($name, CardInstance::INSTANT, $cost, 'counter', 0, false, 0, 0, $manaCost);
        $ritual = static fn (string $name, int $cost, string $manaCost, string $adds): CardInstance => $make($name, CardInstance::INSTANT, $cost, 'mana', 0, false, 0, 0, $manaCost, [], [], $adds);

        $commander = $make("Commandant J$playerId", CardInstance::CREATURE, 3, null, 0, true, 3, 3, "{1}{{$a}}{{$b}}");

        // Terrains non-basiques (les basiques complètent le deck à la fin). Dégâts des pain lands, entrée engagée et
        // paiement de PV des shock / check lands non modélisés.
        $library = [];
        $library[] = $land(['GR' => 'Taiga', 'RU' => 'Volcanic Island', 'GU' => 'Tropical Island'][$pair], $colors);
        $library[] = $land(['GR' => 'Stomping Ground', 'RU' => 'Steam Vents', 'GU' => 'Breeding Pool'][$pair], $colors);
        $library[] = $land(['GR' => 'Rootbound Crag', 'RU' => 'Sulfur Falls', 'GU' => 'Hinterland Harbor'][$pair], $colors);
        $library[] = $land(['GR' => 'Karplusan Forest', 'RU' => 'Shivan Reef', 'GU' => 'Yavimaya Coast'][$pair], $colors);
        $library[] = $land('Command Tower', $colors);
        // Eldrazi Temple : {T} : {C} ; {T} : {C}{C}, à dépenser pour un Eldrazi incolore.
        $library[] = $land('Eldrazi Temple', [], [ActivatedAbility::mana('{C}{C}@eldrazi')]);

        // Incolores (tous les decks).
        $library[] = $rock('Sol Ring', 1, '{C}{C}');
        $library[] = $rock('Arcane Signet', 2, "{{$a}}|{{$b}}");
        $library[] = $rock('Fellwar Stone', 2, "{{$a}}|{{$b}}"); // couleurs des terrains adverses non modélisées
        $library[] = $rock('Thought Vessel', 2, '{C}'); // pas de taille de main maximale : non modélisé
        $library[] = $rock('Commander\'s Sphere', 3, "{{$a}}|{{$b}}"); // sacrifice pour piocher non modélisé
        $library[] = $rock(['GR' => 'Talisman of Impulse', 'RU' => 'Talisman of Creativity', 'GU' => 'Talisman of Curiosity'][$pair], 2, "{C}|{{$a}}|{{$b}}"); // 1 dégât non modélisé
        // Signet : convertisseur `{1}, {T}` -> les deux couleurs du joueur.
        $library[] = $make(['GR' => 'Gruul Signet', 'RU' => 'Izzet Signet', 'GU' => 'Simic Signet'][$pair], CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::mana("{{$a}}{{$b}}", '{1}')]);
        // Mind Stone : {T} : {C} ; {1}, {T}, sacrifier : piocher une carte.
        $library[] = $make('Mind Stone', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::mana('{C}'), ActivatedAbility::draw(1, '{1}', true, true)]);
        $library[] = $make('Prodigal Pinger', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', [], [ActivatedAbility::pinger(1)]);
        // Eldrazi Mimic : 2/1 Eldrazi incolore ; sa capacité de copie de force/endurance n'est pas modélisée.
        $library[] = $make('Eldrazi Mimic', CardInstance::CREATURE, 2, null, 0, false, 2, 1, '{2}', subtypes: [CardInstance::CREATURE => ['Eldrazi']]);
        // Artefact-créature (cartes multi-types).
        $library[] = $make('Golem de Fer', [CardInstance::ARTIFACT, CardInstance::CREATURE], 3, null, 0, false, 2, 2, '{3}', subtypes: [CardInstance::CREATURE => ['Golem']]);
        $library[] = $make('Alpha Myr', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, null, 0, false, 2, 1, '{2}');
        $library[] = $make('Omega Myr', [CardInstance::ARTIFACT, CardInstance::CREATURE], 3, null, 0, false, 1, 2, '{3}');
        $library[] = $make('Juggernaut', [CardInstance::ARTIFACT, CardInstance::CREATURE], 4, null, 0, false, 5, 3, '{4}'); // attaque obligatoire et blocage interdit par les murs non modélisés
        // Perilous Myr : quand il meurt, 2 dégâts (à un joueur ciblé ici).
        $library[] = $make('Perilous Myr', CardInstance::CREATURE, 2, null, 0, false, 1, 1, '{2}', triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'damage', 2)]);
        // Cartes factices de démonstration : pioche à l'entretien, triggers d'attaque, de fin de tour et de défausse.
        $library[] = $make("Idole de l'Érudit", CardInstance::ARTIFACT, 3, null, 0, false, 0, 0, '{3}', triggers: [new TriggeredAbility(TriggeredAbility::UPKEEP, 'draw', 1)]);
        $library[] = $make('Pillard des Cendres', CardInstance::CREATURE, 3, null, 0, false, 2, 2, '{3}', triggers: [new TriggeredAbility(TriggeredAbility::ATTACKS, 'damage', 1)]);
        $library[] = $make('Lanterne du Crépuscule', CardInstance::ARTIFACT, 3, null, 0, false, 0, 0, '{3}', triggers: [new TriggeredAbility(TriggeredAbility::END_STEP, 'token', 1, 'Spirit:1/1')]);
        $library[] = $make('Cendrier des Regrets', CardInstance::ARTIFACT, 2, null, 0, false, 0, 0, '{2}', triggers: [new TriggeredAbility(TriggeredAbility::DISCARD, 'damage', 1)]);
        // Carte factice à coût hybride.
        $library[] = $make('Hybrid Bear', CardInstance::CREATURE, 2, null, 0, false, 2, 2, "{{$a}/{$b}}{1}");

        if ($hasRed) {
            // Kher Keep : terrain {T} : {C} ; {1}{R}, {T} : créer un jeton Kobold 0/1.
            $library[] = $land('Kher Keep', [], [ActivatedAbility::token('Kobold:0/1', 1, '{1}{R}')]);
            // Goblin Bombardment : sacrifier une créature : 1 dégât (à un joueur ici).
            $library[] = $make('Goblin Bombardment', CardInstance::ENCHANTMENT, 2, null, 0, false, 0, 0, '{1}{R}', [], [ActivatedAbility::bombardment(1)]);
            // Young Pyromancer : quand vous lancez un éphémère ou un rituel, créer un jeton Elemental 1/1.
            $library[] = $make('Young Pyromancer', CardInstance::CREATURE, 2, null, 0, false, 2, 1, '{1}{R}', triggers: [new TriggeredAbility(TriggeredAbility::CAST, 'token', 1, 'Elemental:1/1', TriggeredAbility::INSTANT_SORCERY)]);
            $library[] = $burn('Lightning Bolt', CardInstance::INSTANT, 1, '{R}', 3);
            $library[] = $burn('Chain Lightning', CardInstance::SORCERY, 1, '{R}', 3); // copie non modélisée
            $library[] = $burn('Shock', CardInstance::INSTANT, 1, '{R}', 2);
            $library[] = $burn('Lightning Strike', CardInstance::INSTANT, 2, '{1}{R}', 3);
            $library[] = $burn('Incinerate', CardInstance::INSTANT, 2, '{1}{R}', 3); // pas de régénération : non modélisé
            // Boule de feu, Banefire : X dégâts, X choisi au lancement (montant 0 = résolu à partir de X).
            $library[] = $burn('Fireball', CardInstance::INSTANT, 1, '{X}{R}', 0);
            $library[] = $burn('Banefire', CardInstance::SORCERY, 1, '{X}{R}', 0); // « ne peut être contrecarré » pour X ≥ 5 non modélisé
            $library[] = $ritual('Rite of Flame', 1, '{R}', '{R}{R}'); // bonus par Rite au cimetière non modélisé
            $library[] = $ritual('Desperate Ritual', 2, '{1}{R}', '{R}{R}{R}');
            $library[] = $ritual('Seething Song', 3, '{2}{R}', '{R}{R}{R}{R}{R}');
            // Strike It Rich : rituel qui crée un jeton Treasure ({T}, sacrifier : un mana de la couleur de son choix).
            $library[] = $make('Strike It Rich', CardInstance::SORCERY, 1, 'treasure', 0, false, 0, 0, '{R}');
            $library[] = $make('Iron Myr', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, null, 0, false, 1, 1, '{2}', [], [ActivatedAbility::mana('{R}')]);
            $library[] = $creature('Goblin Guide', 1, '{R}', 2, 2, CardInstance::HASTE);
            $library[] = $creature('Raging Goblin', 1, '{R}', 1, 1, CardInstance::HASTE);
            $library[] = $creature('Monastery Swiftspear', 1, '{R}', 1, 2, CardInstance::HASTE); // prouesse non modélisée
            $library[] = $creature('Gray Ogre', 3, '{2}{R}', 2, 2);
            $library[] = $creature('Boggart Brute', 3, '{2}{R}', 3, 2, CardInstance::MENACE);
            $library[] = $creature('Wall of Stone', 3, '{1}{R}{R}', 0, 8, CardInstance::DEFENDER);
            $library[] = $creature('Hill Giant', 4, '{3}{R}', 3, 3);
            $library[] = $creature('Furnace Whelp', 4, '{2}{R}{R}', 2, 2, CardInstance::FLYING); // pompe {R} non modélisée
        }
        if ($hasBlue) {
            // Carte factice de démonstration : pioche activable seulement en rituel.
            $library[] = $make("Tome de l'Archiviste", CardInstance::ARTIFACT, 3, null, 0, false, 0, 0, '{3}', [], [ActivatedAbility::draw(1, '{2}', true, false, true)]);
            // Contresorts : effets annexes (mana, pioche, exil, regard) non modélisés.
            $library[] = $counter('Counterspell', 2, '{U}{U}');
            $library[] = $counter('Mana Drain', 2, '{U}{U}');
            $library[] = $counter('Arcane Denial', 2, '{1}{U}');
            $library[] = $counter('Cancel', 3, '{1}{U}{U}');
            $library[] = $counter('Dissipate', 3, '{1}{U}{U}');
            $library[] = $counter('Dissolve', 3, '{1}{U}{U}');
            $library[] = $make('Silver Myr', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, null, 0, false, 1, 1, '{2}', [], [ActivatedAbility::mana('{U}')]);
            // Prodigal Sorcerer : {T} : 1 dégât (à un joueur ici).
            $library[] = $make('Prodigal Sorcerer', CardInstance::CREATURE, 3, null, 0, false, 1, 1, '{2}{U}', [], [ActivatedAbility::pinger(1)]);
            $library[] = $creature('Merfolk of the Pearl Trident', 1, '{U}', 1, 1);
            $library[] = $creature('Cloud Sprite', 1, '{U}', 1, 1, CardInstance::FLYING); // ne bloque que les volants : non modélisé
            $library[] = $creature('Storm Crow', 2, '{1}{U}', 1, 2, CardInstance::FLYING);
            $library[] = $creature('Coral Merfolk', 2, '{1}{U}', 2, 1);
            $library[] = $creature('Vodalian Soldiers', 2, '{1}{U}', 1, 2);
            $library[] = $creature('Wind Drake', 3, '{2}{U}', 2, 2, CardInstance::FLYING);
            $library[] = $creature('Horned Turtle', 3, '{2}{U}', 1, 4);
            $library[] = $creature('Snapping Drake', 4, '{3}{U}', 3, 2, CardInstance::FLYING);
            $library[] = $creature('Phantom Monster', 4, '{3}{U}', 3, 3, CardInstance::FLYING);
            $library[] = $creature('Giant Octopus', 4, '{3}{U}', 3, 3);
            $library[] = $creature('Air Elemental', 5, '{3}{U}{U}', 4, 4, CardInstance::FLYING);
            $library[] = $creature('Mahamoti Djinn', 6, '{4}{U}{U}', 5, 6, CardInstance::FLYING);
        }
        if ($hasGreen) {
            // Créatures à mana : soumises au mal d'invocation comme toute créature qui s'engage pour un coût.
            foreach (['Llanowar Elves', 'Fyndhorn Elves', 'Elvish Mystic'] as $elf) {
                $library[] = $make($elf, CardInstance::CREATURE, 1, null, 0, false, 1, 1, '{G}', [], [ActivatedAbility::mana('{G}')]);
            }
            $library[] = $make('Boreal Druid', CardInstance::CREATURE, 1, null, 0, false, 1, 1, '{G}', [], [ActivatedAbility::mana('{C}')]);
            $library[] = $make('Copper Myr', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, null, 0, false, 1, 1, '{2}', [], [ActivatedAbility::mana('{G}')]);
            // Wild Mongrel : défausser une carte : +1/+1 jusqu'à la fin du tour (changement de couleur non modélisé).
            $library[] = $make('Wild Mongrel', CardInstance::CREATURE, 2, null, 0, false, 2, 2, '{1}{G}', [], [ActivatedAbility::pump('+1/+1', null, 1)]);
            // Entrée sur le champ de bataille : piocher une carte.
            $library[] = $make('Elvish Visionary', CardInstance::CREATURE, 2, null, 0, false, 1, 1, '{1}{G}', triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)]);
            $library[] = $make('Wall of Blossoms', CardInstance::CREATURE, 2, null, 0, false, 0, 4, '{1}{G}', triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)], keywords: [CardInstance::DEFENDER]);
            $library[] = $make('Llanowar Visionary', CardInstance::CREATURE, 3, null, 0, false, 2, 2, '{2}{G}', [], [ActivatedAbility::mana('{G}')], triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'draw', 1)]);
            $library[] = $creature('Grizzly Bears', 2, '{1}{G}', 2, 2);
            $library[] = $creature('Runeclaw Bear', 2, '{1}{G}', 2, 2);
            $library[] = $creature('Balduvian Bears', 2, '{1}{G}', 2, 2);
            $library[] = $creature('Kalonian Tusker', 2, '{G}{G}', 3, 3);
            $library[] = $creature('Deadly Recluse', 2, '{1}{G}', 1, 2, CardInstance::REACH, CardInstance::DEATHTOUCH);
            $library[] = $creature('Centaur Courser', 3, '{2}{G}', 3, 3);
            $library[] = $creature('Trained Armodon', 3, '{1}{G}{G}', 3, 3);
            $library[] = $creature('Leatherback Baloth', 3, '{G}{G}{G}', 4, 5);
            $library[] = $creature('Giant Spider', 4, '{3}{G}', 2, 4, CardInstance::REACH);
            $library[] = $creature('Craw Wurm', 6, '{4}{G}{G}', 6, 4);
            $library[] = $creature('Yavimaya Wurm', 6, '{4}{G}{G}', 6, 4, CardInstance::TRAMPLE);
            $library[] = $creature('Colossal Dreadmaw', 6, '{4}{G}{G}', 6, 6, CardInstance::TRAMPLE);
        }

        if (count($library) > self::LIBRARY_SIZE) {
            throw new \LogicException(sprintf('Deck de démo J%d : %d cartes hors terrains de base, plus que %d.', $playerId, count($library), self::LIBRARY_SIZE));
        }
        // Terrains de base en alternance des deux couleurs jusqu'à 99 cartes.
        for ($i = 0; count($library) < self::LIBRARY_SIZE; $i++) {
            $color = $colors[$i % 2];
            $library[] = $land($basics[$color], [$color]);
        }

        return ['commander' => $commander, 'library' => $library];
    }
}
