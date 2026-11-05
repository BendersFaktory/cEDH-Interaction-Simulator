<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Game\CardInstance;

/**
 * Sous-types admis par type de carte (règle 205.3 : un sous-type est rattaché à son type ; un artefact ne peut pas être
 * Eldrazi, une créature ne peut pas être Aura). Catalogue : types de créature de la CR 205.3m, et les sous-types d'artefact, d'enchantement, de terrain et de sort courants.
 */
final class Subtypes
{
    /** @var array<string, list<string>> */
    private const BY_TYPE = [
        CardInstance::CREATURE => self::CREATURE_TYPES,
        CardInstance::ARTIFACT => ['Attraction', 'Blood', 'Bobblehead', 'Clue', 'Contraption', 'Equipment', 'Food', 'Fortification', 'Gold', 'Incubator', 'Junk', 'Map', 'Powerstone', 'Treasure', 'Vehicle'],
        CardInstance::ENCHANTMENT => ['Aura', 'Background', 'Cartouche', 'Case', 'Class', 'Curse', 'Role', 'Room', 'Rune', 'Saga', 'Shard', 'Shrine'],
        CardInstance::LAND => ['Cave', 'Desert', 'Forest', 'Gate', 'Island', 'Lair', 'Locus', 'Mine', 'Mountain', 'Plains', 'Power-Plant', 'Sphere', 'Swamp', 'Tower', 'Town', 'Urza\'s'],
        CardInstance::INSTANT => ['Adventure', 'Arcane', 'Lesson', 'Omen', 'Trap'],
        CardInstance::SORCERY => ['Adventure', 'Arcane', 'Lesson', 'Omen', 'Trap'],
    ];

    /** Types de créature (CR 205.3m) ; une créature artefact garde ses types de créature sous la clé `creature`. */
    private const CREATURE_TYPES = [
        'Advisor', 'Aetherborn', 'Alien', 'Ally', 'Angel', 'Antelope', 'Ape', 'Archer', 'Archon', 'Armadillo', 'Army',
        'Artificer', 'Assassin', 'Assembly-Worker', 'Astartes', 'Atog', 'Aurochs', 'Avatar', 'Azra', 'Badger',
        'Balloon', 'Barbarian', 'Bard', 'Basilisk', 'Bat', 'Bear', 'Beast', 'Beaver', 'Beeble', 'Beholder',
        'Berserker', 'Bird', 'Bison', 'Blinkmoth', 'Boar', 'Bringer', 'Brushwagg', 'Camarid', 'Camel', 'Capybara',
        'Caribou', 'Carrier', 'Cat', 'Centaur', 'Child', 'Chimera', 'Citizen', 'Cleric', 'Clown', 'Cockatrice',
        'Construct', 'Coward', 'Coyote', 'Crab', 'Crocodile', 'Ctan', 'Custodes', 'Cyberman', 'Cyclops', 'Dalek',
        'Dauthi', 'Demigod', 'Demon', 'Deserter', 'Detective', 'Devil', 'Dinosaur', 'Djinn', 'Doctor', 'Dog', 'Dragon',
        'Drake', 'Dreadnought', 'Drone', 'Druid', 'Dryad', 'Dwarf', 'Efreet', 'Egg', 'Elder', 'Eldrazi', 'Elemental',
        'Elephant', 'Elf', 'Elk', 'Employee', 'Eye', 'Faerie', 'Ferret', 'Fish', 'Flagbearer', 'Fox', 'Fractal',
        'Frog', 'Fungus', 'Gamer', 'Gargoyle', 'Germ', 'Giant', 'Gith', 'Glimmer', 'Gnoll', 'Gnome', 'Goat', 'Goblin',
        'God', 'Golem', 'Gorgon', 'Graveborn', 'Gremlin', 'Griffin', 'Guest', 'Hag', 'Halfling', 'Hamster', 'Harpy',
        'Hellion', 'Hippo', 'Hippogriff', 'Homarid', 'Homunculus', 'Horror', 'Horse', 'Human', 'Hydra', 'Hyena',
        'Illusion', 'Imp', 'Incarnation', 'Inkling', 'Inquisitor', 'Insect', 'Jackal', 'Jellyfish', 'Juggernaut',
        'Kavu', 'Kirin', 'Kithkin', 'Knight', 'Kobold', 'Kor', 'Kraken', 'Llama', 'Lamia', 'Lammasu', 'Leech',
        'Leviathan', 'Lhurgoyf', 'Licid', 'Lizard', 'Lobster', 'Manticore', 'Masticore', 'Mercenary', 'Merfolk',
        'Metathran', 'Minion', 'Minotaur', 'Mite', 'Mole', 'Monger', 'Mongoose', 'Monk', 'Monkey', 'Moonfolk', 'Mount',
        'Mouse', 'Mutant', 'Myr', 'Mystic', 'Nautilus', 'Necron', 'Nephilim', 'Nightmare', 'Nightstalker', 'Ninja',
        'Noble', 'Noggle', 'Nomad', 'Nymph', 'Octopus', 'Ogre', 'Ooze', 'Orb', 'Orc', 'Orgg', 'Otter', 'Ouphe', 'Ox',
        'Oyster', 'Pangolin', 'Peasant', 'Pegasus', 'Pentavite', 'Performer', 'Pest', 'Phelddagrif', 'Phoenix',
        'Phyrexian', 'Pilot', 'Pincher', 'Pirate', 'Plant', 'Porcupine', 'Possum', 'Praetor', 'Primarch', 'Prism',
        'Processor', 'Rabbit', 'Raccoon', 'Ranger', 'Rat', 'Rebel', 'Reflection', 'Rhino', 'Rigger', 'Robot', 'Rogue',
        'Sable', 'Salamander', 'Samurai', 'Sand', 'Saproling', 'Satyr', 'Scarecrow', 'Scientist', 'Scion', 'Scorpion',
        'Scout', 'Sculpture', 'Serf', 'Serpent', 'Servo', 'Shade', 'Shaman', 'Shapeshifter', 'Shark', 'Sheep', 'Siren',
        'Skeleton', 'Skunk', 'Slith', 'Sliver', 'Sloth', 'Slug', 'Snail', 'Snake', 'Soldier', 'Soltari', 'Spawn',
        'Specter', 'Spellshaper', 'Sphinx', 'Spider', 'Spike', 'Spirit', 'Splinter', 'Sponge', 'Squid', 'Squirrel',
        'Starfish', 'Surrakar', 'Survivor', 'Synth', 'Tentacle', 'Tetravite', 'Thalakos', 'Thopter', 'Thrull',
        'Tiefling', 'Toy', 'Treefolk', 'Trilobite', 'Triskelavite', 'Troll', 'Turtle', 'Tyranid', 'Unicorn', 'Vampire',
        'Varmint', 'Vedalken', 'Volver', 'Wall', 'Walrus', 'Warlock', 'Warrior', 'Weasel', 'Weird', 'Werewolf',
        'Whale', 'Wizard', 'Wolf', 'Wolverine', 'Wombat', 'Worm', 'Wraith', 'Wurm', 'Yeti', 'Zombie', 'Zubera',
    ];

    public static function isValid(string $type, string $subtype): bool
    {
        return in_array($subtype, self::BY_TYPE[$type] ?? [], true);
    }

    /**
     * Vérifie une table `type => sous-types` pour une carte de type(s) `$types` : chaque clé doit être l'un de ces types et
     * chaque sous-type doit être admis pour sa clé.
     *
     * @param string|list<string> $types
     * @param array<string, list<string>> $subtypes
     */
    public static function assertValid(string|array $types, array $subtypes, string $cardName): void
    {
        $types = (array) $types;
        foreach ($subtypes as $key => $list) {
            $type = $key;
            if (!in_array($key, $types, true)) {
                throw new \InvalidArgumentException("$cardName : sous-types rattachés au type « $key », mais la carte est « " . implode(' ', $types) . ' »');
            }
            if (!is_array($list) || !array_is_list($list)) {
                throw new \InvalidArgumentException("$cardName : sous-types de « $key » invalides");
            }
            foreach ($list as $subtype) {
                if (!is_string($subtype) || !self::isValid($type, $subtype)) {
                    throw new \InvalidArgumentException("$cardName : sous-type « " . (is_string($subtype) ? $subtype : '?') . " » non admis pour « $type »");
                }
            }
        }
    }
}
