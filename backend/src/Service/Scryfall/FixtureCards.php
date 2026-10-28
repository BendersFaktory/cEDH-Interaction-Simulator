<?php

declare(strict_types=1);

namespace App\Service\Scryfall;

/**
 * Cartes Scryfall figées (`fixtures/scryfall/cards.json`, champs utiles seulement, texte Oracle réel) : source commune des tests
 * (`ScryfallFixtures`) et de la recette UI (variable d'environnement `SCRYFALL_FIXTURES=1`, voir `ScryfallClient`).
 */
final class FixtureCards
{
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/fixtures/scryfall/cards.json';
    }

    /** @return array<string, array<string, mixed>> nom exact (« A // B » pour une carte à deux faces) => carte */
    public static function all(): array
    {
        $cards = json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
        $byName = [];
        foreach ($cards as $card) {
            $byName[(string) $card['name']] = $card;
        }

        return $byName;
    }
}
