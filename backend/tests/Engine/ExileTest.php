<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StackResolver;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/**
 * Zone d'exil (ROADMAP §1) : `GameState::exile()` / `exilePermanent()`, événement `CardExiled`, effet de sort `exile`
 * (ciblage `permanent:<id>`, résolution, IA), jeton qui cesse d'exister, commandant renvoyé par les SBA (CR 903.9a).
 */
final class ExileTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** Swords to Plowshares simplifié : exile la créature ciblée (le gain de PV n'est pas modélisé). */
    private static function swords(string $id, string $target = 'creature'): CardInstance
    {
        // Les actions sont dédoublonnées par (nom, cibles) : le nom suit le genre de cible.
        $name = $target === 'creature' ? 'Swords to Plowshares' : 'Test Exile Permanent';

        return new CardInstance($id, $name, CardInstance::INSTANT, 1, effects: [new SpellEffect('exile', target: $target)]);
    }

    private static function artifact(string $id): CardInstance
    {
        return new CardInstance($id, 'Test Rock', CardInstance::ARTIFACT, 2);
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @return list<list<string>> cibles des lancements de `$cardId` proposés au joueur 0 */
    private function castTargets(GameState $s, string $cardId): array
    {
        $out = [];
        foreach ((new LegalActionGenerator())->for($s, 0) as $action) {
            if ($action instanceof CastSpell && $action->cardId === $cardId) {
                $out[] = $action->targets;
            }
        }

        return $out;
    }

    public function testExileEffectSerializesItsTargetKind(): void
    {
        $effect = new SpellEffect('exile', target: 'creature');
        $this->assertTrue($effect->isTargeted());
        $this->assertSame(['effect' => 'exile', 'target' => 'creature'], $effect->toArray());
        $this->assertEquals($effect, SpellEffect::fromArray($effect->toArray()));
        $this->assertSame(['effect' => 'exile'], (new SpellEffect('exile'))->toArray(), '`target` omis à son défaut');

        // Un sort à un seul effet porte `effects` dès que l'effet a une précision que les clés historiques n'ont pas.
        $card = self::swords('s');
        $this->assertSame([['effect' => 'exile', 'target' => 'creature']], $card->toPublic()['effects']);
        $this->assertSame('creature', CardInstance::fromArray($card->toArray())->effects[0]->target);
        $this->assertArrayNotHasKey('effects', ScenarioBuilder::bolt('b')->toPublic(), 'format inchangé pour les autres sorts');
    }

    public function testInvalidTargetKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SpellEffect('damage', 3, target: 'creature');
    }

    public function testTargetsAreCreaturesOrAnyPermanentOfLivingPlayers(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::swords('stp'), self::swords('path', 'permanent'))->untappedLands(0, 1)
            ->creature(1, 'c1', 2, 2)->permanent(1, self::artifact('a1'))->creature(2, 'c2', 1, 1)->build(self::PASS);
        $s->player(2)->lost = true;

        $this->assertSame([['permanent:c1']], $this->castTargets($s, 'stp'), 'créature d\'un joueur en vie seulement');
        $this->assertSame([['permanent:p0_land0'], ['permanent:a1'], ['permanent:c1']], $this->castTargets($s, 'path'), 'tout permanent, les siens compris');
        $cast = new CastSpell(0, 'stp', ['permanent:c1']);
        $this->assertSame('Lancer Swords to Plowshares → Test Creature (joueur 1)', $cast->describe($s));
    }

    public function testResolvingExilesTheCreatureWithoutDiesTriggers(): void
    {
        $myr = new CardInstance('myr', 'Perilous Myr', CardInstance::CREATURE, 2, null, 0, true, false, 1, 1,
            triggers: [new TriggeredAbility(TriggeredAbility::DIES, 'damage', 2)]);
        $myr->damage = 1;
        $myr->powerBonus = 2;
        $s = (new ScenarioBuilder())->permanent(1, $myr)->build(self::PASS);
        $s->stack[] = new StackItem('s1', self::swords('stp'), 0, ['permanent:myr']);

        (new StackResolver())->resolveTop($s);
        $events = $s->drainEvents();
        $this->assertSame(['SpellResolved', 'CardExiled'], array_map(static fn (GameEvent $e) => $e->type, $events));
        $this->assertSame('graveyard', $events[0]->payload['destination'], 'le sort va au cimetière de son lanceur');
        $this->assertSame([
            'player' => 1, 'card' => $myr->toPublic(), 'from' => 'battlefield', 'destination' => 'exile',
            'source' => 'Swords to Plowshares', 'exile' => 1,
        ], $events[1]->payload);
        $this->assertNull($s->player(1)->findOnBattlefield('myr'));
        $this->assertSame([$myr], $s->player(1)->exile);
        $this->assertSame([false, 0, 0], [$myr->tapped, $myr->damage, $myr->powerBonus], 'objet neuf en exil');
        $this->assertSame([], $s->pendingTriggers, 'exilée, elle ne meurt pas');
        $this->assertSame('stp', $s->player(0)->graveyard[0]->id);
    }

    public function testTargetGoneOrNoLongerLegalFizzles(): void
    {
        $s = (new ScenarioBuilder())->permanent(1, self::artifact('a1'))->build(self::PASS);
        $s->stack[] = new StackItem('s1', self::swords('stp'), 0, ['permanent:a1']); // n'est plus une créature
        $s->stack[] = new StackItem('s2', self::swords('stp2'), 0, ['permanent:gone']);

        (new StackResolver())->resolveTop($s);
        (new StackResolver())->resolveTop($s);
        $fizzled = $this->of($s->drainEvents(), 'SpellFizzled');
        $this->assertSame([['target' => 'permanent:gone'], ['target' => 'permanent:a1']], array_map(static fn (GameEvent $e) => $e->payload, $fizzled));
        $this->assertNotNull($s->player(1)->findOnBattlefield('a1'));
        $this->assertSame([], $s->player(1)->exile);
    }

    public function testExiledTokenCeasesToExist(): void
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $token = $s->createToken(1, 'Spirit', CardInstance::CREATURE, 1, 1);
        $s->drainEvents();

        $this->assertTrue($s->exilePermanent(1, $token->id, 'Path to Exile'));
        $exiled = $this->of($s->drainEvents(), 'CardExiled');
        $this->assertSame('none', $exiled[0]->payload['destination']);
        $this->assertSame(0, $exiled[0]->payload['exile']);
        $this->assertSame([], $s->player(1)->exile);
        $this->assertSame('none', $s->exile(1, $token));
        $this->assertFalse($s->exilePermanent(1, $token->id, 'Path to Exile'), 'déjà parti');
    }

    public function testExiledCommanderReturnsToTheCommandZoneThroughStateBasedActions(): void
    {
        $commander = ScenarioBuilder::commanderCard('cmd');
        $s = (new ScenarioBuilder())->permanent(1, $commander)->build(self::PASS);

        $s->exilePermanent(1, 'cmd', 'Swords to Plowshares');
        $this->assertSame([$commander], $s->player(1)->exile, 'le commandant passe vraiment par l\'exil');
        $s->drainEvents();
        (new StateBasedActions(new AgentRegistry(), new LegalActionGenerator()))->check($s);
        $events = $s->drainEvents();
        $this->assertSame(['player' => 1, 'card' => $commander->toPublic(), 'from' => 'exile'], $this->of($events, 'CommanderReturned')[0]->payload);
        $this->assertSame([], $s->player(1)->exile);
        $this->assertSame('cmd', $s->player(1)->command[0]->id);
    }

    public function testExileZoneRoundTripsAndIsOmittedWhenEmpty(): void
    {
        $s = (new ScenarioBuilder())->creature(1, 'c1', 2, 2)->build(self::PASS);
        $this->assertArrayNotHasKey('exile', $s->player(1)->toArray(), 'omise vide : empreintes inchangées');

        $s->exilePermanent(1, 'c1', 'Swords to Plowshares');
        $array = $s->toArray();
        $this->assertSame('c1', $array['players'][1]['exile'][0]['id']);
        $copy = GameState::fromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true));
        $this->assertSame('c1', $copy->player(1)->exile[0]->id);
        $this->assertSame(json_encode($array), json_encode($copy->toArray()));
    }

    public function testAiExilesTheMostThreateningOpposingCreatureNeverItsOwn(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::swords('stp', 'permanent'))->untappedLands(0, 1)
            ->creature(0, 'mine', 6, 6)->creature(1, 'small', 1, 1)->creature(2, 'big', 5, 5)->build(self::PASS);

        $action = (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame(['permanent:big'], $action->targets);
    }

    public function testAiCountersAnExileAimedAtItsPermanent(): void
    {
        $s = (new ScenarioBuilder())->hand(1, ScenarioBuilder::counterspell('cs'))->untappedLands(1, 2)->creature(1, 'c1', 2, 2)->build(self::PASS);
        $s->stack[] = new StackItem('s1', self::swords('stp'), 0, ['permanent:c1']);
        $s->priority = new PriorityState(1);

        $action = (new HeuristicAgent())->decide($s, 1, (new LegalActionGenerator())->for($s, 1));
        $this->assertInstanceOf(CastSpell::class, $action);
        $this->assertSame(['stack:s1'], $action->targets);
    }
}
