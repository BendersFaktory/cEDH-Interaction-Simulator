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

    private static function swanSong(string $id): CardInstance
    {
        return new CardInstance($id, 'Swan Song', CardInstance::INSTANT, 1, effects: [new SpellEffect(
            'counter', target: 'enchantment|instant|sorcery', token: 'Bird:2/2', tokenKeywords: [CardInstance::FLYING],
        )]);
    }

    public function testSwanSongTargetsEnchantmentsInstantsAndSorceriesOnly(): void
    {
        $s = (new ScenarioBuilder())->hand(1, self::swanSong('ss'))->untappedLands(1, 1)->build(self::PASS);
        $s->stack[] = new StackItem('ench', new CardInstance('ench', 'Test Enchantment', CardInstance::ENCHANTMENT, 1), 0);
        $s->stack[] = new StackItem('crea', new CardInstance('crea', 'Test Creature', CardInstance::CREATURE, 1, power: 1, toughness: 1), 0);
        $s->stack[] = new StackItem('sor', new CardInstance('sor', 'Test Sorcery', CardInstance::SORCERY, 1), 0);
        $s->priority = new PriorityState(1);

        $this->assertSame([['stack:ench'], ['stack:sor']], $this->counterTargets($s, 'ss'));
    }

    public function testSwanSongGivesTheCounteredSpellsControllerABird(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->stack[] = new StackItem('ins', new CardInstance('ins', 'Test Instant', CardInstance::INSTANT, 1), 0);
        $s->stack[] = new StackItem('ss', self::swanSong('ss'), 1, ['stack:ins']);

        (new \App\Engine\Rules\StackResolver())->resolveTop($s);

        $birds = array_values(array_filter($s->player(0)->battlefield, static fn (CardInstance $c) => $c->name === 'Bird'));
        $this->assertCount(1, $birds, 'Le contrôleur du sort contré reçoit le jeton');
        $this->assertSame([2, 2], [$birds[0]->power, $birds[0]->toughness]);
        $this->assertTrue($birds[0]->hasKeyword(CardInstance::FLYING));
        $this->assertSame([], array_filter($s->player(1)->battlefield, static fn (CardInstance $c) => $c->name === 'Bird'), 'Le contresort ne donne rien à son lanceur');
    }

    public function testRejectsAFilterOnAnEffectThatIsNotACounter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SpellEffect('damage', 2, null, CardInstance::INSTANT);
    }
}
