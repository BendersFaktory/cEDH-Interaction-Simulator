<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Coûts alternatifs (CR 118.9) : Force of Will (1 PV et exil d'une carte bleue de la main) et Fierce Guardianship
 * (gratuit si l'on contrôle un commandant).
 */
final class AlternativeCostTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function fow(): CardInstance
    {
        return new CardInstance('fow', 'Force of Will', CardInstance::INSTANT, 5, manaCost: '{3}{U}{U}', altCost: CardInstance::ALT_PITCH_BLUE,
            effects: [new SpellEffect('counter')]);
    }

    private static function fierce(): CardInstance
    {
        return new CardInstance('fg', 'Fierce Guardianship', CardInstance::INSTANT, 3, manaCost: '{2}{U}', altCost: CardInstance::ALT_COMMANDER,
            effects: [new SpellEffect('counter', target: 'noncreature')]);
    }

    private static function blue(): CardInstance
    {
        return new CardInstance('blue', 'Test Blue', CardInstance::INSTANT, 1, manaCost: '{U}', effects: [new SpellEffect('draw', 1)]);
    }

    /** Actions `CastSpell` de `$cardId` proposées à J0 (son coût alternatif éventuel dans `alt`). */
    private function casts(\App\Engine\Game\GameState $s, string $cardId): array
    {
        return array_values(array_map(
            static fn (CastSpell $a) => [$a->targets, $a->alt],
            array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof CastSpell && $a->cardId === $cardId),
        ));
    }

    public function testForceOfWillIsOfferedWithoutManaOnlyWithABlueCardToPitch(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::fow(), self::blue())->build(self::PASS);
        $s->stack[] = new StackItem('x', new CardInstance('x', 'Test Instant', CardInstance::INSTANT, 1), 1);
        $s->priority = new PriorityState(0);

        $this->assertSame([[['stack:x'], 'pitch:blue']], $this->casts($s, 'fow'), 'Seul le paiement par exil est possible : aucun terrain');
    }

    public function testPayingWithForceOfWillExilesTheBlueCardAndCostsOneLife(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::fow(), self::blue())->life(20)->build(self::PASS);
        $s->stack[] = new StackItem('x', new CardInstance('x', 'Test Instant', CardInstance::INSTANT, 1), 1);
        $s->priority = new PriorityState(0);

        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'fow', ['stack:x'], 0, 'pitch:blue'));

        $this->assertSame(19, $s->player(0)->life);
        $this->assertSame([], array_map(static fn (CardInstance $c) => $c->id, array_filter($s->player(0)->hand, static fn (CardInstance $c) => $c->id === 'blue')));
        $this->assertSame(['blue'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile));
        $this->assertContains('CardExiled', array_map(static fn ($e) => $e->type, $result->events));
    }

    public function testFierceGuardianshipIsFreeOnlyWithACommander(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::fierce())->build(self::PASS);
        $s->stack[] = new StackItem('x', new CardInstance('x', 'Test Instant', CardInstance::INSTANT, 1), 1);
        $s->priority = new PriorityState(0);
        $this->assertSame([], $this->casts($s, 'fg'), 'Sans commandant : coût de mana seulement, et aucun terrain');

        $s->player(0)->battlefield[] = new CardInstance('cmd', 'Test Commander', CardInstance::CREATURE, 3, commander: true, power: 2, toughness: 2);
        $this->assertSame([[['stack:x'], 'free']], $this->casts($s, 'fg'), 'Avec un commandant : gratuit, la cible est un sort non créature');
    }

    public function testAFreeCastSpendsNoMana(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::fierce())->build(self::PASS);
        $s->stack[] = new StackItem('x', new CardInstance('x', 'Test Instant', CardInstance::INSTANT, 1), 1);
        $s->player(0)->battlefield[] = new CardInstance('cmd', 'Test Commander', CardInstance::CREATURE, 3, commander: true, power: 2, toughness: 2);
        $s->priority = new PriorityState(0);

        (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'fg', ['stack:x'], 0, 'free'));

        $this->assertSame(0, $s->player(0)->pool->total());
        $this->assertNotContains('fg', array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->hand), 'Le sort a quitté la main');
        $this->assertContains('fg', array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->graveyard), 'Le sort a été résolu (contré ici)');
    }
}
