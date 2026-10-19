<?php

declare(strict_types=1);

namespace App\Tests\Service\Deck;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameFactory;
use App\Service\Deck\CardMapper;
use App\Service\Deck\DeckLoader;
use App\Service\Deck\DeckLoadException;
use App\Service\Deck\DecklistParser;
use App\Service\Deck\UnsupportedCard;
use App\Service\Scryfall\CardCatalog;
use PHPUnit\Framework\TestCase;

final class DeckLoaderTest extends TestCase
{
    private function loader(bool $degraded = false): DeckLoader
    {
        $catalog = $this->createStub(CardCatalog::class);
        $catalog->method('get')->willReturnCallback(static function (array $names) use ($degraded): array {
            $fixtures = ScryfallFixtures::all();
            $cards = [];
            $missing = [];
            foreach ($names as $name) {
                if (!isset($fixtures[$name])) {
                    $missing[] = $name;
                    continue;
                }
                $cards[$name] = ['data' => $fixtures[$name], 'images' => ['small' => "s/$name", 'normal' => "n/$name"],
                    'fetchedAt' => new \DateTimeImmutable('2026-10-17'), 'stale' => false];
            }

            return ['cards' => $cards, 'missing' => $missing, 'degraded' => $degraded];
        });

        return new DeckLoader($catalog);
    }

    private static function map(string $key): \App\Engine\Card\CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$key]);
    }

    public function testParserHandlesCommonFormats(): void
    {
        $parsed = DecklistParser::parse("// commentaire\nCommander\n1 Isamaru, Hound of Konda\n\nDeck\n1x Sol Ring (C21) 263 *F*\n10 Plains\nLlanowar Elves\n2 Plains\n\nSideboard\n1 Forest\n");
        self::assertSame('Isamaru, Hound of Konda', $parsed['commander']);
        self::assertSame(['Sol Ring' => 1, 'Plains' => 12, 'Llanowar Elves' => 1], $parsed['entries']);
        self::assertSame('Isamaru, Hound of Konda', DecklistParser::parse("CMDR: Isamaru, Hound of Konda\n1 Plains")['commander']);
    }

    public function testMapsSupportedCards(): void
    {
        $plains = self::map('Plains');
        self::assertSame(['W'], $plains->produces);
        self::assertSame(CardInstance::LAND, $plains->type);

        $ring = self::map('Sol Ring');
        self::assertSame(CardInstance::ARTIFACT, $ring->type);
        self::assertSame(1, $ring->cost);
        self::assertSame(['C' => 2], $ring->abilities[0]->produced());

        $bolt = self::map('Lightning Bolt');
        self::assertSame(['damage', 3], [$bolt->effect, $bolt->amount]);
        self::assertSame(['damage', 0], [self::map('Blaze')->effect, self::map('Blaze')->amount]);
        self::assertSame('counter', self::map('Counterspell')->effect);
        self::assertSame('{B}{B}{B}', self::map('Dark Ritual')->adds);

        $elves = self::map('Llanowar Elves');
        self::assertSame([CardInstance::CREATURE => ['Elf', 'Druid']], $elves->subtypes);
        self::assertTrue($elves->abilities[0]->isManaAbility);

        self::assertSame(TriggeredAbility::ETB, self::map('Elvish Visionary')->triggers[0]->event);
        self::assertSame([CardInstance::FLYING], self::map('Wind Drake')->keywords);
        self::assertSame([CardInstance::LEGENDARY], self::map('Isamaru, Hound of Konda')->supertypes);
    }

    /** @dataProvider unsupported */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupported')]
    public function testRefusesUnmodelledCards(string $key, string $reason): void
    {
        try {
            self::map($key);
            self::fail("« $key » aurait dû être refusée");
        } catch (UnsupportedCard $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public static function unsupported(): array
    {
        return [
            'texte inconnu' => ['Rhystic Study', 'texte non modélisé'],
            'planeswalker' => ['Jace, the Mind Sculptor', 'type non modélisé (Planeswalker)'],
            'deux faces' => ['Delver of Secrets // Insectile Aberration', 'plusieurs faces'],
            'force variable' => ['Tarmogoyf', 'force / endurance variable'],
            'texte restant sur un artefact-créature' => ['Juggernaut', 'texte non modélisé'],
        ];
    }

    public function testLoadsPlayableDeck(): void
    {
        $deck = $this->loader()->load("Commander\n1 Isamaru, Hound of Konda\nDeck\n1 Sol Ring\n98 Plains\n");
        self::assertCount(99, $deck->library);
        self::assertSame('Isamaru, Hound of Konda', $deck->commander->name);
        self::assertSame('n/Sol Ring', $deck->images['Sol Ring']['normal']);

        $instances = $deck->instantiate(2);
        self::assertSame('p2_c0', $instances['commander']->id);
        self::assertTrue($instances['commander']->commander);
        self::assertSame('p2_c99', $instances['library'][98]->id);

        // Jouable de bout en bout : la partie démarre avec ce deck sur les 4 sièges.
        $state = GameFactory::create(7, [0 => 'ai:heuristic', 1 => 'ai:heuristic'], static fn (int $id): array => $deck->instantiate($id));
        self::assertCount(7, $state->player(0)->hand);
        self::assertSame('Isamaru, Hound of Konda', $state->player(1)->command[0]->name);
    }

    public function testCommanderAlsoInMainListIsNotCountedTwice(): void
    {
        $deck = $this->loader()->load("1 Isamaru, Hound of Konda\n1 Sol Ring\n98 Plains", 'Isamaru, Hound of Konda');
        self::assertCount(99, $deck->library);
    }

    public function testCollectsAllProblems(): void
    {
        try {
            $this->loader()->load("CMDR: Isamaru, Hound of Konda\n1 Rhystic Study\n1 Carte Inventée\n1 Plains");
            self::fail('Exception attendue');
        } catch (DeckLoadException $e) {
            self::assertCount(2, $e->problems);
            self::assertStringContainsString('Rhystic Study', $e->getMessage());
            self::assertStringContainsString('carte inconnue', $e->getMessage());
        }
    }

    public function testDegradedModeIsReportedInMessageAndDeck(): void
    {
        try {
            $this->loader(true)->load("CMDR: Isamaru, Hound of Konda\n1 Carte Inventée");
            self::fail('Exception attendue');
        } catch (DeckLoadException $e) {
            self::assertStringContainsString('Scryfall indisponible', $e->getMessage());
        }
        self::assertTrue($this->loader(true)->load("CMDR: Isamaru, Hound of Konda\n1 Sol Ring\n98 Plains")->degraded);
    }

    /** @dataProvider invalidDecks */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecks')]
    public function testCommanderRulesAreValidated(string $list, string $message): void
    {
        $this->expectException(DeckLoadException::class);
        $this->expectExceptionMessageMatches($message);
        $this->loader()->load($list);
    }

    public static function invalidDecks(): array
    {
        return [
            'sans commandant' => ["1 Sol Ring\n98 Plains", '/Aucun commandant/'],
            'commandant non légendaire' => ["CMDR: Llanowar Elves\n1 Sol Ring\n98 Plains", '/créature légendaire/'],
            '99 cartes exigées' => ["CMDR: Isamaru, Hound of Konda\n50 Plains", '/50 cartes au lieu de 99/'],
            'singleton' => ["CMDR: Isamaru, Hound of Konda\n2 Sol Ring\n97 Plains", '/plusieurs exemplaires/'],
            'identité de couleurs' => ["CMDR: Isamaru, Hound of Konda\n1 Counterspell\n98 Plains", '/identité de couleurs/'],
        ];
    }
}
