<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PlayLand;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\PlayerAgent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Event\GameEvent;
use App\Engine\Game\GameState;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use PHPUnit\Framework\TestCase;

/** Sources qui blessent (Ancient Tomb, Mana Confluence), shocklands et fetchlands. */
final class PainLandsTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function tomb(string $id = 'tomb'): CardInstance
    {
        return new CardInstance($id, 'Ancient Tomb', CardInstance::LAND, abilities: [ActivatedAbility::mana('{C}{C}', null, 2)]);
    }

    private static function confluence(string $id = 'conf'): CardInstance
    {
        return new CardInstance($id, 'Mana Confluence', CardInstance::LAND, produces: ['C'], abilities: [ActivatedAbility::mana('{W}|{U}|{B}|{R}|{G}', null, 0, 1)]);
    }

    private static function plains(string $id): CardInstance
    {
        return new CardInstance($id, 'Plains', CardInstance::LAND, produces: ['W']);
    }

    private static function shock(string $id = 'vents'): CardInstance
    {
        return new CardInstance($id, 'Steam Vents', CardInstance::LAND, produces: ['U', 'R'], subtypes: [CardInstance::LAND => ['Island', 'Mountain']], untappedLifeCost: 2);
    }

    private static function delta(string $id = 'delta'): CardInstance
    {
        return new CardInstance($id, 'Polluted Delta', CardInstance::LAND, abilities: [ActivatedAbility::fetch('Island|Swamp', false, 1)]);
    }

    /** @return list<GameEvent> */
    private static function ofType(GameState $s, string $type): array
    {
        return array_values(array_filter($s->drainEvents(), static fn (GameEvent $e) => $e->type === $type));
    }

    /** Agent : joue une fois l'action d'id donné, puis passe. */
    private static function playOnce(string $actionId): PlayerAgent
    {
        return new class ($actionId) implements PlayerAgent {
            private bool $done = false;

            public function __construct(private readonly string $id)
            {
            }

            public function decide(GameState $state, int $playerId, array $legal): Action
            {
                foreach ($legal as $a) {
                    if (!$this->done && $a->id() === $this->id) {
                        $this->done = true;

                        return $a;
                    }
                }

                return AutoPassAgent::pass($legal, $playerId);
            }
        };
    }

    public function testManualPainManaDealsDamageToItsController(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::tomb())->build(self::PASS);
        $s->drainEvents();
        $this->assertCount(1, $s->player(0)->battlefield[0]->abilities()); // pas de {C} implicite sans douleur
        $tomb = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof ActivateAbility))[0];
        AbilityManager::activate($s, $tomb);

        $this->assertSame(18, $s->player(0)->life);
        $this->assertSame(2, $s->player(0)->pool->total());
        $damage = self::ofType($s, 'DamageDealt');
        $this->assertSame(['source' => 'Ancient Tomb', 'target' => 'player:0', 'amount' => 2, 'life' => 18], $damage[0]->payload);
    }

    public function testAutoPaymentUsesPainfulSourcesOnlyAsALastResort(): void
    {
        $p = (new ScenarioBuilder())->permanent(0, self::tomb())->permanent(0, self::plains('pl1'))->permanent(0, self::plains('pl2'))->build(self::PASS)->player(0);
        $plan = ManaPayment::plan($p, ManaCost::parse('{2}'));
        $this->assertNotNull($plan);
        $this->assertSame(['pl1', 'pl2'], array_column($plan->taps, 'card'));

        // Trois mana : il faut le Tomb ; à 2 PV il tuerait son contrôleur, donc il n'est plus proposé.
        $this->assertContains('tomb', array_column(ManaPayment::plan($p, ManaCost::parse('{3}'))?->taps ?? [], 'card'));
        $p->life = 2;
        $this->assertNull(ManaPayment::plan($p, ManaCost::parse('{3}')));
    }

    public function testCastingWithALifeCostSourcePaysLife(): void
    {
        $bolt = new CardInstance('b1', 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, manaCost: '{R}');
        $s = (new ScenarioBuilder())->hand(0, $bolt)->permanent(0, self::confluence())->build(self::PASS);
        $s->controllers[0] = 'bolt';
        $result = (new GameEngine(new AgentRegistry(['bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')])))->advance($s);

        $paid = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'LifePaid'));
        $this->assertSame(['player' => 0, 'amount' => 1, 'life' => 19, 'source' => 'Mana Confluence'], $paid[0]->payload);
        $this->assertSame(17, $s->player(2)->life);
    }

    public function testLifeCostNeedsThatMuchLife(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::confluence())->build(self::PASS);
        $card = $s->player(0)->battlefield[0];
        $s->player(0)->life = 0;
        $this->assertFalse(AbilityManager::canActivate($s, $s->player(0), $card, $card->abilities()[1]));
        $s->player(0)->life = 1;
        $this->assertTrue(AbilityManager::canActivate($s, $s->player(0), $card, $card->abilities()[1]));
    }

    public function testShocklandOffersPayingOrEnteringTapped(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::shock())->build(self::PASS);
        $lands = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof PlayLand));
        $this->assertSame(['land:0:vents:life', 'land:0:vents:tapped'], array_map(static fn (PlayLand $a) => $a->id(), $lands));

        $s->controllers[0] = 'pay';
        $result = (new GameEngine(new AgentRegistry(['pay' => self::playOnce('land:0:vents:life')])))->advance($s);
        $this->assertSame(18, $s->player(0)->life);
        $this->assertFalse($s->player(0)->findOnBattlefield('vents')->tapped);
        $played = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'LandPlayed'))[0];
        $this->assertArrayNotHasKey('tapped', $played->payload);

        $s = (new ScenarioBuilder())->hand(0, self::shock())->build(self::PASS);
        $s->controllers[0] = 'tapped';
        $result = (new GameEngine(new AgentRegistry(['tapped' => self::playOnce('land:0:vents:tapped')])))->advance($s);
        $this->assertSame(20, $s->player(0)->life);
        $played = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'LandPlayed'))[0];
        $this->assertTrue($played->payload['tapped']);

        // À 1 PV, seule la variante engagée reste (on ne paie pas des PV qu'on n'a pas).
        $s = (new ScenarioBuilder())->hand(0, self::shock())->life(1)->build(self::PASS);
        $lands = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof PlayLand));
        $this->assertSame(['land:0:vents:tapped'], array_map(static fn (PlayLand $a) => $a->id(), $lands));
    }

    public function testFetchlandSearchesAChosenLandAndShuffles(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::delta())->build(self::PASS);
        $p = $s->player(0);
        $p->library[] = self::shock('vents');
        $p->library[] = self::plains('pl');
        $size = count($p->library);

        // Fetchland : aucun mana, et une cible par terrain qui convient (shockland : payer ou non).
        $this->assertCount(1, $p->battlefield[0]->abilities());
        $fetches = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof ActivateAbility));
        $this->assertSame([['library:vents', 'life'], ['library:vents']], array_map(static fn (ActivateAbility $a) => $a->targets, $fetches));

        $this->assertSame('Activer Polluted Delta ({T}, sacrifier, payer 1 PV : chercher Island ou Swamp) → Steam Vents (payer 2 PV)', $fetches[0]->describe($s));
        $this->assertSame('Activer Polluted Delta ({T}, sacrifier, payer 1 PV : chercher Island ou Swamp) → Steam Vents (engagé)', $fetches[1]->describe($s));
        $s->drainEvents();
        AbilityManager::activate($s, $fetches[0]);
        $this->assertSame(19, $p->life); // coût : 1 PV
        $this->assertNull($p->findOnBattlefield('delta')); // sacrifiée
        (new StackResolver())->resolveTop($s);

        $this->assertSame(17, $p->life); // + 2 PV pour la shockland
        $vents = $p->findOnBattlefield('vents');
        $this->assertNotNull($vents);
        $this->assertFalse($vents->tapped);
        $this->assertCount($size - 1, $p->library);
        $events = array_map(static fn (GameEvent $e) => $e->type, $s->drainEvents());
        // 1 PV à l'activation (coût), puis terrain cherché, 2 PV de la shockland et mélange.
        $this->assertSame(['LifePaid', 'LandFetched', 'LifePaid', 'LibraryShuffled'], array_values(array_intersect($events, ['LandFetched', 'LifePaid', 'LibraryShuffled'])));
    }

    public function testFetchWithNothingToFindStillShuffles(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::delta())->build(self::PASS);
        $fetches = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof ActivateAbility));
        $this->assertSame([['library:none']], array_map(static fn (ActivateAbility $a) => $a->targets, $fetches));
        AbilityManager::activate($s, $fetches[0]);
        $s->drainEvents();
        (new StackResolver())->resolveTop($s);
        $this->assertSame(['LibraryShuffled'], array_values(array_intersect(array_map(static fn (GameEvent $e) => $e->type, $s->drainEvents()), ['LandFetched', 'LibraryShuffled'])));
    }

    public function testStateWithShocklandAndFetchRoundTrips(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::delta())->permanent(0, self::shock())->permanent(0, self::tomb())->build(self::PASS);
        $this->assertSame($s->toArray(), GameState::fromArray($s->toArray())->toArray());
    }

    public function testHeuristicAgentFetchesAndPaysShocksAboveTheFloor(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::delta())->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass']);
        $s->player(0)->library[] = self::shock('vents');
        $action = (new \App\Engine\Agent\HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertSame('ability:0:delta:0:0:library:vents,life', $action->id());

        $s->player(0)->life = 8;
        $action = (new \App\Engine\Agent\HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertSame('ability:0:delta:0:0:library:vents', $action->id());
    }

    public function testCastSpellIsUnaffected(): void
    {
        // Garde-fou : un sort payé sans source douloureuse n'émet ni LifePaid ni DamageDealt vers son lanceur.
        $bolt = new CardInstance('b1', 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3, manaCost: '{R}');
        $s = (new ScenarioBuilder())->hand(0, $bolt)->permanent(0, new CardInstance('m', 'Mountain', CardInstance::LAND, produces: ['R']))->build(self::PASS);
        $s->controllers[0] = 'bolt';
        $result = (new GameEngine(new AgentRegistry(['bolt' => new ScriptedAgent('Lightning Bolt', 'player:2')])))->advance($s);
        $this->assertSame([], array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'LifePaid')));
        $this->assertSame(20, $s->player(0)->life);
        $this->assertInstanceOf(CastSpell::class, new CastSpell(0, 'b1'));
    }
}
