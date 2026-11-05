<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\ReplaceChoice;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\CardDefinition;
use App\Engine\Card\ReplacementEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\AdvanceResult;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Effets de remplacement (CR 614), première carte : Mox Diamond. « If this artifact would enter, you may discard a land card
 * instead. If you do, put this artifact onto the battlefield. If you don't, put it into its owner's graveyard. »
 */
final class ReplacementEffectTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function mox(string $id = 'mox'): CardInstance
    {
        return self::def('Mox Diamond')->instantiate($id);
    }

    private static function land(string $id, string $name, string $color): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
    }

    /** @return list<Action> */
    private function legal(GameState $s, int $player = 0): array
    {
        return (new LegalActionGenerator())->for($s, $player);
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<string> */
    private static function ids(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->id, $cards);
    }

    /** Lance Mox Diamond (J0) et passe la priorité jusqu'à sa résolution (ou la décision de remplacement). */
    private function castMox(GameState $s, GameEngine $engine): AdvanceResult
    {
        $cast = array_values(array_filter($this->legal($s), static fn (Action $a) => $a instanceof CastSpell && $a->cardId === 'mox'));
        self::assertCount(1, $cast, 'Mox Diamond se lance même sans terrain en main');
        $r = $engine->advance($s, $cast[0]);
        $events = $r->events;
        for ($i = 0; $i < 20 && $s->stack !== [] && $r->pending !== null && $s->selection === null; $i++) {
            $r = $engine->advance($s, new PassPriority($r->pending->player));
            $events = [...$events, ...$r->events];
        }

        return new AdvanceResult($events, $r->pending, $r->over);
    }

    public function testMoxDiamondIsMappedWithItsReplacementAndRoundTrips(): void
    {
        $def = self::def('Mox Diamond');
        self::assertEquals([new ReplacementEffect(ReplacementEffect::ENTERS, ReplacementEffect::DISCARD_LAND)], $def->replacements);
        self::assertCount(1, $def->abilities, '{T}: Add one mana of any color.');
        self::assertEquals($def, CardDefinition::fromArray($def->toArray()));
        $mox = self::mox();
        self::assertEquals($mox->replacements, CardInstance::fromArray(json_decode(json_encode($mox->toArray()), true))->replacements);
        self::assertArrayNotHasKey('replacements', self::def('Sol Ring')->instantiate('ring')->toArray(), 'Omis quand vide');
    }

    public function testDiscardingALandPutsMoxDiamondOntoTheBattlefield(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mox(), self::land('i1', 'Island', 'U'), self::land('f1', 'Forest', 'G'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $r = $this->castMox($s, $engine);
        self::assertSame('replaced', $this->of($r->events, 'SpellResolved')[0]->payload['destination']);
        self::assertSame(['kind' => 'replace', 'player' => 0], ['kind' => $s->selection['kind'], 'player' => $r->pending->player]);
        $choices = $this->legal($s);
        self::assertSame(['replace:0:decline', 'replace:0:i1', 'replace:0:f1'], array_map(static fn (Action $a) => $a->id(), $choices));
        self::assertSame('Défausser Island (Mox Diamond arrive sur le champ de bataille)', $choices[1]->describe($s));
        self::assertSame('Ne rien défausser (Mox Diamond va au cimetière)', $choices[0]->describe($s));

        $r = $engine->advance($s, $choices[1]);
        self::assertNull($s->selection);
        self::assertSame(['mox'], self::ids($s->player(0)->battlefield));
        self::assertSame(['i1'], self::ids($s->player(0)->graveyard));
        self::assertSame(['f1'], self::ids($s->player(0)->hand));
        self::assertCount(1, $this->of($r->events, 'CardDiscarded'));
        $applied = $this->of($r->events, 'ReplacementApplied')[0]->payload;
        self::assertSame(['battlefield', 'enters', 'Island', 'Mox Diamond'], [$applied['destination'], $applied['event'], $applied['discarded']['name'], $applied['card']['name']]);
        self::assertSame($s->turn, $s->player(0)->battlefield[0]->enteredTurn);
    }

    public function testDecliningPutsMoxDiamondIntoTheGraveyard(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mox(), self::land('i1', 'Island', 'U'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $this->castMox($s, $engine);
        $r = $engine->advance($s, new ReplaceChoice(0));
        self::assertSame([], $s->player(0)->battlefield);
        self::assertSame(['mox'], self::ids($s->player(0)->graveyard));
        self::assertSame(['i1'], self::ids($s->player(0)->hand));
        self::assertSame('graveyard', $this->of($r->events, 'ReplacementApplied')[0]->payload['destination']);
        self::assertSame([], $this->of($r->events, 'CardDiscarded'));
    }

    public function testWithoutALandInHandMoxDiamondGoesStraightToTheGraveyard(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mox())->build(self::J0_HUMAN);
        $r = $this->castMox($s, new GameEngine(new AgentRegistry()));
        self::assertNull($s->selection, 'Rien à choisir');
        self::assertSame(['mox'], self::ids($s->player(0)->graveyard));
        $applied = $this->of($r->events, 'ReplacementApplied');
        self::assertCount(1, $applied);
        self::assertArrayNotHasKey('discarded', $applied[0]->payload);
    }

    public function testStateRoundTripsDuringTheReplacementDecision(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::mox(), self::land('i1', 'Island', 'U'))->build(self::J0_HUMAN);
        $this->castMox($s, new GameEngine(new AgentRegistry()));
        $copy = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
        self::assertSame($s->selection, $copy->selection);
        self::assertSame(array_map(static fn (Action $a) => $a->id(), $this->legal($s)), array_map(static fn (Action $a) => $a->id(), $this->legal($copy)));
        (new GameEngine(new AgentRegistry()))->advance($copy, new ReplaceChoice(0, 'i1'));
        self::assertSame(['mox'], self::ids($copy->player(0)->battlefield));
        self::assertSame(['i1'], self::ids($copy->player(0)->graveyard));
    }

    public function testAiCastsMoxDiamondOnlyWithALandToSpareAndDiscardsADuplicate(): void
    {
        $ai = new HeuristicAgent();
        $cast = new CastSpell(0, 'mox');
        $s = (new ScenarioBuilder())->hand(0, self::mox(), self::land('i1', 'Island', 'U'))->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        self::assertNotInstanceOf(CastSpell::class, $ai->decide($s, 0, [$cast, new PassPriority(0)]), 'Un seul terrain, aucun en jeu : garder le terrain');
        $s = (new ScenarioBuilder())->hand(0, self::mox(), self::land('i1', 'Island', 'U'), self::land('f1', 'Forest', 'G'), self::land('i2', 'Island', 'U'))
            ->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        self::assertInstanceOf(CastSpell::class, $ai->decide($s, 0, [$cast, new PassPriority(0)]));

        $s->selection = ['player' => 0, 'kind' => 'replace', 'count' => 1, 'source' => 'Mox Diamond', 'draw' => 0, 'card' => self::mox()->toArray()];
        $choice = $ai->decide($s, 0, $this->legal($s));
        self::assertInstanceOf(ReplaceChoice::class, $choice);
        self::assertSame('i1', $choice->discardId, 'Island en double plutôt que Forest');
    }

    public function testFourAiGameWithMoxDiamondPlaysToTheEnd(): void
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach (['Mox Diamond', 'Lightning Bolt', 'Sol Ring', 'Counterspell', 'Brainstorm', 'Command Tower', 'Wall of Omens', 'Serra Angel'] as $name) {
            $library[] = $m->map($f[$name]);
        }
        foreach (['Plains', 'Island', 'Swamp'] as $basic) {
            for ($i = 0; $i < 30; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }
        $deck = new LoadedDeck($m->map($f['Kraum, Ludevic\'s Opus']), array_slice($library, 0, 98), partner: $m->map($f['Tymna the Weaver']));

        $applied = [];
        foreach (range(1, 8) as $seed) {
            $state = GameFactory::create($seed, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));
            $events = (new GameEngine(new AgentRegistry()))->advance($state)->events;
            self::assertSame('GameOver', $events[array_key_last($events)]->type, "graine $seed");
            foreach ($this->of($events, 'ReplacementApplied') as $e) {
                $applied[] = $e->payload['destination'];
            }
        }
        self::assertContains('battlefield', $applied, 'Une IA a défaussé un terrain pour Mox Diamond');
    }
}
