<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\Action;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Jouer une carte exilée (prérequis de Ragavan et d'Opposition Agent) : `GameState::grantExilePlay()` donne la permission,
 * la carte reste dans l'exil de son propriétaire, `LegalActionGenerator` la propose, le lancement la sort de l'exil
 * (`SpellCast.fromExile`), le sort garde son propriétaire ; `anyMana` paie les couleurs avec n'importe quel mana ; une
 * permission « ce tour » tombe au cleanup.
 */
final class ExilePlayTest extends TestCase
{
    private const PASS = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<Action> */
    private function exileActions(GameState $s, string $cardId, int $player = 0): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, $player),
            static fn (Action $a) => ($a instanceof CastSpell || $a instanceof PlayLand) && $a->cardId === $cardId));
    }

    private static function goblin(string $manaCost = '{1}'): CardInstance
    {
        return new CardInstance('p1_c9', 'Goblin', CardInstance::CREATURE, 1, null, 0, false, false, 1, 1, 0, 0, $manaCost);
    }

    /** J1 a `$card` en exil ; J0 (phase principale, deux terrains incolores) en reçoit la permission. */
    private function exiled(CardInstance $card, string $until = GameState::EXILE_PLAY_TURN, bool $lands = false, bool $anyMana = false): GameState
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 2)->build(self::PASS);
        $s->player(1)->exile[] = $card;
        $s->grantExilePlay(0, $card, $until, 'Ragavan, Nimble Pilferer', $lands, $anyMana);

        return $s;
    }

    public function testExiledCardIsCastAndKeepsItsOwner(): void
    {
        $s = $this->exiled(self::goblin());
        $granted = $this->of($s->drainEvents(), 'ExilePlayGranted')[0]->payload;
        $this->assertSame([0, 1, 'turn'], [$granted['player'], $granted['owner'], $granted['until']]);
        $this->assertSame([], $this->exileActions($s, 'p1_c9', 1), 'pas pour un autre joueur');

        $casts = $this->exileActions($s, 'p1_c9');
        $this->assertCount(1, $casts);
        $this->assertSame('Lancer Goblin (exil)', $casts[0]->describe($s));
        $events = (new GameEngine(new AgentRegistry()))->advance($s, $casts[0])->events;
        $this->assertSame([], $s->player(1)->exile);
        $this->assertSame([], $s->exilePlays, 'la permission tombe avec la carte (nouvel objet)');
        $cast = $this->of($events, 'SpellCast')[0]->payload;
        $this->assertTrue($cast['fromExile']);
        $this->assertSame(1, $cast['owner']);
        $this->assertSame(1, $s->stack[0]->ownerId());

        (new StackResolver())->resolveTop($s);
        $this->assertSame(['p1_c9'], array_map(static fn (CardInstance $c) => $c->id, array_values(array_filter($s->player(0)->battlefield, static fn (CardInstance $c) => $c->isCreature()))),
            'la créature arrive chez celui qui l\'a lancée');
        $s->sacrifice(0, ['p1_c9']);
        $this->assertSame(['p1_c9'], array_map(static fn (CardInstance $c) => $c->id, $s->player(1)->graveyard), 'puis va au cimetière de son propriétaire');
    }

    public function testAnyManaPaysColoredSymbols(): void
    {
        $this->assertSame([], $this->exileActions($this->exiled(self::goblin('{R}')), 'p1_c9'), 'terrains incolores : {R} impayable');
        $s = $this->exiled(self::goblin('{R}'), anyMana: true);
        $this->assertSame('{1}', (string) $s->castCostFor(0, $s->player(1)->exile[0]));
        $this->assertCount(1, $this->exileActions($s, 'p1_c9'), 'Opposition Agent : n\'importe quel mana paie {R}');
    }

    public function testExiledLandIsPlayedOnlyWithAPlayPermission(): void
    {
        $land = new CardInstance('p1_c8', 'Wastes', CardInstance::LAND);
        $this->assertSame([], $this->exileActions($this->exiled($land), 'p1_c8'), 'Ragavan : lancer seulement, pas jouer un terrain');

        $s = $this->exiled($land, GameState::EXILE_PLAY_EXILED, lands: true);
        $plays = $this->exileActions($s, 'p1_c8');
        $this->assertCount(1, $plays);
        $this->assertSame('Jouer Wastes (exil)', $plays[0]->describe($s));
        $events = (new GameEngine(new AgentRegistry()))->advance($s, $plays[0])->events;
        $this->assertSame(1, $s->landsPlayed);
        $this->assertNotNull($s->player(0)->findOnBattlefield('p1_c8'));
        $played = $this->of($events, 'LandPlayed')[0]->payload;
        $this->assertSame([true, 1], [$played['fromExile'], $played['owner']]);
    }

    public function testTurnPermissionsExpireAtCleanupAndAreSerialized(): void
    {
        $s = $this->exiled(self::goblin());
        $s->player(1)->exile[] = $kept = new CardInstance('p1_c7', 'Bear', CardInstance::CREATURE, 2, null, 0, false, false, 2, 2);
        $s->grantExilePlay(0, $kept, GameState::EXILE_PLAY_EXILED, 'Opposition Agent', true, true);

        $copy = GameState::fromArray($s->toArray());
        $this->assertSame($s->exilePlays, $copy->exilePlays);
        $this->assertCount(2, $copy->exilePlayable(0));

        $s->expireExilePlays();
        $this->assertSame([['card' => 'p1_c7', 'player' => 0, 'until' => 'exiled', 'lands' => true, 'anyMana' => true]], $s->exilePlays);
        $this->assertArrayNotHasKey('exilePlays', (new ScenarioBuilder())->build(self::PASS)->toArray(), 'omis quand vide');
    }

    public function testAiCastsAnExiledCardInsteadOfPassingInItsMainPhase(): void
    {
        $s = $this->exiled(self::goblin());
        $s->phase = Phase::Main2;
        $legal = (new LegalActionGenerator())->for($s, 0);
        $choice = (new HeuristicAgent())->decide($s, 0, $legal);
        $this->assertInstanceOf(CastSpell::class, $choice);
        $this->assertSame('p1_c9', $choice->cardId);

        $s->activePlayer = 1; // pas sa phase principale : l'IA ne lance pas la créature exilée
        $this->assertInstanceOf(PassPriority::class, (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0)));
    }
}
