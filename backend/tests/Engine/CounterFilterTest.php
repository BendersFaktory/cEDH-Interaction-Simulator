<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Contresorts filtrés (ROADMAP §2) : Dispel ne contre qu'un sort instantané (`SpellEffect::$target` = `instant`),
 * Counterspell reste sans filtre.
 */
final class CounterFilterTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function dispel(string $id): CardInstance
    {
        return new CardInstance($id, 'Dispel', CardInstance::INSTANT, 1, effects: [new SpellEffect('counter', target: CardInstance::INSTANT)]);
    }

    /** Cibles `stack:<id>` proposées à `$cardId` (joueur 1, priorité donnée). */
    private function counterTargets(\App\Engine\Game\GameState $s, string $cardId): array
    {
        $casts = array_filter((new LegalActionGenerator())->for($s, 1), static fn ($a) => $a instanceof CastSpell && $a->cardId === $cardId);

        return array_values(array_map(static fn (CastSpell $a) => $a->targets, $casts));
    }

    public function testDispelOnlyTargetsInstantSpells(): void
    {
        $s = (new ScenarioBuilder())->hand(1, self::dispel('d1'))->untappedLands(1, 1)->build(self::PASS);
        $s->stack[] = new StackItem('sor', new CardInstance('sor', 'Test Sorcery', CardInstance::SORCERY, 1), 0);
        $s->stack[] = new StackItem('ins', new CardInstance('ins', 'Test Instant', CardInstance::INSTANT, 1), 0);
        $s->priority = new PriorityState(1);

        $this->assertSame([['stack:ins']], $this->counterTargets($s, 'd1'), 'Dispel ne vise que le sort instantané');
    }

    public function testCounterspellStillTargetsAnySpell(): void
    {
        $s = (new ScenarioBuilder())->hand(1, ScenarioBuilder::counterspell('cs'))->untappedLands(1, 2)->build(self::PASS);
        $s->stack[] = new StackItem('sor', new CardInstance('sor', 'Test Sorcery', CardInstance::SORCERY, 1), 0);
        $s->stack[] = new StackItem('ins', new CardInstance('ins', 'Test Instant', CardInstance::INSTANT, 1), 0);
        $s->priority = new PriorityState(1);

        $this->assertSame([['stack:sor'], ['stack:ins']], $this->counterTargets($s, 'cs'));
    }

    public function testRejectsAFilterOnAnEffectThatIsNotACounter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SpellEffect('damage', 2, null, CardInstance::INSTANT);
    }
}
