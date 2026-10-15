<?php

declare(strict_types=1);

namespace App\Engine\Card;

use App\Engine\Game\CardInstance;

/**
 * Sous-types admis par type de carte (règle 205.3 : un sous-type est rattaché à son type ; un artefact ne peut pas être
 * Eldrazi, une créature ne peut pas être Aura). Catalogue limité aux besoins des cartes de démo.
 */
final class Subtypes
{
    /** @var array<string, list<string>> */
    private const BY_TYPE = [
        CardInstance::CREATURE => ['Eldrazi', 'Elf', 'Druid', 'Shaman', 'Dog', 'Myr', 'Bear', 'Kobold', 'Goblin', 'Human', 'Wizard', 'Golem'],
        CardInstance::ARTIFACT => ['Equipment', 'Treasure'],
        CardInstance::ENCHANTMENT => ['Aura'],
        CardInstance::LAND => ['Forest', 'Island', 'Mountain', 'Plains', 'Swamp'],
        CardInstance::INSTANT => ['Arcane', 'Adventure'],
        CardInstance::SORCERY => ['Arcane', 'Adventure'],
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
