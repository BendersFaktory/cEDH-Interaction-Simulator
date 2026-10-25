<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\CastSpell;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/** Tuteur (Demonic Tutor) : une carte de la bibliothèque va en main, la bibliothèque est mélangée (CR 701.19). */
final class TutorTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function tutor(string $id): CardInstance
    {
        return new CardInstance($id, 'Demonic Tutor', CardInstance::SORCERY, 2, effects: [new SpellEffect('tutor')]);
    }

    private static function libraryCard(string $id, string $name): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::CREATURE, 1, power: 1, toughness: 1);
    }

    public function testOneTargetPerCardNameInTheLibrary(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::tutor('dt'))->untappedLands(0, 2)->build(self::PASS);
        $s->player(0)->library = [self::libraryCard('a', 'Bear'), self::libraryCard('b', 'Bear'), self::libraryCard('c', 'Elk')];
        $s->priority = new \App\Engine\Game\PriorityState(0);
        $s->phase = \App\Engine\Game\Phase::Main1;

        $targets = array_values(array_map(static fn (CastSpell $a) => $a->targets, array_filter(
            (new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof CastSpell && $a->cardId === 'dt',
        )));

        $this->assertSame([['library:a'], ['library:c']], $targets, 'Une cible par nom, les doublons ne sont pas proposés');
    }

    public function testResolutionPutsTheCardInHandAndShufflesTheLibrary(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(0)->library = [self::libraryCard('a', 'Bear'), self::libraryCard('c', 'Elk')];
        $s->stack[] = new StackItem('dt', self::tutor('dt'), 0, ['library:c']);

        (new StackResolver())->resolveTop($s);

        $this->assertSame(['c'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->hand), 'La carte cherchée est en main');
        $this->assertSame(['a'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->library), 'La bibliothèque perd cette carte');
    }

    public function testTheAiFetchesTheStrongestCard(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::tutor('dt'))->untappedLands(0, 2)->build(self::PASS);
        $s->player(0)->library = [self::libraryCard('a', 'Bear'), new CardInstance('c', 'Titan', CardInstance::CREATURE, 6, power: 6, toughness: 6)];
        $s->priority = new \App\Engine\Game\PriorityState(0);
        $s->phase = \App\Engine\Game\Phase::Main1;

        $action = (new \App\Engine\Agent\HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertSame(['library:c'], $action->targets);
    }

    public function testAnEmptyLibraryOffersNoRealTarget(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::tutor('dt'))->untappedLands(0, 2)->build(self::PASS);
        $s->player(0)->library = [];
        $s->priority = new \App\Engine\Game\PriorityState(0);
        $s->phase = \App\Engine\Game\Phase::Main1;

        $targets = array_map(static fn ($a) => $a->targets, array_filter(
            (new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof CastSpell && $a->cardId === 'dt',
        ));

        $this->assertSame([['library:none']], array_values($targets));
    }
}
