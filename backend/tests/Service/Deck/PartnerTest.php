<?php

declare(strict_types=1);

namespace App\Tests\Service\Deck;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use App\Service\Deck\CardMapper;
use App\Service\Deck\DeckLoader;
use App\Service\Deck\DeckLoadException;
use App\Service\Deck\DecklistParser;
use App\Service\Deck\LoadedDeck;
use App\Service\Scryfall\CardCatalog;
use App\Tests\Engine\ScenarioBuilder;
use PHPUnit\Framework\TestCase;

/** Partner (CR 702.124) : deux commandants, Kraum, Ludevic's Opus et Tymna the Weaver (lot H1). */
final class PartnerTest extends TestCase
{
    private const KRAUM = "Kraum, Ludevic's Opus";
    private const TYMNA = 'Tymna the Weaver';
    private const LIST = "Commander\n1 Kraum, Ludevic's Opus\n1 Tymna the Weaver\n\nDeck\n1 Lightning Bolt\n1 Swords to Plowshares\n1 Dark Ritual\n1 Counterspell\n47 Plains\n47 Island";

    private function loader(): DeckLoader
    {
        $catalog = $this->createStub(CardCatalog::class);
        $catalog->method('get')->willReturnCallback(static function (array $names): array {
            $fixtures = ScryfallFixtures::all();
            $cards = [];
            foreach ($names as $name) {
                $cards[$name] = ['data' => $fixtures[$name], 'images' => ['small' => null, 'normal' => null],
                    'fetchedAt' => new \DateTimeImmutable('2026-10-26'), 'stale' => false];
            }

            return ['cards' => $cards, 'missing' => [], 'degraded' => false];
        });

        return new DeckLoader($catalog);
    }

    private static function card(string $name, string $id, bool $commander = false): CardInstance
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name])->instantiate($id, $commander);
    }

    private function problem(string $list, ?string $commander = null, ?string $partner = null): string
    {
        try {
            $this->loader()->load($list, $commander, $partner);
        } catch (DeckLoadException $e) {
            return $e->getMessage();
        }
        self::fail('Decklist acceptée à tort');
    }

    public function testParserReadsTwoCommanders(): void
    {
        $parsed = DecklistParser::parse(self::LIST);
        self::assertSame([self::KRAUM, self::TYMNA], [$parsed['commander'], $parsed['partner']]);
        self::assertSame(self::TYMNA, DecklistParser::parse("CMDR: Kraum, Ludevic's Opus\nCMDR: Tymna the Weaver\n1 Plains")['partner']);
        self::assertNull(DecklistParser::parse("CMDR: Isamaru, Hound of Konda\n1 Plains")['partner']);
    }

    public function testPartnersLoadIntoTheCommandZoneWithA98CardLibrary(): void
    {
        $deck = $this->loader()->load(self::LIST);
        self::assertCount(98, $deck->library);
        self::assertSame(self::TYMNA, $deck->partner?->name);

        $deck = LoadedDeck::fromArray($deck->toArray());
        $instances = $deck->instantiate(2);
        self::assertSame(['p2_c0', 'p2_c1', 'p2_c2'], [$instances['commander']->id, $instances['partner']->id, $instances['library'][0]->id]);
        self::assertTrue($instances['partner']->commander);
        self::assertContains(CardInstance::PARTNER, $instances['partner']->keywords);

        $state = GameFactory::create(7, [0 => 'ai:heuristic', 1 => 'ai:heuristic'], static fn (int $id): array => $deck->instantiate($id));
        self::assertSame([self::KRAUM, self::TYMNA], array_map(static fn (CardInstance $c) => $c->name, $state->player(1)->command));
        self::assertCount(98 - 7, $state->player(1)->library);
    }

    public function testCommanderAndPartnerCanBeGivenOutsideTheList(): void
    {
        $deck = $this->loader()->load("1 Lightning Bolt\n1 Swords to Plowshares\n48 Plains\n48 Island", self::TYMNA, self::KRAUM);
        self::assertSame([self::TYMNA, self::KRAUM], [$deck->commander->name, $deck->partner?->name]);
    }

    public function testRulesForTwoCommanders(): void
    {
        self::assertStringContainsString('exigent Partner', $this->problem("Commander\n1 Kraum, Ludevic's Opus\n1 Isamaru, Hound of Konda\nDeck\n98 Plains"));
        self::assertStringContainsString('au lieu de 98', $this->problem(self::LIST . "\n1 Wind Drake"));
        // Identité réunie : W (Swords) et R (Bolt) passent avec Kraum + Tymna, pas avec Kraum seul.
        self::assertStringContainsString('Swords to Plowshares', $this->problem("CMDR: Kraum, Ludevic's Opus\n1 Swords to Plowshares\n98 Island"));
    }

    public function testTaxAndCommanderDamageAreCountedPerCommander(): void
    {
        $kraum = self::card(self::KRAUM, 'k', true);
        $tymna = self::card(self::TYMNA, 't', true);
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        $p = $s->player(0);
        $p->command = [$kraum, $tymna];
        $p->commanderCasts = ['k' => 2];
        self::assertSame(5 + 4, $p->castCost($kraum)->manaValue());
        self::assertSame(3, $p->castCost($tymna)->manaValue());

        // 20 + 20 de deux commandants différents : en vie ; 21 d'un seul : perdu (CR 903.10a).
        $s->player(1)->commanderDamage = ['0:k' => 20, '0:t' => 20];
        $s->player(2)->commanderDamage = ['0:t' => 21];
        (new StateBasedActions())->check($s);
        self::assertFalse($s->player(1)->lost);
        self::assertTrue($s->player(2)->lost);
    }

    public function testKraumTriggersOnTheSecondSpellOfAnOpponentOnly(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass']);
        $s->player(0)->battlefield[] = self::card(self::KRAUM, 'k', true);
        $bolt = ScenarioBuilder::bolt('b');

        foreach ([1 => 0, 2 => 1, 3 => 1] as $count => $expected) {
            $s->spellsCast[1] = $count;
            $s->queueOpponentCastTriggers(1, $bolt);
            self::assertCount($expected, $s->pendingTriggers, "sort n° $count");
        }
        $s->spellsCast = [0 => 2];
        $s->pendingTriggers = [];
        $s->queueOpponentCastTriggers(0, $bolt); // ses propres sorts ne comptent pas
        self::assertSame([], $s->pendingTriggers);

        self::assertSame(['spellsCast' => [0 => 2]], array_intersect_key($s->toArray(), ['spellsCast' => true]));
        self::assertSame([0 => 2], GameState::fromArray($s->toArray())->spellsCast);
    }

    public function testCastingASpellCountsItForTheTurn(): void
    {
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('b'))->untappedLands(0, 1)
            ->build([0 => 'scripted:bolt', 1 => 'human']);
        $registry = new AgentRegistry(['scripted:bolt' => new \App\Tests\Engine\ScriptedAgent('Lightning Bolt', 'player:1')]);
        (new GameEngine($registry))->advance($s); // s'arrête quand J1 (humain) doit répondre au Bolt
        self::assertCount(1, $s->stack);
        self::assertSame([0 => 1], $s->spellsCast);
    }

    public function testFourAiGameWithKraumAndTymnaPlaysToTheEnd(): void
    {
        $f = ScryfallFixtures::all();
        $m = new CardMapper();
        $library = [];
        foreach (['Lightning Bolt', 'Sol Ring', 'Counterspell', 'Dark Ritual', 'Command Tower', 'Mana Confluence', 'City of Brass', 'Swords to Plowshares', 'Brainstorm', 'Wall of Omens', 'Serra Angel', 'Wind Drake', 'Mind Stone', 'Demonic Tutor'] as $name) {
            $library[] = $m->map($f[$name]);
        }
        foreach (['Plains', 'Island', 'Swamp'] as $basic) {
            for ($i = 0; $i < 28; $i++) {
                $library[] = $m->map($f[$basic]);
            }
        }
        $deck = new LoadedDeck($m->map($f[self::KRAUM]), $library, partner: $m->map($f[self::TYMNA]));
        $state = GameFactory::create(7, array_fill(0, 4, 'ai:heuristic'), static fn (int $id): array => $deck->instantiate($id));

        $kraum = $tymna = 0;
        $types = [];
        foreach ((new GameEngine(new AgentRegistry()))->advance($state)->events as $e) {
            $types[] = $e->type;
            $kraum += (int) ($e->type === 'AbilityTriggered' && $e->payload['card']['name'] === self::KRAUM);
            $tymna += (int) ($e->type === 'LifePaid' && $e->payload['source'] === self::TYMNA);
        }
        self::assertContains('GameOver', $types);
        self::assertGreaterThan(0, $kraum);
        self::assertGreaterThan(0, $tymna);
    }

    public function testTymnaPaysAndDrawsOnePerOpponentDealtCombatDamage(): void
    {
        $s = (new ScenarioBuilder())->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $tymna = self::card(self::TYMNA, 't', true);
        $s->player(0)->battlefield[] = $tymna;
        $s->queuePlayerTriggers(0, TriggeredAbility::POSTCOMBAT_MAIN);
        self::assertCount(1, $s->pendingTriggers);

        $s->combatDamaged = [0, 1, 3]; // J0 lui-même ne compte pas
        $hand = count($s->player(0)->hand);
        $life = $s->player(0)->life;
        $s->stack[] = new StackItem('s1', clone $tymna, 0, [], 0, null, 0, $tymna->triggers[0]);
        (new StackResolver())->resolveTop($s);
        self::assertSame($life - 2, $s->player(0)->life);
        self::assertCount($hand + 2, $s->player(0)->hand);

        $s->combatDamaged = [];
        $s->stack[] = new StackItem('s2', clone $tymna, 0, [], 0, null, 0, $tymna->triggers[0]);
        (new StackResolver())->resolveTop($s);
        self::assertSame($life - 2, $s->player(0)->life, 'X = 0 : rien');
        self::assertSame([], GameState::fromArray($s->toArray())->combatDamaged);
    }
}
