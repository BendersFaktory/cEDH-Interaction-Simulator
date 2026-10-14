<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\Discard;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PriorityState;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/** Choix de l'IA heuristique : réserve de mana pour un contresort, défausse au cleanup, attaque et blocage. */
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

    private function decide(GameState $s, int $player = 0): Action
    {
        return (new HeuristicAgent())->decide($s, $player, (new LegalActionGenerator())->for($s, $player));
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

    public function testKeepsAnIslandRatherThanASignetForTheCounterspell(): void
    {
        // 2 Îles, 1 Montagne, Izzet Signet : l'ours bleu laisserait Île + Signet (le Signet produit un {R} perdu), l'ours
        // rouge laisse deux Îles pour le Contresort.
        $signet = new CardInstance('sig', 'Izzet Signet', CardInstance::ARTIFACT, 2, null, 0, false, false, 0, 0, 0, 0, '{2}', [], [ActivatedAbility::mana('{U}{R}', '{1}')]);
        $s = $this->state(
            [self::counterspell('c1'), self::bear('blue', '{U}', 1), self::bear('red', '{R}', 1)],
            [...$this->islands(2), self::land('mnt', 'R'), $signet],
        );

        $action = $this->decide($s);
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame('red', $action->cardId);
    }

    public function testHugeThreatIsCastEvenWithoutManaLeftForTheCounterspell(): void
    {
        // 6 Îles : le 5/5 à {3}{U}{U} (valeur 5 + 2) ne laisse qu'une Île, mais il change la partie.
        $big = new CardInstance('big', 'Big', CardInstance::CREATURE, 5, null, 0, false, false, 5, 5, 0, 0, '{3}{U}{U}');
        $s = $this->state([self::counterspell('c1'), $big], $this->islands(6));

        $action = $this->decide($s);
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame('big', $action->cardId);
    }

    // --- Combat ---

    public function testCommanderDoesNotAttackIntoABlockerThatKillsIt(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'cmd', 3, 3, true)->creature(0, 'grunt', 2, 2)->creature(1, 'wall', 3, 2)->build(self::CONTROLLERS);
        $s->phase = Phase::DeclareAttackers;
        $s->combat->declaring = 'attackers';
        foreach ([2, 3] as $id) {
            $s->player($id)->lost = true;
        }

        $action = $this->decide($s);
        $this->assertInstanceOf(DeclareAttacker::class, $action);
        $this->assertSame('grunt', $action->cardId);
    }

    public function testKeepsAGuardWhenAnOpponentCanKillMe(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'a', 2, 2)->creature(0, 'b', 2, 4)->creature(1, 'big', 6, 6)->build(self::CONTROLLERS);
        $s->phase = Phase::DeclareAttackers;
        $s->combat->declaring = 'attackers';
        $s->player(0)->life = 5;
        $s->player(2)->life = 1;

        $action = $this->decide($s);
        $this->assertInstanceOf(DeclareAttacker::class, $action);
        $this->assertSame('a', $action->cardId, 'la plus grande endurance (b) reste en garde');
    }

    public function testDoubleBlocksAnAttackerThatNoSingleBlockerKills(): void
    {
        // 4/4 contre deux 2/3 : aucun ne le tue seul, ensemble ils le tuent et il ne peut en tuer qu'un.
        $s = (new ScenarioBuilder())->creature(0, 'atk', 4, 4)->creature(1, 'b1', 2, 3)->creature(1, 'b2', 2, 3)->build(self::CONTROLLERS);
        $s->phase = Phase::DeclareBlockers;
        $s->combat->declaring = 'blockers';
        $s->combat->attackers = ['atk' => 1];
        $s->combat->queue = [1];
        $s->priority = new PriorityState(1, [], true);

        $first = $this->decide($s, 1);
        $this->assertInstanceOf(DeclareBlocker::class, $first);
        $s->combat->blockers['atk'] = [$first->cardId];

        $second = $this->decide($s, 1);
        $this->assertInstanceOf(DeclareBlocker::class, $second);
        $this->assertSame(['b1', 'b2'], [...$s->combat->blockers['atk'], $second->cardId]);
    }

    public function testNoDoubleBlockWhenTheAttackerWouldKillBoth(): void
    {
        $s = (new ScenarioBuilder())->creature(0, 'atk', 6, 4)->creature(1, 'b1', 2, 3)->creature(1, 'b2', 2, 3)->build(self::CONTROLLERS);
        $s->phase = Phase::DeclareBlockers;
        $s->combat->declaring = 'blockers';
        $s->combat->attackers = ['atk' => 1];
        $s->combat->queue = [1];
        $s->priority = new PriorityState(1, [], true);

        $this->assertNotInstanceOf(DeclareBlocker::class, $this->decide($s, 1));
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
