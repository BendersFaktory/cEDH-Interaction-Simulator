<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Engine\Mana\ManaPool;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Symboles phyrexian (CR 107.4f), mot-clé flash et effet Treasure d'un sort / d'un trigger d'entrée. */
final class PhyrexianFlashTreasureTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function player(int $life, string ...$lands): PlayerState
    {
        $cards = [];
        foreach ($lands as $i => $color) {
            $cards[] = new CardInstance('l' . $i, 'Land', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
        }

        return new PlayerState(0, $life, [], [], $cards, [], [], [], [], new ManaPool());
    }

    private static function gutShot(string $id): CardInstance
    {
        return new CardInstance($id, 'Gut Shot', CardInstance::INSTANT, 1, 'damage', 1, false, false, 0, 0, 0, 0, '{R/P}');
    }

    /** @return list<CastSpell> */
    private function casts(GameState $s, int $player = 0): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, $player), static fn (Action $a) => $a instanceof CastSpell));
    }

    public function testPhyrexianCostParsesAndPrints(): void
    {
        $cost = ManaCost::parse('{1}{U/P}{B/P}');
        $this->assertSame(3, $cost->manaValue());
        $this->assertSame('{1}{U/P}{B/P}', (string) $cost);
        $this->assertSame('{3}{U/P}', (string) ManaCost::parse('{2}{U/P}')->withExtraGeneric(1));
    }

    public function testPhyrexianSymbolPrefersManaThenLife(): void
    {
        $plan = ManaPayment::plan(self::player(20, 'R'), ManaCost::parse('{R/P}'));
        $this->assertSame(0, $plan->lifeCost);
        $this->assertSame(['R' => 1], $plan->spend);

        $plan = ManaPayment::plan(self::player(20), ManaCost::parse('{R/P}'));
        $this->assertSame(2, $plan->lifeCost);
        $this->assertSame([], $plan->taps);

        // Un seul mana de la bonne couleur pour deux symboles : l'un en mana, l'autre en PV.
        $plan = ManaPayment::plan(self::player(20, 'B'), ManaCost::parse('{B/P}{B/P}'));
        $this->assertSame(2, $plan->lifeCost);
        $this->assertSame(['B' => 1], $plan->spend);

        // Le mana générique n'est jamais payable en PV.
        $this->assertNull(ManaPayment::plan(self::player(20), ManaCost::parse('{1}{U/P}')));
    }

    public function testPhyrexianLifeNeverBringsLifeBelowOne(): void
    {
        $this->assertNull(ManaPayment::plan(self::player(2), ManaCost::parse('{U/P}'))); // 2 PV : tomberait à 0
        $this->assertSame(2, ManaPayment::plan(self::player(3), ManaCost::parse('{U/P}'))->lifeCost);
        $this->assertNull(ManaPayment::plan(self::player(4), ManaCost::parse('{U/P}{U/P}'))); // 4 PV : tomberait à 0
        $this->assertSame(4, ManaPayment::plan(self::player(5), ManaCost::parse('{U/P}{U/P}'))->lifeCost);
        // Avec un mana de la couleur, un seul symbole en PV suffit.
        $this->assertSame(2, ManaPayment::plan(self::player(4, 'U'), ManaCost::parse('{U/P}{U/P}'))->lifeCost);
    }

    public function testPhyrexianSpellCastPaysLifeAndLogsLifePaid(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::gutShot('g1'))->build(self::PASS);
        $this->assertCount(1, array_filter($this->casts($s), static fn (CastSpell $a) => $a->targets === ['player:2']));
        $s->controllers[0] = 'scripted';
        $result = (new GameEngine(new AgentRegistry(['scripted' => new ScriptedAgent('Gut Shot', 'player:2')])))->advance($s);

        $paid = array_values(array_filter($result->events, static fn ($e) => $e->type === 'LifePaid'));
        $this->assertCount(1, $paid);
        $this->assertSame(2, $paid[0]->payload['amount']);
        $this->assertSame(18, $paid[0]->payload['life']);
        $this->assertSame(18, $s->player(0)->life);
        $this->assertSame(19, $s->player(2)->life);
        $cast = array_values(array_filter($result->events, static fn ($e) => $e->type === 'SpellCast'))[0]->payload;
        $this->assertSame('{R/P}', $cast['manaCost']);
        $this->assertSame([], $cast['tapped']);
    }

    public function testPhyrexianSpellWithManaAvailablePaysNoLife(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::gutShot('g1'))->untappedLands(0, 0)->build(self::PASS);
        $s->player(0)->battlefield[] = new CardInstance('m1', 'Mountain', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['R']);
        $s->controllers[0] = 'scripted';
        $result = (new GameEngine(new AgentRegistry(['scripted' => new ScriptedAgent('Gut Shot', 'player:2')])))->advance($s);

        $this->assertSame([], array_filter($result->events, static fn ($e) => $e->type === 'LifePaid'));
        $this->assertSame(20, $s->player(0)->life);
    }

    public function testPhyrexianSpellIsNotOfferedAtOneOrTwoLifeWithoutMana(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::gutShot('g1'))->life(2)->build(self::PASS);
        $this->assertSame([], $this->casts($s));
    }

    public function testFlashCreatureIsCastableAtInstantSpeedOnly(): void
    {
        $flash = new CardInstance('f1', 'Ambush Viper', CardInstance::CREATURE, 2, null, 0, false, false, 2, 1, 0, 0, '{G}', keywords: [CardInstance::FLASH]);
        $plain = new CardInstance('p1', 'Bear', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2, 0, 0, '{G}');
        $s = (new ScenarioBuilder())->hand(0, $flash, $plain)->build(self::PASS);
        $s->player(0)->battlefield[] = new CardInstance('m1', 'Forest', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['G']);

        $names = static fn (array $casts): array => array_map(static fn (CastSpell $a) => $s->player(0)->findCastable($a->cardId)->name, $casts);
        $this->assertEqualsCanonicalizing(['Ambush Viper', 'Bear'], $names($this->casts($s))); // tour de J0, principale : les deux

        $s->activePlayer = 1; // pas son tour : seule la carte avec flash reste lançable
        $this->assertSame(['Ambush Viper'], $names($this->casts($s)));
    }

    public function testTreasureSpellAndEnterTriggerCreateTreasures(): void
    {
        $spell = new CardInstance('t1', 'Test Treasure Spell', CardInstance::SORCERY, 1, 'treasure');
        $s = (new ScenarioBuilder())->hand(0, $spell)->untappedLands(0, 1)->build(self::PASS);
        $s->controllers[0] = 'scripted';
        (new GameEngine(new AgentRegistry(['scripted' => new ScriptedAgent('Test Treasure Spell', null)])))->advance($s);
        $this->assertSame(['Land', 'Treasure'], array_map(static fn (CardInstance $c) => $c->name, $s->player(0)->battlefield));

        $goblin = new CardInstance('t2', 'Test Treasure Goblin', CardInstance::CREATURE, 2, null, 0, false, false, 1, 1, 0, 0, null, triggers: [new TriggeredAbility(TriggeredAbility::ETB, 'treasure', 2)]);
        $s = (new ScenarioBuilder())->hand(0, $goblin)->untappedLands(0, 2)->build(self::PASS);
        $s->controllers[0] = 'scripted';
        (new GameEngine(new AgentRegistry(['scripted' => new ScriptedAgent('Test Treasure Goblin', null)])))->advance($s);
        $names = array_map(static fn (CardInstance $c) => $c->name, $s->player(0)->battlefield);
        sort($names);
        $this->assertSame(['Land', 'Land', 'Test Treasure Goblin', 'Treasure', 'Treasure'], $names);
    }
}
