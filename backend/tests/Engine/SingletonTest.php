<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Card\Decks;
use App\Engine\Game\CardInstance;
use PHPUnit\Framework\TestCase;

/** Construction Commander : 99 cartes en bibliothèque (CR 903.5a), singleton hors terrains de base (CR 903.5b). */
final class SingletonTest extends TestCase
{
    private static function commander(): CardInstance
    {
        return new CardInstance('cmd', 'Chef', CardInstance::CREATURE, 3, commander: true, power: 3, toughness: 3, manaCost: '{1}{R}{G}', supertypes: [CardInstance::LEGENDARY]);
    }

    /** Cartes données puis Forêts jusqu'à 99. */
    private static function deck(CardInstance ...$cards): array
    {
        for ($i = count($cards); $i < Decks::LIBRARY_SIZE; $i++) {
            $cards[] = new CardInstance("f$i", 'Forest', CardInstance::LAND, produces: ['G']);
        }

        return ['commander' => self::commander(), 'library' => $cards];
    }

    private static function bolt(string $id): CardInstance
    {
        return new CardInstance($id, 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, manaCost: '{R}');
    }

    public function testDemoDecksAreSingletonOf99Cards(): void
    {
        foreach (range(0, 3) as $seat) {
            $deck = Decks::commanderDemo($seat);
            $this->assertCount(99, $deck['library'], "J$seat");
            $names = array_map(static fn (CardInstance $c) => $c->name, array_filter($deck['library'], static fn (CardInstance $c) => !in_array($c->name, Decks::BASIC_LANDS, true)));
            $this->assertSame(array_unique($names), $names, "J$seat");
            Decks::validate($deck, $seat);
        }
    }

    public function testBasicLandsMayBeRepeated(): void
    {
        Decks::validate(self::deck(self::bolt('b1')), 0);
        $this->addToAssertionCount(1);
    }

    public function testDuplicateNonBasicCardIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lightning Bolt');
        Decks::validate(self::deck(self::bolt('b1'), self::bolt('b2')), 1);
    }

    public function testCommanderCountsForSingleton(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Chef');
        Decks::validate(self::deck(new CardInstance('copy', 'Chef', CardInstance::CREATURE, 3, manaCost: '{1}{R}{G}')), 0);
    }

    public function testWrongLibrarySizeIsRefused(): void
    {
        $deck = self::deck();
        array_pop($deck['library']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('98 cartes au lieu de 99');
        Decks::validate($deck, 0);
    }

    public function testDemoDeckOfJ0HasItsKeywordCreatures(): void
    {
        $names = array_map(static fn (CardInstance $c) => $c->name, Decks::commanderDemo(0)['library']);
        foreach (['Giant Spider', 'Deadly Recluse', 'Boggart Brute', 'Colossal Dreadmaw'] as $card) {
            $this->assertContains($card, $names);
        }
    }
}
