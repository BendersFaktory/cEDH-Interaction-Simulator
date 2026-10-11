<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Card\Subtypes;
use App\Engine\Game\CardInstance;
use PHPUnit\Framework\TestCase;

/** Sous-types rattachés à leur type (règle 205.3) et couleur déduite du coût. */
final class SubtypesTest extends TestCase
{
    private static function card(string $type, array $subtypes, string $manaCost = '{2}'): CardInstance
    {
        return new CardInstance('c1', 'Carte', $type, 2, manaCost: $manaCost, subtypes: $subtypes);
    }

    public function testCatalogue(): void
    {
        $this->assertTrue(Subtypes::isValid(CardInstance::CREATURE, 'Eldrazi'));
        $this->assertFalse(Subtypes::isValid(CardInstance::ARTIFACT, 'Eldrazi'));
        $this->assertTrue(Subtypes::isValid(CardInstance::ENCHANTMENT, 'Aura'));
        $this->assertFalse(Subtypes::isValid(CardInstance::CREATURE, 'Aura'));
        $this->assertTrue(Subtypes::isValid(CardInstance::LAND, 'Forest'));
        $this->assertTrue(Subtypes::isValid(CardInstance::SORCERY, 'Arcane'));
    }

    public function testEldraziIsAcceptedOnACreature(): void
    {
        $mimic = self::card(CardInstance::CREATURE, [CardInstance::CREATURE => ['Eldrazi']]);
        $this->assertTrue($mimic->hasSubtype('Eldrazi'));
        $this->assertTrue($mimic->hasSubtype('Eldrazi', CardInstance::CREATURE));
        $this->assertFalse($mimic->hasSubtype('Eldrazi', CardInstance::ARTIFACT));
        $this->assertFalse($mimic->hasSubtype('Elf'));
    }

    public function testEldraziIsRefusedOnAnArtifact(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::card(CardInstance::ARTIFACT, [CardInstance::ARTIFACT => ['Eldrazi']]);
    }

    public function testSubtypesMustBeFiledUnderTheCardType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::card(CardInstance::ARTIFACT, [CardInstance::CREATURE => ['Eldrazi']]);
    }

    public function testAuraIsAcceptedOnAnEnchantment(): void
    {
        $this->assertTrue(self::card(CardInstance::ENCHANTMENT, [CardInstance::ENCHANTMENT => ['Aura']])->hasSubtype('Aura', CardInstance::ENCHANTMENT));
    }

    public function testAuraIsRefusedOnACreature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::card(CardInstance::CREATURE, [CardInstance::CREATURE => ['Aura']]);
    }

    public function testRoundTripKeepsSubtypesAndRevalidates(): void
    {
        $mimic = self::card(CardInstance::CREATURE, [CardInstance::CREATURE => ['Eldrazi']]);
        $copy = CardInstance::fromArray($mimic->toArray());
        $this->assertSame([CardInstance::CREATURE => ['Eldrazi']], $copy->subtypes);
        $this->assertSame($mimic->toArray(), $copy->toArray());

        $tampered = $mimic->toArray();
        $tampered['type'] = CardInstance::ARTIFACT;
        $this->expectException(\InvalidArgumentException::class);
        CardInstance::fromArray($tampered);
    }

    public function testKeyIsOmittedWithoutSubtypes(): void
    {
        $bear = self::card(CardInstance::CREATURE, [], '{1}{G}');
        $this->assertArrayNotHasKey('subtypes', $bear->toArray());
        $this->assertArrayNotHasKey('subtypes', $bear->toPublic());
        $this->assertSame([], CardInstance::fromArray($bear->toArray())->subtypes);
    }

    public function testColorlessIsDeducedFromTheManaCost(): void
    {
        $this->assertTrue(self::card(CardInstance::CREATURE, [], '{2}')->isColorless());
        $this->assertTrue(self::card(CardInstance::CREATURE, [], '{C}{C}')->isColorless());
        $this->assertFalse(self::card(CardInstance::CREATURE, [], '{1}{G}')->isColorless());
        $this->assertFalse(self::card(CardInstance::CREATURE, [], '{R/G}{1}')->isColorless());
    }
}
