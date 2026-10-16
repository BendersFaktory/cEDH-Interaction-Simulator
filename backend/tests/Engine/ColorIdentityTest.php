<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Card\Decks;
use App\Engine\Game\CardInstance;
use PHPUnit\Framework\TestCase;

/** Identité de couleurs Commander (CR 903.4) et validation des decks. */
final class ColorIdentityTest extends TestCase
{
    /** @return list<string> */
    private static function identity(CardInstance $c): array
    {
        return array_map(static fn ($color) => $color->symbol(), $c->colorIdentity());
    }

    private static function spell(string $name, string $manaCost, array $abilities = [], string $type = CardInstance::CREATURE): CardInstance
    {
        return new CardInstance($name, $name, $type, 2, manaCost: $manaCost, abilities: $abilities);
    }

    /** Commandant : créature légendaire (CR 903.3). */
    private static function chef(string $manaCost): CardInstance
    {
        return new CardInstance('Chef', 'Chef', CardInstance::CREATURE, 3, commander: true, power: 3, toughness: 3, manaCost: $manaCost, supertypes: [CardInstance::LEGENDARY]);
    }

    /** Complète la bibliothèque à 99 cartes avec des Montagnes (la taille est validée avant l'identité). */
    private static function deck(CardInstance $commander, CardInstance ...$cards): array
    {
        for ($i = count($cards); $i < Decks::LIBRARY_SIZE; $i++) {
            $cards[] = new CardInstance("m$i", 'Mountain', CardInstance::LAND, produces: ['R']);
        }

        return ['commander' => $commander, 'library' => $cards];
    }

    public function testHybridCostGivesBothColors(): void
    {
        $this->assertSame(['U', 'R'], self::identity(self::spell('Hybride', '{1}{U/R}')));
    }

    public function testColorlessCardsHaveNoIdentity(): void
    {
        $this->assertSame([], self::identity(self::spell('Eldrazi', '{5}{C}')));
        $this->assertSame([], self::identity(self::spell('Sol Ring', '{1}', [ActivatedAbility::mana('{C}{C}')], CardInstance::ARTIFACT)));
    }

    public function testSignetCountsItsProducedColors(): void
    {
        $signet = self::spell('Izzet Signet', '{2}', [ActivatedAbility::mana('{U}{R}', '{1}')], CardInstance::ARTIFACT);
        $this->assertSame(['U', 'R'], self::identity($signet));
    }

    public function testBasicLandAndDualLand(): void
    {
        $this->assertSame(['G'], self::identity(new CardInstance('f', 'Forest', CardInstance::LAND, produces: ['G'])));
        $this->assertSame(['W', 'B'], self::identity(new CardInstance('d', 'Dual', CardInstance::LAND, produces: ['B', 'W'])));
        $this->assertSame([], self::identity(new CardInstance('w', 'Wastes', CardInstance::LAND)));
    }

    public function testIdentityIsNotSerialized(): void
    {
        $this->assertArrayNotHasKey('colorIdentity', self::spell('X', '{R}')->toArray());
    }

    public function testCardOutsideCommanderIdentityIsRefused(): void
    {
        $deck = self::deck(self::chef('{1}{U}{R}'), self::spell('Ok', '{R}'), self::spell('Verte', '{1}{G}'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verte');
        Decks::validate($deck, 2);
    }

    public function testCardInsideIdentityIsAccepted(): void
    {
        Decks::validate(self::deck(self::chef('{1}{U}{R}'), self::spell('Ok', '{R}'), self::spell('Incolore', '{3}')), 0);
        $this->addToAssertionCount(1);
    }

    public function testDemoDecksOfAllSeatsAreValid(): void
    {
        foreach (range(0, 3) as $seat) {
            Decks::validate(Decks::commanderDemo($seat), $seat);
        }
        $this->addToAssertionCount(1);
    }
}
