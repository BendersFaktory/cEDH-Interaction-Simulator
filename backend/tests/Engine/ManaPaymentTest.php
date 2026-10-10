<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Game\CardInstance;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use PHPUnit\Framework\TestCase;

final class ManaPaymentTest extends TestCase
{
    /** @param list<string|null> $specs symbole produit ('R'...) ; préfixe '!' = engagé ; null = terrain sans `produces` */
    private function player(array $specs): PlayerState
    {
        $lands = [];
        foreach ($specs as $i => $spec) {
            $tapped = is_string($spec) && str_starts_with($spec, '!');
            $symbol = is_string($spec) ? ltrim($spec, '!') : null;
            $lands[] = new CardInstance('l' . $i, 'Land', CardInstance::LAND, 0, null, 0, $tapped, false, 0, 0, 0, 0, null, $symbol === null ? [] : [$symbol]);
        }

        return new PlayerState(0, 40, [], [], $lands);
    }

    /** @param list<array{land: string, color: Color}>|null $plan */
    private function symbols(?array $plan): ?array
    {
        return $plan === null ? null : array_map(fn (array $e) => $e['color']->symbol(), $plan);
    }

    public function testColoredAndGeneric(): void
    {
        $plan = ManaPayment::plan($this->player(['R', 'U', 'U']), ManaCost::parse('{1}{R}'));
        $this->assertCount(2, $plan);
        $this->assertSame(['l0', 'l1'], array_column($plan, 'land'));
    }

    public function testImpossibleColor(): void
    {
        $this->assertNull(ManaPayment::plan($this->player(['R', 'R', 'R']), ManaCost::parse('{U}')));
        $this->assertNull(ManaPayment::plan($this->player(['R']), ManaCost::parse('{1}{R}')));
    }

    public function testHybridPicksAvailableColor(): void
    {
        $plan = ManaPayment::plan($this->player(['G', 'U']), ManaCost::parse('{R/G}'));
        $this->assertSame(['G'], $this->symbols($plan));
        $this->assertSame(['l0'], array_column($plan, 'land'));
        $this->assertNull(ManaPayment::plan($this->player(['U']), ManaCost::parse('{R/G}')));
    }

    public function testHybridDoesNotStarveColoredSymbol(): void
    {
        // {R}{R/G} avec R et G : l'hybride doit prendre G.
        $plan = ManaPayment::plan($this->player(['R', 'G']), ManaCost::parse('{R}{R/G}'));
        $this->assertNotNull($plan);
        $this->assertEqualsCanonicalizing(['R', 'G'], $this->symbols($plan));
    }

    public function testXPlanAndMaxX(): void
    {
        $p = $this->player(['R', 'R', 'U', 'U', 'U']);
        $cost = ManaCost::parse('{X}{R}');
        $this->assertCount(4, ManaPayment::plan($p, $cost, 3));
        $this->assertNull(ManaPayment::plan($p, $cost, 5));
        $this->assertSame(4, ManaPayment::maxX($p, $cost));
        $this->assertSame(0, ManaPayment::maxX($p, ManaCost::parse('{R}')));
        $this->assertSame(0, ManaPayment::maxX($this->player(['U']), $cost));
        $this->assertSame(2, ManaPayment::maxX($this->player(['R', 'U', 'U', 'U', 'U']), ManaCost::parse('{X}{X}{R}')));
    }

    public function testTappedLandsIgnored(): void
    {
        $p = $this->player(['!R', 'R']);
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{R}{R}')));
        $this->assertSame(['l1'], array_column(ManaPayment::plan($p, ManaCost::parse('{R}')), 'land'));
        $this->assertSame(1, ManaPayment::maxX($p, ManaCost::parse('{X}')));
    }

    public function testLegacyLandPaysGenericOnly(): void
    {
        $p = $this->player([null, null]);
        $this->assertNotNull(ManaPayment::plan($p, ManaCost::parse('{2}')));
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{R}')));
        $this->assertSame(['C', 'C'], $this->symbols(ManaPayment::plan($p, ManaCost::parse('{2}'))));
    }

    public function testGenericLeavesScarceColorUntapped(): void
    {
        $plan = ManaPayment::plan($this->player(['R', 'G', 'G']), ManaCost::parse('{1}'));
        $this->assertSame(['G'], $this->symbols($plan));
        $this->assertSame(['l1'], array_column($plan, 'land'));

        // Égalité d'abondance : ordre WUBRGC (W avant R).
        $plan = ManaPayment::plan($this->player(['R', 'W']), ManaCost::parse('{1}'));
        $this->assertSame(['W'], $this->symbols($plan));
    }

    public function testZeroCostAndEmpty(): void
    {
        $this->assertSame([], ManaPayment::plan($this->player([]), new ManaCost()));
        $this->assertNull(ManaPayment::plan($this->player([]), ManaCost::parse('{1}')));
    }

    public function testDeterministicAndNoMutation(): void
    {
        $p = $this->player(['R', 'G', 'G', 'U']);
        $before = json_encode($p->toArray());
        $cost = ManaCost::parse('{1}{R/U}{G}');
        $a = ManaPayment::plan($p, $cost);
        $b = ManaPayment::plan($p, $cost);
        $this->assertEquals($a, $b);
        ManaPayment::maxX($p, ManaCost::parse('{X}'));
        $this->assertSame($before, json_encode($p->toArray()));
        foreach ($p->battlefield as $land) {
            $this->assertFalse($land->tapped);
        }
    }
}
