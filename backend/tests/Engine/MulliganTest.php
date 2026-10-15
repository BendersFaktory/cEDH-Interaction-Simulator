<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\BottomCard;
use App\Engine\Action\KeepHand;
use App\Engine\Action\Mulligan;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Mulligan de Londres (CR 103.5), premier mulligan gratuit en multijoueur (CR 103.5c). */
final class MulliganTest extends TestCase
{
    private const HUMAN_J0 = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<string> */
    private function types(?\App\Engine\Decision\DecisionRequest $pending): array
    {
        return array_column($pending?->toArray()['actions'] ?? [], 'type');
    }

    private function land(string $id): CardInstance
    {
        return new CardInstance($id, 'Mountain', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['R']);
    }

    private function spell(string $id, int $cost, ?string $effect = null): CardInstance
    {
        return new CardInstance($id, "Spell$id", $effect === 'counter' ? CardInstance::INSTANT : CardInstance::SORCERY, $cost, $effect);
    }

    public function testFactoryOpensThePregameAndTheHumanDecidesBeforeAnyPriority(): void
    {
        $s = GameFactory::create(1, self::HUMAN_J0);
        $this->assertSame([0, 1, 2, 3], array_keys($s->mulligan));
        $this->assertSame(['taken' => 0, 'kept' => false], $s->mulligan[0]);

        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertSame(0, $r->pending->player);
        $this->assertSame(['keep', 'mulligan'], $this->types($r->pending));
        $this->assertSame(['keep:0', 'mulligan:0'], array_column($r->pending->toArray()['actions'], 'id'));
        $this->assertSame(0, $s->priority->holder, 'Le décideur occupe priority->holder (serveur et rejeu)');
        $this->assertSame([], $this->of($r->events, 'PriorityGranted'), 'Aucune priorité (ni checkpoint) avant la fin des mulligans');
    }

    public function testFirstMulliganIsFreeThenTheSecondCostsOneCard(): void
    {
        $s = GameFactory::create(1, self::HUMAN_J0);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $library = count($s->player(0)->library);

        // Premier mulligan : main dans la bibliothèque, mélange, 7 nouvelles cartes.
        $r = $engine->advance($s, new Mulligan(0));
        $taken = $this->of($r->events, 'MulliganTaken');
        $this->assertCount(1, $taken);
        $this->assertSame(['player' => 0, 'mulligans' => 1, 'hand' => 0, 'library' => $library + 7], $taken[0]->payload);
        $this->assertCount(7, $this->of($r->events, 'CardDrawn'));
        $this->assertCount(7, $s->player(0)->hand);
        $this->assertSame(['keep', 'mulligan'], $this->types($r->pending));

        // Garder après un seul mulligan : rien au fond.
        $kept = GameState::fromArray($s->toArray());
        $rk = $engine->advance($kept, new KeepHand(0));
        $this->assertSame(['player' => 0, 'mulligans' => 1, 'toBottom' => 0], $this->of($rk->events, 'HandKept')[0]->payload);
        $this->assertSame([], $this->of($rk->events, 'CardPutOnBottom'));
        $this->assertNull($kept->mulligan);

        // Second mulligan puis garder : une carte au fond, une action par carte de la main.
        $engine->advance($s, new Mulligan(0));
        $r = $engine->advance($s, new KeepHand(0));
        $this->assertSame(['player' => 0, 'mulligans' => 2, 'toBottom' => 1], $this->of($r->events, 'HandKept')[0]->payload);
        $this->assertSame(array_fill(0, 7, 'bottom'), $this->types($r->pending));
        $this->assertSame([], $this->of($r->events, 'PriorityGranted'));

        $card = $s->player(0)->hand[2];
        $before = count($s->player(0)->library);
        $r = $engine->advance($s, new BottomCard(0, $card->id));
        $bottom = $this->of($r->events, 'CardPutOnBottom');
        $this->assertSame(['player' => 0, 'card' => $card->toPublic(), 'hand' => 6, 'library' => $before + 1], $bottom[0]->payload);
        $this->assertSame($card->id, end($s->player(0)->library)->id, 'Au fond de la bibliothèque');
        $this->assertCount(6, $s->player(0)->hand);
        $this->assertNull($s->mulligan, 'Avant-partie terminée');
        $this->assertNotEmpty($this->of($r->events, 'PriorityGranted'), 'La partie commence');
    }

    public function testIllegalPregameDecisionsAreRejected(): void
    {
        $s = GameFactory::create(1, self::HUMAN_J0);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $snapshot = $s->toArray();
        try {
            $engine->advance($s, new BottomCard(0, $s->player(0)->hand[0]->id));
            $this->fail('Rien à mettre au fond avant de garder');
        } catch (IllegalActionException) {
        }

        // Après 7 mulligans, seul « garder » reste proposé (main de 0 carte après la mise au fond).
        $s = GameState::fromArray($snapshot);
        $s->mulligan[0]['taken'] = 7;
        $this->assertSame(['keep:0'], array_map(static fn (Action $a) => $a->id(), (new LegalActionGenerator())->for($s, 0)));
        $s->mulligan[0]['kept'] = true;
        $this->assertCount(7, (new LegalActionGenerator())->for($s, 0), 'Six cartes au fond, une par une');
    }

    public function testDecisionsFollowTurnOrderFromTheFirstPlayer(): void
    {
        $s = GameFactory::create(5, [0 => 'human', 1 => 'human', 2 => 'human', 3 => 'human']);
        $engine = new GameEngine(new AgentRegistry());
        $order = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $engine->advance($s, $i === 0 ? null : new KeepHand(end($order)));
            if ($s->mulligan === null) {
                break;
            }
            $order[] = $r->pending->player;
        }
        $first = $s->activePlayer;
        $this->assertSame([$first, ($first + 1) % 4, ($first + 2) % 4, ($first + 3) % 4], $order);
    }

    public function testAutoPassingHumanNeverDecidesAMulligan(): void
    {
        $s = GameFactory::create(1, [0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->setStops(0, \App\Engine\Game\StopConfig::fromArray(['steps' => [], 'onOpponentSpell' => false, 'onDeclareAttackers' => false, 'onDeclareBlockers' => false]));
        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertSame(['keep', 'mulligan'], $this->types($r->pending), 'Même sans aucun stop');
    }

    public function testHeuristicKeepsTwoToFiveLands(): void
    {
        $ai = new HeuristicAgent();
        foreach ([[0, 0, 'mulligan'], [1, 0, 'mulligan'], [2, 0, 'keep'], [5, 0, 'keep'], [6, 1, 'mulligan'], [7, 0, 'mulligan'], [0, 2, 'keep'], [7, 2, 'keep']] as [$lands, $taken, $expected]) {
            $s = GameFactory::create(1, self::FOUR_AI);
            $hand = [];
            for ($i = 0; $i < 7; $i++) {
                $hand[] = $i < $lands ? $this->land("l$i") : $this->spell("s$i", 3);
            }
            $s->player(0)->hand = $hand;
            $s->mulligan[0]['taken'] = $taken;
            $action = $ai->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
            $this->assertSame($expected, $action->toArray()['type'], "$lands terrains, $taken mulligan(s)");
        }
        foreach (['ai:aggro', 'ai:control'] as $profile) {
            $s = GameFactory::create(1, self::FOUR_AI);
            $s->player(0)->hand = array_map(fn (int $i) => $this->spell("s$i", 3), range(0, 6));
            $this->assertInstanceOf(Mulligan::class, (new AgentRegistry())->get($profile)->decide($s, 0, (new LegalActionGenerator())->for($s, 0)));
        }
    }

    public function testHeuristicBottomsLikeADiscard(): void
    {
        $ai = new HeuristicAgent();
        $s = GameFactory::create(1, self::FOUR_AI);
        $s->mulligan[0] = ['taken' => 3, 'kept' => true]; // deux cartes au fond
        $s->player(0)->hand = [$this->land('l1'), $this->land('l2'), $this->land('l3'), $this->spell('cheap', 1),
            $this->spell('big', 5), $this->spell('cs', 6, 'counter'), $this->spell('mid', 3)];

        $first = $ai->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertSame('bottom:0:big', $first->id(), 'La plus chère, le contresort est gardé, les terrains aussi');

        // Main inondée : un terrain en surplus part au fond.
        $s->player(0)->hand = [$this->land('l1'), $this->land('l2'), $this->land('l3'), $this->land('l4'), $this->land('l5'),
            $this->spell('a', 2), $this->spell('b', 4)];
        $this->assertInstanceOf(BottomCard::class, $pick = $ai->decide($s, 0, (new LegalActionGenerator())->for($s, 0)));
        $this->assertStringStartsWith('bottom:0:l', $pick->id());
    }

    public function testSerializedMidMulliganResumesIdentically(): void
    {
        $s = GameFactory::create(3, self::HUMAN_J0);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $engine->advance($s, new Mulligan(0));
        $engine->advance($s, new Mulligan(0));

        $copy = GameState::fromArray(json_decode(json_encode($s->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(['taken' => 2, 'kept' => false], $copy->mulligan[0]);
        $this->assertSame(json_encode($s->toArray()), json_encode($copy->toArray()));

        $card = $s->player(0)->hand[0]->id;
        $continue = static function (GameState $g) use ($engine, $card): string {
            $events = [...$engine->advance($g, new KeepHand(0))->events, ...$engine->advance($g, new BottomCard(0, $card))->events];

            return json_encode([$g->toArray(), array_map(static fn (GameEvent $e) => [$e->seq, $e->type, $e->payload], $events)]);
        };
        $this->assertSame($continue($s), $continue($copy));
        $this->assertArrayNotHasKey('mulligan', $s->toArray(), 'Clé omise une fois l\'avant-partie finie');
    }

    public function testFourAiGamesAreDeterministicAndSomeMulligan(): void
    {
        // Graine 373 : au moins une IA prend un mulligan.
        $run = static function (): array {
            $s = GameFactory::create(373, self::FOUR_AI);
            $r = (new GameEngine(new AgentRegistry()))->advance($s);

            return [json_encode($s->toArray()), array_map(static fn (GameEvent $e) => [$e->type, $e->payload], $r->events)];
        };
        [$state1, $events1] = $run();
        [$state2, $events2] = $run();
        $this->assertSame($state1, $state2);
        $this->assertSame($events1, $events2);

        $types = array_column($events1, 0);
        $this->assertContains('MulliganTaken', $types);
        $this->assertSame(4, count(array_keys($types, 'HandKept', true)));
        $firstPriority = array_search('PriorityGranted', $types, true);
        $lastKept = max(array_keys($types, 'HandKept', true));
        $this->assertLessThan($firstPriority, $lastKept, 'Toutes les mains gardées avant la première priorité');
    }
}
