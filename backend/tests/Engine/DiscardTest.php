<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Discard;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use PHPUnit\Framework\TestCase;

/** Défausse à 7 cartes au cleanup : décision du joueur actif, hors priorité. */
final class DiscardTest extends TestCase
{
    /** @return list<CardInstance> */
    private function spells(int $n): array
    {
        $cards = [];
        for ($i = 1; $i <= $n; $i++) {
            $cards[] = new CardInstance("h$i", "Spell$i", CardInstance::SORCERY, $i);
        }

        return $cards;
    }

    /** @param array<int, string> $controllers */
    private function atCleanup(int $handSize, array $controllers, ?ScenarioBuilder $b = null): GameState
    {
        $s = ($b ?? (new ScenarioBuilder())->hand(0, ...$this->spells($handSize)))->build($controllers);
        $s->phase = Phase::Cleanup;

        return $s;
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    public function testHumanDiscardsDownToSevenOneCardAtATime(): void
    {
        $s = $this->atCleanup(9, [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $engine = new GameEngine(new AgentRegistry());

        $r = $engine->advance($s);
        $req = $r->pending->toArray();
        $this->assertSame(0, $req['priority']['holder']);
        $this->assertSame('cleanup', $req['phase']);
        $this->assertSame(array_fill(0, 9, 'discard'), array_column($req['actions'], 'type'));

        $r = $engine->advance($s, new Discard(0, 'h3'));
        $this->assertSame(['CardDiscarded'], array_map(static fn ($e) => $e->type, $this->of($r->events, 'CardDiscarded')));
        $this->assertSame(8, $this->of($r->events, 'CardDiscarded')[0]->payload['hand']);
        $this->assertSame(array_fill(0, 8, 'discard'), array_column($r->pending->toArray()['actions'], 'type'));

        $r = $engine->advance($s, new Discard(0, 'h5'));
        $this->assertSame(7, $this->of($r->events, 'CardDiscarded')[0]->payload['hand']);
        $this->assertCount(7, $s->player(0)->hand);
        $this->assertSame(['Spell3', 'Spell5'], array_map(static fn ($c) => $c->name, $s->player(0)->graveyard));
        // Plus de défausse : la partie continue (le joueur humain reçoit la priorité plus loin).
        $this->assertNotSame('cleanup', $r->pending?->toArray()['phase'] ?? null);
    }

    public function testNoDiscardWhenHandIsSevenOrLess(): void
    {
        $s = $this->atCleanup(7, [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $this->assertSame([], $this->beforeSecondTurn($result->events, 'CardDiscarded'));
    }

    public function testOnlyTheActivePlayerDiscards(): void
    {
        $b = (new ScenarioBuilder())->hand(0, ...$this->spells(3))->hand(1, ...$this->spells(9));
        $s = $this->atCleanup(0, [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'], $b);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $this->assertSame([], $this->beforeSecondTurn($result->events, 'CardDiscarded'));
    }

    public function testAutoPassAgentStillDiscardsDownToSeven(): void
    {
        $s = $this->atCleanup(9, [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $discards = $this->beforeSecondTurn($result->events, 'CardDiscarded');
        $this->assertSame([8, 7], array_map(static fn ($e) => $e->payload['hand'], $discards));
        $this->assertSame(['Spell1', 'Spell2'], array_map(static fn ($e) => $e->payload['card']['name'], $discards));
    }

    public function testHeuristicDiscardsSurplusLandsThenTheMostExpensiveCard(): void
    {
        $hand = [
            new CardInstance('l1', 'Land', CardInstance::LAND),
            new CardInstance('l2', 'Land', CardInstance::LAND),
            ...$this->spells(7),
        ];
        $mk = fn () => $this->atCleanup(0, [0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'],
            (new ScenarioBuilder())->hand(0, ...$hand)->untappedLands(0, 5));

        $a = (new GameEngine(new AgentRegistry()))->advance($mk());
        $b = (new GameEngine(new AgentRegistry()))->advance($mk());

        $discards = $this->beforeSecondTurn($a->events, 'CardDiscarded');
        $this->assertSame(['l1', 'l2'], array_map(static fn ($e) => $e->payload['card']['id'], $discards));
        $this->assertSame(
            array_map(static fn ($e) => $e->toArray(), $a->events),
            array_map(static fn ($e) => $e->toArray(), $b->events),
            'déterministe',
        );

        // Sans terrain en surplus, la carte la plus chère part en premier.
        $s = $this->atCleanup(8, [0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $r = (new GameEngine(new AgentRegistry()))->advance($s);
        $this->assertSame('Spell8', $this->beforeSecondTurn($r->events, 'CardDiscarded')[0]->payload['card']['name']);
    }

    public function testTheServerRefusesAnIllegalDiscard(): void
    {
        $s = $this->atCleanup(9, [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $this->expectException(IllegalActionException::class);
        $engine->advance($s, new Discard(0, 'inconnue'));
    }

    public function testCleanupIsFollowedByTheNextPlayersUntap(): void
    {
        $s = $this->atCleanup(9, [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s);

        $phases = array_map(static fn ($e) => $e->payload['phase'] . '@' . $e->payload['turn'], array_slice($this->of($result->events, 'PhaseChanged'), 0, 2));
        $this->assertSame(['untap@2', 'upkeep@2'], $phases);
    }

    /**
     * Événements d'un type produits avant le tour suivant celui du joueur 0 (là, ai:pass accumule d'autres cartes).
     *
     * @param list<GameEvent> $events
     * @return list<GameEvent>
     */
    private function beforeSecondTurn(array $events, string $type): array
    {
        $out = [];
        foreach ($events as $e) {
            if ($e->type === 'PhaseChanged' && $e->payload['turn'] >= 2 && $e->payload['phase'] === 'cleanup') {
                break;
            }
            if ($e->type === $type) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
