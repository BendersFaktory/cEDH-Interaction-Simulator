<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ResolutionChoice;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\ComboPlanner;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Service\Deck\CardMapper;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/** Étape 16 : IA de combo (`ComboPlanner`) et choix des cartes exilées par escape (`CastSpell::$exiles`). */
final class ComboPlannerTest extends TestCase
{
    /** J0 joue l'IA ordinaire (qui interroge `ComboPlanner`), les autres passent toujours. */
    private const AI = [0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function card(string $name, string $id): CardInstance
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name])->instantiate($id);
    }

    private static function land(string $id, string $color): CardInstance
    {
        return new CardInstance($id, 'Land ' . $color, CardInstance::LAND, produces: [$color]);
    }

    private static function filler(string $id, int $mv = 2): CardInstance
    {
        return new CardInstance($id, 'Filler ' . $id, CardInstance::SORCERY, $mv, manaCost: '{' . $mv . '}');
    }

    /** Bibliothèque de `$player` : `$n` cartes distinctes, `$extra` glissées au milieu. */
    private static function library(GameState $s, int $player, int $n, CardInstance ...$extra): void
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = self::filler("p{$player}_l$i");
        }
        array_splice($cards, intdiv($n, 2), 0, $extra);
        $s->player($player)->library = $cards;
    }

    /** @param list<GameEvent> $events */
    private static function gameOver(array $events): ?array
    {
        foreach ($events as $e) {
            if ($e->type === 'GameOver') {
                return $e->payload;
            }
        }

        return null;
    }

    /** @param list<GameEvent> $events @return list<string> */
    private static function cast(array $events): array
    {
        return array_values(array_map(static fn (GameEvent $e) => (string) $e->payload['card']['name'], array_filter($events, static fn (GameEvent $e) => $e->type === 'SpellCast')));
    }

    /** @return list<Action> */
    private static function legal(GameState $s, int $player = 0): array
    {
        return (new LegalActionGenerator())->for($s, $player);
    }

    public function testOracleThenConsultationInResponseWins(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card("Thassa's Oracle", 'p0_c1'), self::card('Demonic Consultation', 'p0_c2'))
            ->permanent(0, self::land('p0_u1', 'U'))->permanent(0, self::land('p0_u2', 'U'))->permanent(0, self::land('p0_b', 'B'))->build(self::AI);
        self::library($s, 0, 40);

        $events = (new GameEngine(new AgentRegistry()))->advance($s)->events;

        self::assertSame(['winner' => 0, 'reason' => 'won', 'source' => "Thassa's Oracle"], self::gameOver($events));
        self::assertSame(["Thassa's Oracle", 'Demonic Consultation'], self::cast($events), 'Oracle d\'abord, Consultation en réponse à son trigger');
        self::assertCount(40, $s->player(0)->exile);
    }

    public function testOracleThenTaintedPactContinuesUntilTheLibraryIsEmpty(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card("Thassa's Oracle", 'p0_c1'), self::card('Tainted Pact', 'p0_c2'))
            ->permanent(0, self::land('p0_u1', 'U'))->permanent(0, self::land('p0_u2', 'U'))->permanent(0, self::land('p0_b', 'B'))
            ->permanent(0, self::land('p0_c', 'C'))->build(self::AI);
        self::library($s, 0, 25);

        $events = (new GameEngine(new AgentRegistry()))->advance($s)->events;

        self::assertSame('won', self::gameOver($events)['reason']);
        self::assertSame([], $s->player(0)->library);
        self::assertSame([], array_values(array_filter($s->player(0)->hand, static fn (CardInstance $c) => str_starts_with($c->name, 'Filler'))), 'Rien pris en main');
    }

    public function testOracleIsHeldWithoutManaForTheEnabler(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card("Thassa's Oracle", 'p0_c1'), self::card('Demonic Consultation', 'p0_c2'))
            ->permanent(0, self::land('p0_u1', 'U'))->permanent(0, self::land('p0_u2', 'U'))->build(self::AI);
        self::library($s, 0, 40);

        self::assertNull(ComboPlanner::next($s, 0, self::legal($s)), 'Pas de {B} pour Consultation : on garde Oracle');
        $choice = (new HeuristicAgent())->decide($s, 0, self::legal($s));
        self::assertFalse($choice instanceof CastSpell && $choice->cardId === 'p0_c1', 'Oracle n\'est jamais lancé seule par l\'heuristique');
    }

    public function testTaintedPactOutsideTheComboStillTakesACard(): void
    {
        $s = (new ScenarioBuilder())->build(self::AI);
        self::library($s, 0, 5);
        $s->stack[] = new StackItem('s1', self::card('Tainted Pact', 'p0_c1'), 0);
        (new StackResolver())->resolveTop($s);

        self::assertNull(ComboPlanner::resolution($s, 0, array_values(array_filter(self::legal($s), static fn (Action $a) => $a instanceof ResolutionChoice))));

        // Un terrain exilé : l'heuristique continue (sans combo, elle ne vide pas sa bibliothèque pour autant).
        $s = (new ScenarioBuilder())->build(self::AI);
        $s->player(0)->library = [self::land('p0_l1', 'U'), self::filler('p0_l2')];
        $s->stack[] = new StackItem('s1', self::card('Tainted Pact', 'p0_c1'), 0);
        (new StackResolver())->resolveTop($s);
        self::assertSame('continue', (new HeuristicAgent())->decide($s, 0, self::legal($s))->toArray()['choice']);
    }

    public function testAdNauseamStopsBeforeTheWorstRevealCouldKillOrOnceTheComboIsInHand(): void
    {
        $s = (new ScenarioBuilder())->build(self::AI);
        $s->selection = ['kind' => 'ad_nauseam', 'player' => 0];
        $s->player(0)->library = [self::filler('p0_a', 1), self::filler('p0_b', 5)];
        $choices = [new ResolutionChoice(0, 'continue'), new ResolutionChoice(0, 'stop')];

        $s->player(0)->life = 6;
        self::assertSame('continue', ComboPlanner::resolution($s, 0, $choices)?->choice, '6 - 5 = 1 PV au pire');
        $s->player(0)->life = 5;
        self::assertSame('stop', ComboPlanner::resolution($s, 0, $choices)?->choice);
        $s->player(0)->life = 30;
        $s->player(0)->hand = [self::card("Thassa's Oracle", 'p0_c1'), self::card('Tainted Pact', 'p0_c2')];
        self::assertSame('stop', ComboPlanner::resolution($s, 0, $choices)?->choice, 'Oracle + Pact en main : assez');
    }

    public function testBreachLedAndBrainFreezeMillThemselvesThenEscapeOracle(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Brain Freeze', 'p0_c1'), self::filler('p0_h1'), self::filler('p0_h2'))
            ->permanent(0, self::card('Underworld Breach', 'p0_c2'))->permanent(0, self::card("Lion's Eye Diamond", 'p0_c3'))
            ->permanent(0, self::land('p0_u1', 'U'))->permanent(0, self::land('p0_u2', 'U'))->build(self::AI);
        $s->player(0)->graveyard = [self::filler('p0_g1'), self::filler('p0_g2'), self::filler('p0_g3'), self::filler('p0_g4')];
        self::library($s, 0, 30, self::card("Thassa's Oracle", 'p0_c4'));

        $events = (new GameEngine(new AgentRegistry()))->advance($s)->events;

        self::assertSame(['winner' => 0, 'reason' => 'won', 'source' => "Thassa's Oracle"], self::gameOver($events));
        $casts = self::cast($events);
        self::assertSame("Thassa's Oracle", end($casts));
        self::assertContains("Lion's Eye Diamond", $casts, 'LED relancée par escape');
        foreach ($s->player(0)->exile as $card) {
            self::assertNotSame("Lion's Eye Diamond", $card->name, 'Une pièce n\'est jamais exilée par escape');
            self::assertNotSame('Brain Freeze', $card->name);
        }
    }

    public function testHeuristicNeverCastsTheReservedPiecesAlone(): void
    {
        foreach (["Thassa's Oracle", 'Underworld Breach', 'Brain Freeze', 'Ad Nauseam'] as $name) {
            self::assertTrue(ComboPlanner::reserved(self::card($name, 'x')), $name);
            self::assertTrue(ComboPlanner::keeps(self::card($name, 'x')), $name);
        }
        self::assertFalse(ComboPlanner::reserved(self::card("Lion's Eye Diamond", 'x')));
        self::assertTrue(ComboPlanner::keeps(self::card('Demonic Consultation', 'x')));
    }

    /** Agent scripté : lance une fois `$action`, puis passe. */
    private static function scripted(CastSpell $action): AgentRegistry
    {
        return new AgentRegistry(['ai:script' => new class ($action) implements \App\Engine\Agent\PlayerAgent {
            private bool $done = false;

            public function __construct(private readonly CastSpell $action)
            {
            }

            public function decide(GameState $state, int $playerId, array $legal): Action
            {
                if (!$this->done) {
                    $this->done = true;

                    return $this->action;
                }

                return \App\Engine\Agent\AutoPassAgent::pass($legal, $playerId);
            }
        }]);
    }

    private static function escapeState(): GameState
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Underworld Breach', 'p0_c1'))->permanent(0, self::land('p0_r', 'R'))
            ->build([0 => 'ai:script', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->player(0)->graveyard = [self::filler('p0_g1'), self::land('p0_g2', 'B'), ScenarioBuilder::bolt('p0_c2'), self::filler('p0_g3'), self::filler('p0_g4')];
        $s->maxTurns = 1;

        return $s;
    }

    public function testEscapeWithChosenExilesExilesThoseCards(): void
    {
        $s = self::escapeState();
        $chosen = new CastSpell(0, 'p0_c2', ['player:1'], alt: CastSpell::ALT_ESCAPE, exiles: ['p0_g1', 'p0_g3', 'p0_g4']);
        self::assertStringEndsWith(':exiles=p0_g1,p0_g3,p0_g4', $chosen->id());

        (new GameEngine(self::scripted($chosen)))->advance($s);

        self::assertSame(['p0_g1', 'p0_g3', 'p0_g4'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile), 'Le terrain reste au cimetière');
    }

    public function testEscapeRejectsInvalidChosenExiles(): void
    {
        $this->expectException(IllegalActionException::class);
        (new GameEngine(self::scripted(new CastSpell(0, 'p0_c2', ['player:1'], alt: CastSpell::ALT_ESCAPE, exiles: ['p0_g1', 'p0_g1', 'p0_g3']))))->advance(self::escapeState());
    }

    public function testAHumanCannotSendChosenExiles(): void
    {
        $s = self::escapeState();
        $s->controllers[0] = 'human';
        $this->expectException(IllegalActionException::class);
        (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 'p0_c2', ['player:1'], alt: CastSpell::ALT_ESCAPE, exiles: ['p0_g1', 'p0_g3', 'p0_g4']));
    }
}
