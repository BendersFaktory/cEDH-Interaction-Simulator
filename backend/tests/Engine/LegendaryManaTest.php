<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Game\CardInstance;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Engine\Mana\ManaRestriction;
use PHPUnit\Framework\TestCase;

/**
 * Mana « legendary » (Delighted Halfling : « Spend this mana only to cast a legendary spell ») : ne paie qu'un sort légendaire,
 * jamais une capacité, même celle d'une créature légendaire (`ManaContext` sans sort).
 */
final class LegendaryManaTest extends TestCase
{
    private static function halfling(): CardInstance
    {
        return new CardInstance('h', 'Delighted Halfling', CardInstance::CREATURE, 2, power: 1, toughness: 2,
            abilities: [ActivatedAbility::mana('{G}@' . ManaRestriction::LEGENDARY)], supertypes: [CardInstance::LEGENDARY]);
    }

    private static function spell(string $id, bool $legendary): CardInstance
    {
        return new CardInstance($id, 'Test Spell', CardInstance::INSTANT, 1, supertypes: $legendary ? [CardInstance::LEGENDARY] : []);
    }

    public function testRestrictedManaPaysOnlyALegendarySpell(): void
    {
        $p = (new ScenarioBuilder())->permanent(0, self::halfling())->build(self::PASS)->player(0);
        $green = ManaCost::parse('{G}');

        $this->assertNotNull(ManaPayment::plan($p, $green, 0, 1, null, self::spell('ok', true)), 'Sort légendaire : le mana du Halfling sert');
        $this->assertNull(ManaPayment::plan($p, $green, 0, 1, null, self::spell('ko', false)), 'Sort non légendaire : refusé');
    }

    public function testRestrictedManaNeverPaysAnAbilityOfALegendarySource(): void
    {
        $p = (new ScenarioBuilder())->permanent(0, self::halfling())->build(self::PASS)->player(0);
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{G}'), 0, 1, null, self::halfling(), new \App\Engine\Mana\ManaContext($p, 1, false)));
    }

    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];
}
