<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Sorts à plusieurs cibles (CR 115.1, 608.2b) : une cible par effet ciblé, résolution partielle si une cible devient illégale,
 * sort sans effet si toutes le deviennent. Aucune carte de deck n'a encore plusieurs cibles : cartes de test uniquement.
 */
final class MultiTargetTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** Deux « cible joueur » : 2 dégâts au premier, 1 au second (deux mots « cible » distincts). */
    private static function twoDamage(string $id, bool $withDraw = false): CardInstance
    {
        $effects = [new SpellEffect('damage', 2), new SpellEffect('damage', 1)];
        if ($withDraw) {
            $effects[] = new SpellEffect('draw', 1);
        }

        return new CardInstance($id, 'Test Double Bolt', CardInstance::INSTANT, 1, effects: $effects);
    }

    /** @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    public function testATargetedEffectPerTargetSoAllCombinationsAreOffered(): void
    {
        $s = (new ScenarioBuilder())->hand(1, self::twoDamage('db'))->untappedLands(1, 1)->build(self::PASS);
        $s->priority = new PriorityState(1);

        $targets = [];
        foreach ((new LegalActionGenerator())->for($s, 1) as $a) {
            if ($a instanceof CastSpell && $a->cardId === 'db') {
                $targets[] = $a->targets;
            }
        }

        $expected = [];
        foreach ([0, 1, 2] as $first) {
            foreach ([0, 1, 2] as $second) {
                $expected[] = ["player:$first", "player:$second"];
            }
        }
        sort($targets);
        sort($expected);
        $this->assertSame($expected, $targets, '3 joueurs, 2 cibles : 9 combinaisons, le même joueur pouvant être visé deux fois');
    }

    public function testTwoTargetedEffectsAreAcceptedOnACard(): void
    {
        $card = self::twoDamage('db');

        $this->assertCount(2, $card->targetedEffects());
    }

    public function testPartiallyIllegalTargetsStillResolveTheOthers(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(2)->lost = true; // la deuxième cible est partie
        $before = $s->player(0)->life;
        $s->stack[] = new StackItem('s1', self::twoDamage('db'), 1, ['player:0', 'player:2']);

        (new StackResolver())->resolveTop($s);

        $events = $s->drainEvents();
        $this->assertSame($before - 2, $s->player(0)->life, 'La cible légale reçoit ses dégâts (CR 608.2b)');
        $this->assertCount(1, $this->of($events, 'DamageDealt'));
        $this->assertSame(['target' => 'player:2'], $this->of($events, 'SpellFizzled')[0]->payload);
    }

    public function testPartiallyIllegalTargetsStillResolveTheNonTargetedEffects(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(2)->lost = true;
        $handBefore = count($s->player(1)->hand);
        $s->stack[] = new StackItem('s1', self::twoDamage('db', withDraw: true), 1, ['player:0', 'player:2']);

        (new StackResolver())->resolveTop($s);

        $this->assertSame($handBefore + 1, count($s->player(1)->hand), 'Le sort se résout : sa pioche aussi');
    }

    public function testAllTargetsIllegalDoesNothingIncludingNonTargetedEffects(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(0)->lost = true;
        $s->player(2)->lost = true;
        $life = $s->player(1)->life;
        $handBefore = count($s->player(1)->hand);
        $s->stack[] = new StackItem('s1', self::twoDamage('db', withDraw: true), 1, ['player:0', 'player:2']);

        (new StackResolver())->resolveTop($s);

        $events = $s->drainEvents();
        $this->assertCount(2, $this->of($events, 'SpellFizzled'), 'Une cible illégale = un SpellFizzled, deux ici');
        $this->assertCount(0, $this->of($events, 'DamageDealt'));
        $this->assertSame($handBefore, count($s->player(1)->hand), 'Toutes les cibles illégales : pas de pioche (CR 608.2b)');
        $this->assertSame($life, $s->player(1)->life);
    }
}
