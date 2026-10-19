<?php

declare(strict_types=1);

namespace App\Tests\Service\Deck;

/** Cartes Scryfall figées (champs utiles seulement, texte Oracle réel). */
final class ScryfallFixtures
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $c = static fn (string $name, string $type, string $cost, float $cmc, string $text, array $extra = []): array => $extra + [
            'name' => $name, 'layout' => 'normal', 'type_line' => $type, 'mana_cost' => $cost, 'cmc' => $cmc,
            'oracle_text' => $text, 'keywords' => [],
        ];

        return [
            'Plains' => $c('Plains', 'Basic Land — Plains', '', 0, '({T}: Add {W}.)'),
            'Forest' => $c('Forest', 'Basic Land — Forest', '', 0, '({T}: Add {G}.)'),
            'Sol Ring' => $c('Sol Ring', 'Artifact', '{1}', 1, '{T}: Add {C}{C}.'),
            'Lightning Bolt' => $c('Lightning Bolt', 'Instant', '{R}', 1, 'Lightning Bolt deals 3 damage to any target.'),
            'Blaze' => $c('Blaze', 'Sorcery', '{X}{R}', 1, 'Blaze deals X damage to any target.'),
            'Counterspell' => $c('Counterspell', 'Instant', '{U}{U}', 2, 'Counter target spell.'),
            'Dark Ritual' => $c('Dark Ritual', 'Instant', '{B}', 1, 'Add {B}{B}{B}.'),
            'Llanowar Elves' => $c('Llanowar Elves', 'Creature — Elf Druid', '{G}', 1, '{T}: Add {G}.', ['power' => '1', 'toughness' => '1']),
            'Elvish Visionary' => $c('Elvish Visionary', 'Creature — Elf Shaman', '{1}{G}', 2, 'When this creature enters, draw a card.', ['power' => '1', 'toughness' => '1']),
            'Wind Drake' => $c('Wind Drake', 'Creature — Drake', '{2}{U}', 3, 'Flying', ['power' => '2', 'toughness' => '2', 'keywords' => ['Flying']]),
            'Isamaru, Hound of Konda' => $c('Isamaru, Hound of Konda', 'Legendary Creature — Dog', '{W}', 1, '', ['power' => '2', 'toughness' => '2']),
            'Juggernaut' => $c('Juggernaut', 'Artifact Creature — Juggernaut', '{4}', 4, "Juggernaut attacks each combat if able.\nJuggernaut can't be blocked by Walls.", ['power' => '5', 'toughness' => '3']),
            'Rhystic Study' => $c('Rhystic Study', 'Enchantment', '{2}{U}', 3, 'Whenever an opponent casts a spell, you may draw a card unless that player pays {1}.'),
            'Jace, the Mind Sculptor' => $c('Jace, the Mind Sculptor', 'Legendary Planeswalker — Jace', '{2}{U}{U}', 4, '+2: Look at the top card.', ['loyalty' => '3']),
            'Delver of Secrets // Insectile Aberration' => ['name' => 'Delver of Secrets // Insectile Aberration', 'layout' => 'transform', 'type_line' => 'Creature — Human Wizard // Creature — Human Insect'],
            'Tarmogoyf' => $c('Tarmogoyf', 'Creature — Lhurgoyf', '{1}{G}', 2, "Tarmogoyf's power is equal to the number of card types among cards in all graveyards.", ['power' => '*', 'toughness' => '1+*']),
        ];
    }
}
