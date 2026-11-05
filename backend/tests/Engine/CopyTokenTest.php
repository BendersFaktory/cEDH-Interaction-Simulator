<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\Decks;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/** Effet de sort `copy` : jeton copie d'une créature qu'on contrôle (CR 707.2), règle des légendes sur la copie du commandant. */
final class CopyTokenTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function mirror(string $id): CardInstance
    {
        return new CardInstance($id, 'Reflet de Légende', CardInstance::INSTANT, 3, 'copy');
    }

    /** @param list<GameEvent> $events @return list<string> */
    private function types(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->type, $events);
    }

    public function testTargetsAreOnlyCreaturesTheCasterControls(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mirror('m'))->untappedLands(0, 3)
            ->creature(0, 'mine', 2, 2)->creature(1, 'theirs', 2, 2)->build(self::PASS);

        $targets = [];
        foreach ((new LegalActionGenerator())->for($s, 0) as $a) {
            if ($a instanceof CastSpell && $a->cardId === 'm') {
                $targets[] = $a->targets;
            }
        }
        $this->assertSame([['permanent:mine']], $targets);
    }

    public function testNoCreatureMeansNoCast(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mirror('m'))->untappedLands(0, 3)->creature(1, 'theirs', 2, 2)->build(self::PASS);
        foreach ((new LegalActionGenerator())->for($s, 0) as $a) {
            $this->assertFalse($a instanceof CastSpell && $a->cardId === 'm');
        }
    }

    public function testCopyKeepsCopiableValuesAndNotCommanderStatus(): void
    {
        $commander = ScenarioBuilder::commanderCard('cmd');
        $commander->damage = 1;
        $commander->powerBonus = 2;
        $s = (new ScenarioBuilder())->permanent(0, $commander)->build(self::PASS);
        $s->stack[] = new StackItem('s1', self::mirror('m'), 0, ['permanent:cmd']);

        (new StackResolver())->resolveTop($s);
        $events = $s->drainEvents();
        $this->assertSame(['SpellResolved', 'TokenCreated'], $this->types($events));
        $payload = $events[1]->payload;
        $this->assertSame('cmd', $payload['copyOf']);
        $copy = $s->player(0)->findOnBattlefield($payload['card']['id']);
        $this->assertNotNull($copy);
        $this->assertTrue($copy->token);
        $this->assertFalse($copy->commander);
        $this->assertSame($commander->name, $copy->name);
        $this->assertSame($commander->supertypes, $copy->supertypes);
        $this->assertSame([$commander->power, $commander->toughness], [$copy->power, $copy->toughness]);
        $this->assertSame([0, 0, 0], [$copy->damage, $copy->powerBonus, $copy->toughnessBonus], 'ni dégâts ni bonus copiés');
    }

    public function testTargetGoneOrNotControlledFizzles(): void
    {
        $s = (new ScenarioBuilder())->creature(1, 'theirs', 2, 2)->build(self::PASS);
        $s->stack[] = new StackItem('s1', self::mirror('m1'), 0, ['permanent:theirs']);
        $s->stack[] = new StackItem('s2', self::mirror('m2'), 0, ['permanent:gone']);
        (new StackResolver())->resolveTop($s);
        (new StackResolver())->resolveTop($s);
        $events = $s->drainEvents();
        $this->assertSame(['SpellResolved', 'SpellFizzled', 'SpellResolved', 'SpellFizzled'], $this->types($events));
        $this->assertSame([], $s->player(0)->battlefield);
    }

    public function testCopyOfALegendTriggersTheLegendRule(): void
    {
        $legend = new CardInstance('l1', 'Isamaru', CardInstance::CREATURE, 1, null, 0, false, false, 2, 2, manaCost: '{W}', supertypes: [CardInstance::LEGENDARY]);
        $s = (new ScenarioBuilder())->permanent(0, $legend)->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->stack[] = new StackItem('s1', self::mirror('m'), 0, ['permanent:l1']);
        (new StackResolver())->resolveTop($s);
        $s->drainEvents();

        (new StateBasedActions(new AgentRegistry(), new LegalActionGenerator()))->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['LegendRuleApplied'], $this->types($events));
        $this->assertCount(1, array_filter($s->player(0)->battlefield, static fn (CardInstance $c) => $c->name === 'Isamaru'));
    }

    public function testDemoDecksStayValidWithTheMirror(): void
    {
        for ($p = 0; $p < 4; $p++) {
            $deck = Decks::commanderDemo($p);
            Decks::validate($deck, $p);
            $this->assertContains('Reflet de Légende', array_map(static fn (CardInstance $c) => $c->name, $deck['library']));
        }
    }
}
