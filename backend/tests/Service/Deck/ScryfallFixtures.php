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
            'Mind Stone' => $c('Mind Stone', 'Artifact', '{2}', 2, "{T}: Add {C}.\n{1}, {T}, Sacrifice this artifact: Draw a card."),
            'Izzet Signet' => $c('Izzet Signet', 'Artifact', '{2}', 2, '{1}, {T}: Add {U}{R}.'),
            'Serra Angel' => $c('Serra Angel', 'Creature — Angel', '{3}{W}{W}', 5, 'Flying, vigilance', ['power' => '4', 'toughness' => '4', 'keywords' => ['Flying', 'Vigilance']]),
            'Wall of Omens' => $c('Wall of Omens', 'Creature — Wall', '{1}{W}', 2, "Defender\nWhen this creature enters, draw a card.", ['power' => '0', 'toughness' => '4', 'keywords' => ['Defender']]),
            'Perilous Myr' => $c('Perilous Myr', 'Artifact Creature — Myr', '{2}', 2, 'When this creature dies, it deals 2 damage to any target.', ['power' => '1', 'toughness' => '1']),
            'Phyrexian Arena' => $c('Phyrexian Arena', 'Enchantment', '{1}{B}{B}', 3, 'At the beginning of your upkeep, you draw a card and you lose 1 life.'),
            'Underground Sea' => $c('Underground Sea', 'Land — Island Swamp', '', 0, '({T}: Add {U} or {B}.)'),
            'Command Tower' => $c('Command Tower', 'Land', '', 0, "{T}: Add one mana of any color in your commander's color identity."),
            'Birds of Paradise' => $c('Birds of Paradise', 'Creature — Bird', '{G}', 1, "Flying\n{T}: Add one mana of any color.", ['power' => '0', 'toughness' => '1', 'keywords' => ['Flying']]),
            'Lotus Petal' => $c('Lotus Petal', 'Artifact', '{0}', 0, '{T}, Sacrifice Lotus Petal: Add one mana of any color.'),
            'Divination' => $c('Divination', 'Sorcery', '{2}{U}', 3, 'Draw two cards.'),
            // Cartes factices (aucune carte réelle n'a exactement ces deux lignes) : plusieurs effets par sort.
            'Shock and Draw' => $c('Shock and Draw', 'Instant', '{1}{R}', 2, "Shock and Draw deals 2 damage to any target.\nDraw a card."),
            'Bolt and Counter' => $c('Bolt and Counter', 'Instant', '{U}{R}', 2, "Bolt and Counter deals 3 damage to any target.\nCounter target spell."),
            'Ancient Tomb' => $c('Ancient Tomb', 'Land', '', 0, '{T}: Add {C}{C}. Ancient Tomb deals 2 damage to you.'),
            'Talisman of Dominance' => $c('Talisman of Dominance', 'Artifact', '{2}', 2, "{T}: Add {C}.\n{T}: Add {U} or {B}. Talisman of Dominance deals 1 damage to you."),
            'Underground River' => $c('Underground River', 'Land', '', 0, "{T}: Add {C}.\n{T}: Add {U} or {B}. Underground River deals 1 damage to you."),
            'Mana Confluence' => $c('Mana Confluence', 'Land', '', 0, '{T}, Pay 1 life: Add one mana of any color.'),
            'Polluted Delta' => $c('Polluted Delta', 'Land', '', 0, '{T}, Pay 1 life, Sacrifice Polluted Delta: Search your library for an Island or Swamp card, put it onto the battlefield, then shuffle.'),
            'Prismatic Vista' => $c('Prismatic Vista', 'Land', '', 0, "{T}: Add {C}.\n{T}, Pay 1 life, Sacrifice Prismatic Vista: Search your library for a basic land card, put it onto the battlefield, then shuffle."),
            'Evolving Wilds' => $c('Evolving Wilds', 'Land', '', 0, '{T}, Sacrifice Evolving Wilds: Search your library for a basic land card, put it onto the battlefield tapped, then shuffle.'),
            'Watery Grave' => $c('Watery Grave', 'Land — Island Swamp', '', 0, "({T}: Add {U} or {B}.)\nAs Watery Grave enters, you may pay 2 life. If you don't, it enters tapped."),
            'Island' => $c('Island', 'Basic Land — Island', '', 0, '({T}: Add {U}.)'),
            'Swamp' => $c('Swamp', 'Basic Land — Swamp', '', 0, '({T}: Add {B}.)'),
            'Tarmogoyf' => $c('Tarmogoyf', 'Creature — Lhurgoyf', '{1}{G}', 2, "Tarmogoyf's power is equal to the number of card types among cards in all graveyards.", ['power' => '*', 'toughness' => '1+*']),
        ];
    }
}
