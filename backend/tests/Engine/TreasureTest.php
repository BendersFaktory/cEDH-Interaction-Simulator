<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Treasure pour un autre joueur ou engagé (étape 13, point 4) : « Its controller creates two Treasure tokens » (An Offer You
 * Can't Refuse : effet `treasure`, `target` = `spell_controller`), Treasure engagé d'un trigger (Tataru Taru : `spec` = `tapped`).
 */
final class TreasureTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<string> */
    private static function treasures(GameState $s, int $player): array
    {
        return array_values(array_map(static fn (CardInstance $c) => $c->id . ($c->tapped ? ':t' : ''),
            array_filter($s->player($player)->battlefield, static fn (CardInstance $c) => $c->name === 'Treasure')));
    }

    private static function offer(): CardInstance
    {
        return new CardInstance('p0_c5', 'An Offer You Can\'t Refuse', CardInstance::INSTANT, 1, manaCost: '{U}',
            effects: [new SpellEffect('counter', target: 'noncreature'), new SpellEffect('treasure', 2, target: SpellEffect::TREASURE_SPELL_CONTROLLER)]);
    }

    /** J1 a un rituel sur la pile, J0 le vise avec An Offer You Can't Refuse. */
    private function offered(): GameState
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->stack[] = new StackItem('s1', new CardInstance('p1_c3', 'Test Sorcery', CardInstance::SORCERY, 1), 1);
        $s->stack[] = new StackItem('s2', self::offer(), 0, ['stack:s1']);
        $s->drainEvents();

        return $s;
    }

    public function testTheCounteredSpellsControllerCreatesTheTreasures(): void
    {
        $s = $this->offered();
        (new StackResolver())->resolveTop($s);
        $this->assertSame([], $s->stack, 'le rituel est contré');
        $this->assertSame(['p1_t1', 'p1_t2'], self::treasures($s, 1), 'deux Treasure dégagés pour J1');
        $this->assertSame([], self::treasures($s, 0));
        $this->assertCount(2, $this->of($s->drainEvents(), 'TokenCreated'));
    }

    public function testNothingHappensWhenTheTargetIsGone(): void
    {
        $s = $this->offered();
        array_shift($s->stack); // le rituel a quitté la pile : An Offer n'a plus de cible légale
        (new StackResolver())->resolveTop($s);
        $this->assertSame([], self::treasures($s, 0));
        $this->assertSame([], self::treasures($s, 1));
    }

    public function testEffectIsSerialized(): void
    {
        $effect = new SpellEffect('treasure', 2, target: SpellEffect::TREASURE_SPELL_CONTROLLER);
        $this->assertSame(['effect' => 'treasure', 'amount' => 2, 'target' => 'spell_controller'], $effect->toArray());
        $this->assertEquals($effect, SpellEffect::fromArray($effect->toArray()));
        $this->expectException(\InvalidArgumentException::class);
        new SpellEffect('treasure', target: 'creature');
    }

    public function testTriggerCreatesTappedTreasures(): void
    {
        $trigger = new TriggeredAbility(TriggeredAbility::OPPONENT_DRAW, 'treasure', 1, TriggeredAbility::TREASURE_TAPPED);
        $this->assertSame('1 Treasure engagé', substr($trigger->label(), -strlen('1 Treasure engagé')));
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->stack[] = new StackItem('s1', new CardInstance('p0_c4', 'Tataru Taru', CardInstance::CREATURE, 2, power: 0, toughness: 3), 0, [], 0, null, 0, $trigger);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['p0_t1:t'], self::treasures($s, 0));
        $this->assertTrue($this->of($s->drainEvents(), 'TokenCreated')[0]->payload['tapped']);

        $this->expectException(\InvalidArgumentException::class);
        new TriggeredAbility(TriggeredAbility::ETB, 'treasure', 1, 'untapped');
    }
}
