<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Card\Decks;
use App\Engine\Game\CardInstance;
use App\Engine\Mana\Color;
use PHPUnit\Framework\TestCase;

/** Cartes à plusieurs types (artefact-créature, terrain-créature). */
final class MultiTypeTest extends TestCase
{
    private static function golem(): CardInstance
    {
        return new CardInstance('g1', 'Golem', [CardInstance::ARTIFACT, CardInstance::CREATURE], 3, power: 2, toughness: 2, manaCost: '{3}', subtypes: [CardInstance::CREATURE => ['Golem']]);
    }

    public function testPredicatesSeeEveryType(): void
    {
        $golem = self::golem();
        $this->assertTrue($golem->isCreature());
        $this->assertTrue($golem->hasType(CardInstance::ARTIFACT));
        $this->assertTrue($golem->isPermanent());
        $this->assertFalse($golem->isLand());
        $this->assertFalse($golem->isInstantSpeed());
    }

    public function testLandCreatureIsBoth(): void
    {
        $card = new CardInstance('d1', 'Terrain vivant', [CardInstance::LAND, CardInstance::CREATURE], 0, power: 1, toughness: 1);
        $this->assertTrue($card->isLand());
        $this->assertTrue($card->isCreature());
        $this->assertSame([Color::Colorless], $card->producedColors());
    }

    public function testSingleTypeStaysAStringInSerializedForm(): void
    {
        $bolt = new CardInstance('b1', 'Bolt', CardInstance::INSTANT, 1);
        $this->assertSame('instant', $bolt->toArray()['type']);
        $this->assertSame(['artifact', 'creature'], self::golem()->toArray()['type']);
    }

    public function testRoundTrip(): void
    {
        $golem = self::golem();
        $copy = CardInstance::fromArray($golem->toArray());
        $this->assertSame($golem->toArray(), $copy->toArray());
        $this->assertSame(['artifact', 'creature'], $copy->types);
    }

    public function testSubtypeMustBelongToOneOfTheTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CardInstance('x', 'X', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, subtypes: [CardInstance::LAND => ['Forest']]);
    }

    public function testSubtypeMustBeAdmittedForItsType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CardInstance('x', 'X', [CardInstance::ARTIFACT, CardInstance::CREATURE], 2, subtypes: [CardInstance::ARTIFACT => ['Golem']]);
    }

    public function testEveryDemoDeckContainsTheGolem(): void
    {
        foreach ([0, 1, 2, 3] as $player) {
            $deck = Decks::commanderDemo($player);
            $this->assertCount(1, array_filter($deck['library'], static fn (CardInstance $c) => $c->name === 'Golem de Fer'), "J$player");
            $this->assertCount(39, $deck['library']);
        }
    }
}
