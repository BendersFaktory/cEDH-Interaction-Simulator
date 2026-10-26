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

    public function testMapsPhyrexianFlashAndTreasure(): void
    {
        $gutShot = self::map('Gut Shot');
        self::assertSame('{R/P}', $gutShot->manaCost);
        self::assertSame([['effect' => 'damage', 'amount' => 1]], array_map(static fn ($e) => $e->toArray(), $gutShot->effects));

        $viper = self::map('Ambush Viper');
        self::assertContains(CardInstance::FLASH, $viper->keywords);
        self::assertContains(CardInstance::DEATHTOUCH, $viper->keywords);

        self::assertSame('treasure', self::map('Test Treasure Spell')->effects[0]->effect);
        $goblin = self::map('Test Treasure Goblin');
        self::assertSame([TriggeredAbility::ETB, 'treasure', 1], [$goblin->triggers[0]->event, $goblin->triggers[0]->effect, $goblin->triggers[0]->amount]);
    }

    public function testMapsDispelToCounterInstantsOnly(): void
    {
        $dispel = self::map('Dispel');
        self::assertSame([['effect' => 'counter', 'target' => CardInstance::INSTANT]], array_map(static fn ($e) => $e->toArray(), $dispel->effects));
        self::assertSame([['effect' => 'counter']], array_map(static fn ($e) => $e->toArray(), self::map('Counterspell')->effects));
    }

    public function testMapsDelightedHalflingLegendaryMana(): void
    {
        $halfling = self::map('Delighted Halfling');
        self::assertContains(CardInstance::LEGENDARY, $halfling->supertypes);
        self::assertCount(1, $halfling->abilities);
        self::assertSame('legendary', $halfling->abilities[0]->restriction(0)?->kind);
    }

    public function testMapsPreordainScryThenDraw(): void
    {
        self::assertSame([['effect' => 'scry', 'amount' => 2], ['effect' => 'draw', 'amount' => 1]],
            array_map(static fn ($e) => $e->toArray(), self::map('Preordain')->effects));
    }

    public function testMapsDemonicTutor(): void
    {
        self::assertSame([['effect' => 'tutor']], array_map(static fn ($e) => $e->toArray(), self::map('Demonic Tutor')->effects));
    }

    public function testMapsAlternativeCostCounters(): void
    {
        $fow = self::map('Force of Will');
        self::assertSame(CardInstance::ALT_PITCH_BLUE, $fow->altCost);
        self::assertSame([['effect' => 'counter']], array_map(static fn ($e) => $e->toArray(), $fow->effects));

        $fierce = self::map('Fierce Guardianship');
        self::assertSame(CardInstance::ALT_COMMANDER, $fierce->altCost);
        self::assertSame([['effect' => 'counter', 'target' => 'noncreature']], array_map(static fn ($e) => $e->toArray(), $fierce->effects));
    }

    public function testMapsFreeInteraction(): void
    {
        $effects = static fn (string $name): array => array_map(static fn ($e) => $e->toArray(), self::map($name)->effects);

        $fon = self::map('Force of Negation');
        self::assertSame(CardInstance::ALT_PITCH_BLUE_OFF_TURN, $fon->altCost);
        self::assertSame([['effect' => 'counter', 'target' => 'noncreature', 'counterTo' => 'exile']], $effects('Force of Negation'));

        self::assertSame(CardInstance::ALT_RETURN_ISLANDS . '1', self::map('Daze')->altCost);
        self::assertSame([['effect' => 'counter', 'payCost' => '{1}']], $effects('Daze'));
        self::assertSame([['effect' => 'counter', 'payCost' => '{3}']], $effects('Mana Leak'));
        self::assertSame(CardInstance::ALT_RETURN_ISLANDS . '2', self::map('Gush')->altCost);
        self::assertSame([['effect' => 'draw', 'amount' => 2]], $effects('Gush'));

        self::assertSame([['effect' => 'counter'], ['effect' => 'pact', 'payCost' => '{3}{U}{U}']], $effects('Pact of Negation'));
        self::assertSame('{U/P}', self::map('Mental Misstep')->manaCost);
        self::assertSame([['effect' => 'counter', 'manaValue' => 1]], $effects('Mental Misstep'));
        self::assertSame([['effect' => 'counter', 'drainMana' => true]], $effects('Mana Drain'));
        self::assertSame([['effect' => 'counter', 'counterTo' => 'library_top']], $effects('Memory Lapse'));
    }

    public function testMapsCardSelection(): void
    {
        $effects = static fn (string $name): array => array_map(static fn ($e) => $e->toArray(), self::map($name)->effects);

        self::assertSame([['effect' => 'draw', 'amount' => 3], ['effect' => 'put_back', 'amount' => 2]], $effects('Brainstorm'));
        self::assertSame([['effect' => 'arrange', 'amount' => 3], ['effect' => 'draw', 'amount' => 1]], $effects('Ponder'));
        self::assertSame([['effect' => 'scry', 'amount' => 2], ['effect' => 'draw', 'amount' => 1]], $effects('Preordain'), 'Le mot-clé Scry de Scryfall ne fait plus refuser la carte');
        $rhystic = self::map('Rhystic Study');
        self::assertSame([], $rhystic->effects);
        self::assertSame([['event' => 'opponent_cast', 'effect' => 'draw_unless', 'amount' => 1, 'spec' => '{1}']], array_map(static fn ($t) => $t->toArray(), $rhystic->triggers));
    }

    public function testMapsSwanSongWithItsBirdToken(): void
    {
        $swanSong = self::map('Swan Song');
        self::assertSame([[
            'effect' => 'counter', 'target' => 'enchantment|instant|sorcery', 'token' => 'Bird:2/2', 'tokenKeywords' => [CardInstance::FLYING],
        ]], array_map(static fn ($e) => $e->toArray(), $swanSong->effects));
    }

    public function testMapsTheTutorsByTypeAndLifeLoss(): void
    {
        $vampiric = array_map(static fn ($e) => $e->toArray(), self::map('Vampiric Tutor')->effects);
        $this->assertSame([['effect' => 'tutor', 'tutorTop' => true, 'lifeLoss' => 2]], $vampiric, 'Vampiric Tutor : toute carte, sur le dessus, 2 PV');

        $worldly = array_map(static fn ($e) => $e->toArray(), self::map('Worldly Tutor')->effects);
        $this->assertSame([['effect' => 'tutor', 'tutorTop' => true, 'tutorFilter' => 'creature']], $worldly);

        $enlightened = array_map(static fn ($e) => $e->toArray(), self::map('Enlightened Tutor')->effects);
        $this->assertSame([['effect' => 'tutor', 'tutorTop' => true, 'tutorFilter' => 'artifact|enchantment']], $enlightened);

        $mystical = array_map(static fn ($e) => $e->toArray(), self::map('Mystical Tutor')->effects);
        $this->assertSame([['effect' => 'tutor', 'tutorTop' => true, 'tutorFilter' => 'instant|sorcery']], $mystical);

        $seal = array_map(static fn ($e) => $e->toArray(), self::map('Imperial Seal')->effects);
        $this->assertSame([['effect' => 'tutor', 'tutorTop' => true, 'lifeLoss' => 2]], $seal, 'Imperial Seal : comme Vampiric Tutor');
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
        self::assertSame([['effect' => 'damage', 'amount' => 3]], array_map(static fn ($e) => $e->toArray(), $bolt->effects));
        self::assertSame([['effect' => 'damage']], array_map(static fn ($e) => $e->toArray(), self::map('Blaze')->effects));
        self::assertSame('counter', self::map('Counterspell')->effects[0]->effect);
        self::assertSame('{B}{B}{B}', self::map('Dark Ritual')->effects[0]->adds);
        self::assertSame([['effect' => 'draw', 'amount' => 2]], array_map(static fn ($e) => $e->toArray(), self::map('Divination')->effects));
        self::assertSame(['damage', 'draw'], array_map(static fn ($e) => $e->effect, self::map('Shock and Draw')->effects));

        $elves = self::map('Llanowar Elves');
        self::assertSame([CardInstance::CREATURE => ['Elf', 'Druid']], $elves->subtypes);
        self::assertTrue($elves->abilities[0]->isManaAbility);

        self::assertSame(TriggeredAbility::ETB, self::map('Elvish Visionary')->triggers[0]->event);
        self::assertSame([CardInstance::FLYING], self::map('Wind Drake')->keywords);
        self::assertSame([CardInstance::LEGENDARY], self::map('Isamaru, Hound of Konda')->supertypes);
    }

    public function testMapsActivatedAbilitiesAndTriggers(): void
    {
        $stone = self::map('Mind Stone')->abilities;
        self::assertTrue($stone[0]->isManaAbility);
        self::assertSame(['draw', 1, true, true], [$stone[1]->effect, $stone[1]->amount, $stone[1]->tap, $stone[1]->sacrifice]);
        self::assertSame(1, $stone[1]->manaCost?->manaValue());

        $signet = self::map('Izzet Signet')->abilities[0];
        self::assertTrue($signet->isManaAbility);
        self::assertSame(['U' => 1, 'R' => 1], $signet->produced());
        self::assertSame(1, $signet->manaCost?->manaValue());

        $omens = self::map('Wall of Omens');
        self::assertSame([CardInstance::DEFENDER], $omens->keywords);
        self::assertSame([TriggeredAbility::ETB, 'draw', 1], [$omens->triggers[0]->event, $omens->triggers[0]->effect, $omens->triggers[0]->amount]);

        $myr = self::map('Perilous Myr')->triggers[0];
        self::assertSame([TriggeredAbility::DIES, 'damage', 2], [$myr->event, $myr->effect, $myr->amount]);
    }

    public function testMapsDualLandsAndAnyColorMana(): void
    {
        self::assertSame(['U', 'B'], self::map('Underground Sea')->produces);
        self::assertSame(['W', 'U', 'B', 'R', 'G'], self::map('Command Tower')->produces);
        $birds = self::map('Birds of Paradise')->abilities[0];
        self::assertTrue($birds->isManaAbility);
        self::assertCount(5, $birds->options);
        $petal = self::map('Lotus Petal')->abilities[0];
        self::assertTrue($petal->isManaAbility && $petal->sacrifice && $petal->tap);
        self::assertCount(5, $petal->options);
    }

    public function testMapsSwordsToPlowsharesAndCityOfBrass(): void
    {
        $swords = self::map('Swords to Plowshares');
        self::assertSame([['effect' => 'exile', 'target' => 'creature', 'powerLife' => true]], array_map(static fn ($e) => $e->toArray(), $swords->effects));
        self::assertSame('exile', $swords->instantiate('s')->effects[0]->effect);

        $city = self::map('City of Brass');
        self::assertSame([], $city->produces);
        self::assertCount(1, $city->abilities);
        self::assertSame([1, 5, true], [$city->abilities[0]->selfDamage, count($city->abilities[0]->options), $city->abilities[0]->isManaAbility]);
        self::assertCount(1, $city->instantiate('c')->abilities(), 'pas de {C} implicite');
    }

    public function testMapsPainSourcesShocksAndFetches(): void
    {
        $tomb = self::map('Ancient Tomb');
        self::assertSame([], $tomb->produces);
        self::assertSame([2, ['C' => 2]], [$tomb->abilities[0]->selfDamage, $tomb->abilities[0]->produced()]);
        self::assertCount(1, $tomb->instantiate('t')->abilities()); // pas de {C} implicite sans douleur

        $talisman = self::map('Talisman of Dominance')->abilities;
        self::assertSame([0, 1], [$talisman[0]->selfDamage, $talisman[1]->selfDamage]);
        $river = self::map('Underground River');
        self::assertSame(['C'], $river->produces);
        self::assertSame(1, $river->abilities[0]->selfDamage);

        $confluence = self::map('Mana Confluence')->abilities[0];
        self::assertSame([1, 5], [$confluence->lifeCost, count($confluence->options)]);

        $delta = self::map('Polluted Delta')->abilities[0];
        self::assertSame(['fetch', 1, true, true, 'Island|Swamp'], [$delta->effect, $delta->lifeCost, $delta->tap, $delta->sacrifice, $delta->spec]);
        self::assertSame([], self::map('Polluted Delta')->instantiate('d')->abilities()[1] ?? []); // seule la fetch
        self::assertSame('basic', self::map('Prismatic Vista')->abilities[0]->spec);
        self::assertSame(['C'], self::map('Prismatic Vista')->produces);
        $wilds = self::map('Evolving Wilds')->abilities[0];
        self::assertSame(['basic:tapped', 0], [$wilds->spec, $wilds->lifeCost]);

        $grave = self::map('Watery Grave');
        self::assertSame([2, ['U', 'B']], [$grave->untappedLifeCost, $grave->produces]);
    }

    public function testDeckWithPainLandsAndFetchesPlays(): void
    {
        $list = "CMDR: Isamaru, Hound of Konda\n1 Ancient Tomb\n1 Mana Confluence\n1 Evolving Wilds\n1 Prismatic Vista\n95 Plains";
        $deck = $this->loader()->load($list);
        self::assertCount(99, $deck->library);
        $state = GameFactory::create(11, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'], static fn (int $id): array => $deck->instantiate($id));
        $types = array_map(static fn ($e) => $e->type, (new \App\Engine\Game\GameEngine(new \App\Engine\Agent\AgentRegistry()))->advance($state)->events);
        self::assertContains('GameOver', $types);
        self::assertContains('LandFetched', $types); // l'IA active Evolving Wilds / Prismatic Vista
    }

    public function testDeckWithCommonCreatureTypesLoads(): void
    {
        $deck = $this->loader()->load("CMDR: Isamaru, Hound of Konda\n1 Serra Angel\n1 Wall of Omens\n1 Mind Stone\n1 Perilous Myr\n95 Plains");
        self::assertCount(99, $deck->library);
        self::assertSame([CardInstance::CREATURE => ['Angel']], $deck->instantiate(0)['library'][0]->subtypes);
    }

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
            'Path to Exile : recherche de terrain pour l\'adversaire' => ['Path to Exile', 'texte non modélisé'],
            'planeswalker' => ['Jace, the Mind Sculptor', 'type non modélisé (Planeswalker)'],
            'deux faces' => ['Delver of Secrets // Insectile Aberration', 'plusieurs faces'],
            'force variable' => ['Tarmogoyf', 'force / endurance variable'],
            'texte restant sur un artefact-créature' => ['Juggernaut', 'texte non modélisé'],
            'trigger d\'entretien à double effet' => ['Phyrexian Arena', 'texte non modélisé'],
            'deux effets ciblés' => ['Bolt and Counter', 'plusieurs effets ciblés'],
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
            $this->loader()->load("CMDR: Isamaru, Hound of Konda\n1 Phyrexian Arena\n1 Carte Inventée\n1 Plains");
            self::fail('Exception attendue');
        } catch (DeckLoadException $e) {
            self::assertCount(2, $e->problems);
            self::assertStringContainsString('Phyrexian Arena', $e->getMessage());
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
