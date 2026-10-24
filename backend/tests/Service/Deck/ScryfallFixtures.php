<?php

declare(strict_types=1);

namespace App\Tests\Service\Deck;

use App\Service\Scryfall\FixtureCards;

/** Cartes Scryfall figées : voir `FixtureCards` (fixtures/scryfall/cards.json, partagées avec la recette UI). */
final class ScryfallFixtures
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return FixtureCards::all();
    }
}
