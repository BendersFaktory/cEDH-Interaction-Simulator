<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Game\CardInstance;
use App\Engine\Game\PlayerState;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Engine\Mana\ManaPlan;
use App\Engine\Mana\ManaPool;
use PHPUnit\Framework\TestCase;

final class ManaPaymentTest extends TestCase
{
    /**
     * @param list<string|null> $specs 'R' = terrain produisant R ; 'RG' = terrain double ; '!R' = engagé ; null = terrain sans
     *                                 `produces` ; 'SOL' = Sol Ring ({T} : {C}{C}) ;
     *                                 'SIG' = Izzet Signet ({1}, {T} : {U}{R}) ;
     *                                 'TEMPLE' = Eldrazi Temple ({T} : {C} ; {T} : {C}{C} restreint Eldrazi)
     * @param array<string, int> $pool
     * @param array<string, array<string, int>> $restricted
     */
    private function player(array $specs, array $pool = [], array $restricted = []): PlayerState
    {
        $cards = [];
        foreach ($specs as $i => $spec) {
            $tapped = is_string($spec) && str_starts_with($spec, '!');
            $symbols = is_string($spec) ? ltrim($spec, '!') : null;
            if ($symbols === 'SIG') {
                $cards[] = new CardInstance('a' . $i, 'Izzet Signet', CardInstance::ARTIFACT, 2, null, 0, $tapped, false, 0, 0, 0, 0, null, [], [ActivatedAbility::mana('{U}{R}', '{1}')]);
                continue;
            }
            if ($symbols === 'TEMPLE') {
                $cards[] = new CardInstance('l' . $i, 'Eldrazi Temple', CardInstance::LAND, 0, null, 0, $tapped, false, 0, 0, 0, 0, null, [], [ActivatedAbility::mana('{C}{C}@eldrazi')]);
                continue;
            }
            if ($symbols === 'SOL') {
                $cards[] = new CardInstance('a' . $i, 'Sol Ring', CardInstance::ARTIFACT, 1, null, 0, $tapped, false, 0, 0, 0, 0, null, [], [ActivatedAbility::mana('{C}{C}')]);
                continue;
            }
            $cards[] = new CardInstance('l' . $i, 'Land', CardInstance::LAND, 0, null, 0, $tapped, false, 0, 0, 0, 0, null, $symbols === null ? [] : str_split($symbols));
        }

        return new PlayerState(0, 40, [], [], $cards, [], [], [], [], new ManaPool($pool, $restricted));
    }

    private static function mimic(): CardInstance
    {
        return new CardInstance('mimic', 'Eldrazi Mimic', CardInstance::CREATURE, 2, null, 0, false, false, 2, 1, 0, 0, '{2}', subtypes: [CardInstance::CREATURE => ['Eldrazi']]);
    }

    private static function bear(): CardInstance
    {
        return new CardInstance('bear', 'Grizzly Bears', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2, 0, 0, '{1}{G}');
    }

    /** Créature incolore sans sous-type Eldrazi (n'a pas droit au mana du Temple). */
    private static function golem(): CardInstance
    {
        return new CardInstance('golem', 'Golem', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2, 0, 0, '{2}');
    }

    /** @return list<string>|null */
    private function cards(?ManaPlan $plan): ?array
    {
        return $plan === null ? null : array_column($plan->taps, 'card');
    }

    public function testColoredAndGeneric(): void
    {
        $plan = ManaPayment::plan($this->player(['R', 'U', 'U']), ManaCost::parse('{1}{R}'));
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        $this->assertSame(['U' => 1, 'R' => 1], $plan->spend);
    }

    public function testImpossibleColor(): void
    {
        $this->assertNull(ManaPayment::plan($this->player(['R', 'R', 'R']), ManaCost::parse('{U}')));
        $this->assertNull(ManaPayment::plan($this->player(['R']), ManaCost::parse('{1}{R}')));
    }

    public function testHybridPicksAvailableColor(): void
    {
        $plan = ManaPayment::plan($this->player(['G', 'U']), ManaCost::parse('{R/G}'));
        $this->assertSame(['G' => 1], $plan->spend);
        $this->assertSame(['l0'], $this->cards($plan));
        $this->assertNull(ManaPayment::plan($this->player(['U']), ManaCost::parse('{R/G}')));
    }

    public function testHybridDoesNotStarveColoredSymbol(): void
    {
        // {R}{R/G} avec R et G : l'hybride doit prendre G.
        $plan = ManaPayment::plan($this->player(['R', 'G']), ManaCost::parse('{R}{R/G}'));
        $this->assertNotNull($plan);
        $this->assertSame(['R' => 1, 'G' => 1], $plan->spend);
    }

    public function testXPlanAndMaxX(): void
    {
        $p = $this->player(['R', 'R', 'U', 'U', 'U']);
        $cost = ManaCost::parse('{X}{R}');
        $this->assertCount(4, ManaPayment::plan($p, $cost, 3)->taps);
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
        $this->assertSame(['l1'], $this->cards(ManaPayment::plan($p, ManaCost::parse('{R}'))));
        $this->assertSame(1, ManaPayment::maxX($p, ManaCost::parse('{X}')));
    }

    public function testLegacyLandPaysGenericOnly(): void
    {
        $p = $this->player([null, null]);
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{R}')));
        $this->assertSame(['C' => 2], ManaPayment::plan($p, ManaCost::parse('{2}'))->spend);
        $this->assertSame(['C' => 1], ManaPayment::plan($p, ManaCost::parse('{C}'))->spend);
    }

    public function testGenericTieBrokenByBattlefieldOrder(): void
    {
        // Même gaspillage et même nombre d'engagements : le premier permanent du champ de bataille gagne.
        $plan = ManaPayment::plan($this->player(['R', 'G', 'G']), ManaCost::parse('{1}'));
        $this->assertSame(['l0'], $this->cards($plan));
        $this->assertSame(['R' => 1], $plan->spend);
    }

    public function testZeroCostAndEmpty(): void
    {
        $plan = ManaPayment::plan($this->player([]), new ManaCost());
        $this->assertSame([], $plan->taps);
        $this->assertSame([], $plan->spend);
        $this->assertNull(ManaPayment::plan($this->player([]), ManaCost::parse('{1}')));
    }

    public function testDeterministicAndNoMutation(): void
    {
        $p = $this->player(['R', 'G', 'G', 'U', 'RG', 'SOL']);
        $before = json_encode($p->toArray());
        $cost = ManaCost::parse('{1}{R/U}{G}');
        $a = ManaPayment::plan($p, $cost);
        $b = ManaPayment::plan($p, $cost);
        $this->assertNotNull($a);
        $this->assertEquals($a, $b);
        ManaPayment::maxX($p, ManaCost::parse('{X}'));
        $this->assertSame($before, json_encode($p->toArray()));
        foreach ($p->battlefield as $land) {
            $this->assertFalse($land->tapped);
        }
    }

    public function testDualPaysEitherColorAsNeeded(): void
    {
        $this->assertSame(['l0'], $this->cards(ManaPayment::plan($this->player(['RG']), ManaCost::parse('{R}'))));
        $this->assertSame(['l0'], $this->cards(ManaPayment::plan($this->player(['RG']), ManaCost::parse('{G}'))));
        $plan = ManaPayment::plan($this->player(['RG']), ManaCost::parse('{G}'));
        $this->assertSame(['G' => 1], $plan->spend);
        $this->assertSame(1, $plan->taps[0]['ability']); // la capacité {G} du dual
        // Deux couleurs avec un dual + un terrain simple.
        $plan = ManaPayment::plan($this->player(['RG', 'R']), ManaCost::parse('{R}{G}'));
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        $this->assertNull(ManaPayment::plan($this->player(['RG']), ManaCost::parse('{R}{G}')));
    }

    public function testDualNotWastedWhenSimpleLandSuffices(): void
    {
        $this->assertSame(['l1'], $this->cards(ManaPayment::plan($this->player(['RG', 'R']), ManaCost::parse('{R}'))));
        $this->assertSame(['l1'], $this->cards(ManaPayment::plan($this->player(['RG', 'G']), ManaCost::parse('{1}'))));
        // Générique : le terrain simple, même placé après.
        $this->assertSame(['l1'], $this->cards(ManaPayment::plan($this->player(['RG', 'U']), ManaCost::parse('{1}'))));
    }

    public function testSolRingPaysTwoWithoutWaste(): void
    {
        $plan = ManaPayment::plan($this->player(['SOL', 'R']), ManaCost::parse('{2}'));
        $this->assertSame(['a0'], $this->cards($plan));
        $this->assertSame(['C' => 2], $plan->spend);
    }

    public function testSolRingForOneLeavesSurplusInPool(): void
    {
        $plan = ManaPayment::plan($this->player(['SOL']), ManaCost::parse('{1}'));
        $this->assertSame(['a0'], $this->cards($plan));
        $this->assertSame(['C' => 1], $plan->spend); // le second {C} reste dans la réserve
        // Un terrain simple est préféré : pas de gaspillage.
        $this->assertSame(['l1'], $this->cards(ManaPayment::plan($this->player(['SOL', 'R']), ManaCost::parse('{1}'))));
    }

    public function testSolRingCombinesWithLands(): void
    {
        $plan = ManaPayment::plan($this->player(['SOL', 'R']), ManaCost::parse('{2}{R}'));
        $this->assertSame(['a0', 'l1'], $this->cards($plan));
        $this->assertSame(['R' => 1, 'C' => 2], $plan->spend);
        $this->assertNull(ManaPayment::plan($this->player(['SOL']), ManaCost::parse('{2}{R}')));
    }

    public function testPoolIsSpentFirst(): void
    {
        $plan = ManaPayment::plan($this->player(['R'], ['R' => 1]), ManaCost::parse('{R}'));
        $this->assertSame([], $plan->taps);
        $this->assertSame(['R' => 1], $plan->spend);
    }

    public function testPoolPlusLands(): void
    {
        $plan = ManaPayment::plan($this->player(['U', 'R'], ['R' => 1]), ManaCost::parse('{1}{R}{U}'));
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        $this->assertSame(['U' => 1, 'R' => 2], $plan->spend);

        $plan = ManaPayment::plan($this->player(['U'], ['R' => 1]), ManaCost::parse('{1}{R}'));
        $this->assertSame(['l0'], $this->cards($plan));
        $this->assertSame(['U' => 1, 'R' => 1], $plan->spend);

        // La réserve (coloré) ne paie pas ce que le coût ne demande pas.
        $this->assertNull(ManaPayment::plan($this->player([], ['R' => 1]), ManaCost::parse('{U}')));
    }

    public function testHybridWithDual(): void
    {
        $plan = ManaPayment::plan($this->player(['RG', 'U']), ManaCost::parse('{R/G}{U}'));
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        $this->assertNull(ManaPayment::plan($this->player(['RG']), ManaCost::parse('{R/G}{R/G}')));
        $plan = ManaPayment::plan($this->player(['RG', 'R']), ManaCost::parse('{R/G}{R/G}'));
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        // Hybride payé par la réserve plutôt que par un engagement.
        $plan = ManaPayment::plan($this->player(['RG'], ['G' => 1]), ManaCost::parse('{R/G}'));
        $this->assertSame([], $plan->taps);
        $this->assertSame(['G' => 1], $plan->spend);
    }

    public function testMaxXWithSolRing(): void
    {
        $this->assertSame(2, ManaPayment::maxX($this->player(['SOL', 'R']), ManaCost::parse('{X}{R}')));
        $this->assertSame(1, ManaPayment::maxX($this->player(['SOL']), ManaCost::parse('{X}{X}')));
        $this->assertSame(0, ManaPayment::maxX($this->player(['SOL']), ManaCost::parse('{X}{R}')));
        $this->assertSame(3, ManaPayment::maxX($this->player(['SOL'], ['U' => 1]), ManaCost::parse('{X}')));
        $plan = ManaPayment::plan($this->player(['SOL', 'R']), ManaCost::parse('{X}{R}'), 2);
        $this->assertSame(['a0', 'l1'], $this->cards($plan));
        $this->assertSame(['R' => 1, 'C' => 2], $plan->spend);
    }

    public function testNodeLimitDoesNotHang(): void
    {
        $specs = array_fill(0, 24, 'RG');
        $p = $this->player($specs);
        $start = microtime(true);
        $plan = ManaPayment::plan($p, ManaCost::parse('{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}'));
        $this->assertNotNull($plan);
        $this->assertCount(24, $plan->taps);
        $this->assertSame(['R' => 12, 'G' => 12], $plan->spend);
        // Impossible (25 symboles, 24 sources, mais borne couleur non détectée) : doit rendre null sans boucler.
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}{G}')));
        $this->assertNull(ManaPayment::plan($this->player(array_merge(array_fill(0, 12, 'RG'), array_fill(0, 12, 'WU'))), ManaCost::parse('{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{R}{W}{U}')));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function testSignetNeverPaysItsOwnCost(): void
    {
        $this->assertNull(ManaPayment::plan($this->player(['SIG']), ManaCost::parse('{U}')));
        $this->assertNull(ManaPayment::plan($this->player(['SIG']), ManaCost::parse('{1}')));
        $this->assertSame(0, ManaPayment::maxX($this->player(['SIG']), ManaCost::parse('{X}')));
    }

    public function testSignetConvertsALandIntoTwoColors(): void
    {
        $plan = ManaPayment::plan($this->player(['G', 'SIG']), ManaCost::parse('{U}{R}'));
        $this->assertSame(['l0'], $this->cards($plan));
        $this->assertSame([['card' => 'a1', 'ability' => 0, 'option' => 0, 'spend' => ['G' => 1]]], $plan->converters);
        $this->assertSame(['U' => 1, 'R' => 1], $plan->spend);
        $this->assertSame(2, ManaPayment::maxX($this->player(['G', 'SIG']), ManaCost::parse('{X}')));
    }

    public function testSignetIsOnlyUsedWhenItDoesStrictlyBetter(): void
    {
        $plan = ManaPayment::plan($this->player(['R', 'U', 'SIG']), ManaCost::parse('{U}'));
        $this->assertSame(['l1'], $this->cards($plan));
        $this->assertSame([], $plan->converters);
        // {2} : deux terrains ou terrain + Signet, à égalité (2 engagements, rien de perdu) : pas de Signet.
        $this->assertSame([], ManaPayment::plan($this->player(['G', 'G', 'SIG']), ManaCost::parse('{2}'))->converters);
        // {2}{U}{R} avec trois terrains : impossible sans le Signet.
        $plan = ManaPayment::plan($this->player(['G', 'G', 'U', 'SIG']), ManaCost::parse('{1}{U}{R}{G}'));
        $this->assertNotNull($plan);
        $this->assertCount(1, $plan->converters);
    }

    public function testSignetCostIsPaidFromThePoolFirst(): void
    {
        $plan = ManaPayment::plan($this->player(['SIG'], ['C' => 1]), ManaCost::parse('{U}{R}'));
        $this->assertSame([], $this->cards($plan));
        $this->assertSame(['C' => 1], $plan->converters[0]['spend']);
    }

    public function testPayWithPlanActivatesConvertersAfterSimpleSources(): void
    {
        $p = $this->player(['G', 'SIG']);
        $result = $p->payWithPlan(ManaPayment::plan($p, ManaCost::parse('{U}{R}')));
        $this->assertSame(['l0', 'a1'], $result['tapped']);
        $this->assertSame([], $result['fromPool']);
        $this->assertTrue($p->pool->isEmpty());
        $this->assertTrue($p->findOnBattlefield('a1')->tapped);
    }

    public function testRestrictedPoolManaIsRefusedForAColoredOrNonEldraziSpell(): void
    {
        $restricted = ['eldrazi' => ['C' => 2]];
        $this->assertNull(ManaPayment::plan($this->player(['G'], [], $restricted), ManaCost::parse('{1}{G}'), 0, null, null, self::bear()));
        $this->assertNull(ManaPayment::plan($this->player([], [], $restricted), ManaCost::parse('{2}'), 0, null, null, self::golem()));
        $this->assertNull(ManaPayment::plan($this->player([], [], $restricted), ManaCost::parse('{2}')), 'Sans carte payée, rien n\'est admis');
        $this->assertSame(0, ManaPayment::maxX($this->player([], [], $restricted), ManaCost::parse('{X}'), null, self::golem()));
    }

    public function testRestrictedPoolManaPaysAnEldraziAndIsSpentBeforeFreeMana(): void
    {
        $p = $this->player(['R'], ['C' => 1], ['eldrazi' => ['C' => 2]]);
        $plan = ManaPayment::plan($p, ManaCost::parse('{2}'), 0, null, null, self::mimic());
        $this->assertSame([], $plan->taps);
        $this->assertSame(['C' => 2], $plan->spend);
        $this->assertSame(['eldrazi' => ['C' => 2]], $plan->spendRestricted);

        $result = $p->payWithPlan($plan);
        $this->assertSame(['C' => 2], $result['fromPool']);
        $this->assertSame(['C' => 1], $p->pool->toArray(), 'Le mana libre reste disponible');
        $this->assertSame([], $p->pool->restrictedToArray());

        // Mana restreint et mana libre ensemble : le restreint d'abord, le libre complète.
        $p = $this->player([], ['G' => 1], ['eldrazi' => ['C' => 2]]);
        $plan = ManaPayment::plan($p, ManaCost::parse('{3}'), 0, null, null, self::mimic());
        $this->assertSame(['G' => 1, 'C' => 2], $plan->spend);
        $this->assertSame(['eldrazi' => ['C' => 2]], $plan->spendRestricted);
        $p->payWithPlan($plan);
        $this->assertTrue($p->pool->isEmpty());
    }

    public function testTempleRestrictedOptionIsChosenOnlyWhenAdmissibleAndStrictlyBetter(): void
    {
        $p = $this->player(['TEMPLE', 'R']);

        // Mimic {2} : le Temple seul ({C}{C} restreint, 1 engagement) plutôt que Temple {C} + Montagne.
        $plan = ManaPayment::plan($p, ManaCost::parse('{2}'), 0, null, null, self::mimic());
        $this->assertSame([['card' => 'l0', 'ability' => 1, 'option' => 0]], $plan->taps);
        $this->assertSame(['eldrazi' => ['C' => 2]], $plan->spendRestricted);

        // Sort non admissible : l'option restreinte n'existe pas pour lui.
        $plan = ManaPayment::plan($p, ManaCost::parse('{2}'), 0, null, null, self::golem());
        $this->assertSame(['l0', 'l1'], $this->cards($plan));
        $this->assertSame(0, $plan->taps[0]['ability']);
        $this->assertSame([], $plan->spendRestricted);

        // Mimic à {1} : l'option restreinte gaspillerait un mana ; la Montagne est préférée au Temple (source à
        // plusieurs alternatives, gardée pour plus tard).
        $plan = ManaPayment::plan($p, ManaCost::parse('{1}'), 0, null, null, self::mimic());
        $this->assertSame([['card' => 'l1', 'ability' => 0, 'option' => 0]], $plan->taps);
        $this->assertSame([], $plan->spendRestricted);
        $plan = ManaPayment::plan($this->player(['TEMPLE']), ManaCost::parse('{1}'), 0, null, null, self::mimic());
        $this->assertSame([['card' => 'l0', 'ability' => 0, 'option' => 0]], $plan->taps);

        // Le mana restreint produit en surplus reste restreint dans la réserve.
        $p = $this->player(['TEMPLE']);
        $plan = ManaPayment::plan($p, ManaCost::parse('{3}'), 0, null, null, self::mimic());
        $this->assertNull($plan);
        $this->assertSame(2, ManaPayment::maxX($p, ManaCost::parse('{X}'), null, self::mimic()));
        $this->assertSame(1, ManaPayment::maxX($p, ManaCost::parse('{X}'), null, self::golem()));
    }

    public function testTempleRestrictedOptionNeverPaysAConverterCost(): void
    {
        // Mimic {U}{R} fictif : le Signet ne peut pas être payé par le mana restreint du Temple, mais par son {C}.
        $plan = ManaPayment::plan($this->player(['TEMPLE', 'SIG']), ManaCost::parse('{U}{R}'), 0, null, null, self::mimic());
        $this->assertSame([['card' => 'l0', 'ability' => 0, 'option' => 0]], $plan->taps);
        $this->assertCount(1, $plan->converters);
        $this->assertSame([], $plan->spendRestricted);
        $this->assertNull(ManaPayment::plan($this->player(['SIG'], [], ['eldrazi' => ['C' => 1]]), ManaCost::parse('{U}{R}'), 0, null, null, self::mimic()));
    }

    public function testPoolSerializationKeepsTheRestrictedPartApart(): void
    {
        $p = $this->player(['R'], ['R' => 1]);
        $this->assertArrayNotHasKey('poolRestricted', $p->toArray(), 'Omis si rien n\'est restreint');
        $this->assertSame([], $p->pool->restrictedField());

        $p->pool->add(\App\Engine\Mana\Color::Colorless, 2, new \App\Engine\Mana\ManaRestriction('eldrazi'));
        $this->assertSame(['R' => 1], $p->pool->toArray(), 'La table libre ne change pas');
        $this->assertSame(['eldrazi' => ['C' => 2]], $p->toArray()['poolRestricted']);
        $this->assertSame(['restricted' => ['eldrazi' => ['C' => 2]]], $p->pool->restrictedField());
        $this->assertSame(['R' => 1, 'C' => 2], $p->pool->all());
        $this->assertSame(3, $p->pool->total());

        $copy = PlayerState::fromArray($p->toArray());
        $this->assertSame($p->toArray(), $copy->toArray());

        $copy->pool->clear();
        $this->assertTrue($copy->pool->isEmpty());
        $this->assertSame([], $copy->pool->restrictedToArray());
    }
}
