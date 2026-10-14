<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\Discard;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Choix de l'IA heuristique : réserve de mana pour un contresort, défausse au cleanup. */
final class HeuristicAgentTest extends TestCase
{
    private const CONTROLLERS = [0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function land(string $id, string $color): CardInstance
    {
        return new CardInstance($id, $color === 'U' ? 'Island' : 'Forest', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, [$color]);
    }

    private static function counterspell(string $id): CardInstance
    {
        return new CardInstance($id, 'Counterspell', CardInstance::INSTANT, 2, 'counter', 0, false, false, 0, 0, 0, 0, '{U}{U}');
    }

    private static function bear(string $id, string $cost = '{1}{U}', int $value = 2): CardInstance
    {
        return new CardInstance($id, 'Bear ' . $id, CardInstance::CREATURE, $value, null, 0, false, false, 2, 2, 0, 0, $cost);
    }

    /** @param list<CardInstance> $hand @param list<CardInstance> $battlefield */
    private function state(array $hand, array $battlefield): GameState
    {
        $b = (new ScenarioBuilder())->hand(0, ...$hand);
        foreach ($battlefield as $card) {
            $b->permanent(0, $card);
        }

        return $b->build(self::CONTROLLERS);
    }

    private function decide(GameState $s): Action
    {
        return (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
    }

    /** @return list<CardInstance> */
    private function islands(int $n): array
    {
        return array_map(fn (int $i) => self::land("isl$i", 'U'), range(1, $n));
    }

    // --- Garder de quoi contrer ---

    public function testDoesNotSpendTheManaOfItsCounterspell(): void
    {
        // 3 Îles, Contresort {U}{U} en main : lancer l'ours ({1}{U}) laisserait 1 Île, il ne reste plus de quoi contrer.
        $s = $this->state([self::counterspell('c1'), self::bear('b1')], $this->islands(3));

        $this->assertNotInstanceOf(CastSpell::class, $this->decide($s));
    }

    public function testCastsWhenEnoughManaRemainsForTheCounterspell(): void
    {
        $s = $this->state([self::counterspell('c1'), self::bear('b1')], $this->islands(4));

        $action = $this->decide($s);
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame('b1', $action->cardId);
    }

    public function testNoReserveWhenTheCounterspellCannotBePaidAnyway(): void
    {
        // Une seule Île : le contresort n'est pas payable, la réserve serait sans objet.
        $s = $this->state([self::counterspell('c1'), self::bear('b1', '{U}', 1)], $this->islands(1));

        $action = $this->decide($s);
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame('b1', $action->cardId);
    }

    public function testLethalBurnIsCastEvenWithoutManaLeftForTheCounterspell(): void
    {
        $burn = new CardInstance('f1', 'Shock', CardInstance::INSTANT, 1, 'damage', 3, false, false, 0, 0, 0, 0, '{U}');
        $s = $this->state([self::counterspell('c1'), $burn], $this->islands(2));
        $s->player(1)->life = 3;

        $action = $this->decide($s);
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame(['player:1'], $action->targets);

        // Aucun adversaire à portée de mort : on garde le contresort.
        $s->player(1)->life = 20;
        $this->assertNotInstanceOf(CastSpell::class, $this->decide($s));
    }

    // --- Défausse ---

    /** @param list<CardInstance> $hand @param list<CardInstance> $battlefield */
    private function discarded(array $hand, array $battlefield): CardInstance
    {
        $s = $this->state($hand, $battlefield);
        $s->phase = Phase::Cleanup;
        $action = $this->decide($s);
        $this->assertInstanceOf(Discard::class, $action);

        return $s->player(0)->findInHand($action->cardId);
    }

    public function testDiscardKeepsTheCounterspell(): void
    {
        $hand = [self::counterspell('c1')];
        for ($i = 1; $i <= 7; $i++) {
            $hand[] = self::bear("b$i", '{U}', 1);
        }

        $this->assertNotSame('Counterspell', $this->discarded($hand, $this->islands(4))->name);
    }

    public function testDiscardDropsExtraLandsBeforeSpells(): void
    {
        // 4 terrains en jeu et 3 en main : le troisième terrain de la main est en trop, avant le sort le plus cher.
        $hand = [self::land('hl1', 'U'), self::land('hl2', 'U'), self::land('hl3', 'U')];
        for ($i = 1; $i <= 5; $i++) {
            $hand[] = self::bear("b$i", '{2}{U}', 3);
        }

        $this->assertTrue($this->discarded($hand, $this->islands(4))->isLand());
    }

    public function testDiscardDropsUnplayableCardsFirst(): void
    {
        // 3 Îles et aucun terrain vert : le sort {G}{G} est injouable, bien que moins cher que les autres.
        $hand = [self::bear('g1', '{G}{G}', 2)];
        for ($i = 1; $i <= 7; $i++) {
            $hand[] = self::bear("b$i", '{2}{U}', 3);
        }

        $this->assertSame('Bear g1', $this->discarded($hand, $this->islands(3))->name);
    }

    public function testDiscardKeepsLandsWhenShortOnMana(): void
    {
        // 2 terrains en jeu : les terrains de la main restent, on défausse le sort le plus cher.
        $hand = [self::land('hl1', 'U'), self::bear('b1', '{U}', 1), self::bear('b2', '{1}{U}', 2), self::bear('b3', '{2}{U}', 3)];
        for ($i = 4; $i <= 7; $i++) {
            $hand[] = self::bear("b$i", '{U}', 1);
        }
        $hand[] = self::bear('b8', '{1}{U}', 2);

        $this->assertSame('Bear b3', $this->discarded($hand, $this->islands(2))->name);
    }
}
