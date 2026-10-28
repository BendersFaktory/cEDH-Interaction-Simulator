<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Rules\LegalActionGenerator;
use App\Engine\Rules\StateBasedActions;
use PHPUnit\Framework\TestCase;

/**
 * Changement de contrôle d'un permanent (CR 108.4, prérequis de Wishclaw Talisman) : le propriétaire se lit dans l'id
 * (`p<n>_c<k>`), `GameState::gainControl()` déplace le permanent d'un champ de bataille à l'autre, et tout départ du champ
 * de bataille va dans la zone du propriétaire (CR 400.3), y compris quand le contrôleur est éliminé (CR 800.4a).
 */
final class ControlChangeTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    /** @param list<CardInstance> $cards @return list<string> */
    private static function ids(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->id, $cards);
    }

    private static function bear(string $id, bool $commander = false): CardInstance
    {
        return new CardInstance($id, 'Bear', CardInstance::CREATURE, 2, null, 0, false, $commander, 2, 2);
    }

    private static function talisman(string $id = 'p0_c50'): CardInstance
    {
        return new CardInstance($id, 'Talisman', CardInstance::ARTIFACT, 1);
    }

    /** J0 possède `p0_c50` (artefact) et `p0_c51` (créature), passés sous le contrôle de J1. */
    private function stolen(): GameState
    {
        $s = (new ScenarioBuilder())->permanent(0, self::talisman())->permanent(0, self::bear('p0_c51'))->build(self::PASS);
        $s->turn = 3;
        $this->assertTrue($s->gainControl(0, 'p0_c50', 1, 'Wishclaw Talisman'));
        $this->assertTrue($s->gainControl(0, 'p0_c51', 1, 'Wishclaw Talisman'));

        return $s;
    }

    public function testOwnerIsReadFromTheId(): void
    {
        $this->assertSame(2, self::bear('p2_c17')->ownerId());
        $this->assertSame(3, self::bear('p3_t4')->ownerId(), 'jeton : joueur sous le contrôle duquel il est arrivé');
        $this->assertNull(self::bear('bear')->ownerId());
        $this->assertSame(1, self::bear('bear')->ownerOr(1), 'id libre (tests) : le contrôleur fait foi');
    }

    public function testGainControlMovesThePermanentWithSummoningSickness(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::bear('p0_c51'))->build(self::PASS);
        $s->turn = 3;
        $s->player(0)->battlefield[0]->tapped = true;
        $s->drainEvents();

        $this->assertTrue($s->gainControl(0, 'p0_c51', 1, 'Wishclaw Talisman'));
        $this->assertFalse($s->gainControl(0, 'p0_c51', 1, 'Wishclaw Talisman'), 'plus chez J0');
        $this->assertSame([], self::ids($s->player(0)->battlefield));
        $this->assertSame(['p0_c51'], self::ids($s->player(1)->battlefield));
        $bear = $s->player(1)->battlefield[0];
        $this->assertTrue($bear->tapped, 'même objet, même état');
        $this->assertSame(3, $bear->enteredTurn, 'mal d\'invocation chez le nouveau contrôleur (CR 302.6)');
        $event = $this->of($s->drainEvents(), 'PermanentControlChanged')[0];
        $this->assertSame(1, $event->payload['player']);
        $this->assertSame(0, $event->payload['from']);
        $this->assertSame('p0_c51', $event->payload['card']['id']);
        $this->assertTrue($event->payload['tapped']);

        $copy = GameState::fromArray($s->toArray());
        $this->assertSame(['p0_c51'], self::ids($copy->player(1)->battlefield), 'sérialisé sans champ de plus');
        $this->assertSame(0, $copy->player(1)->battlefield[0]->ownerId());
    }

    public function testLeavingTheBattlefieldGoesToTheOwnersZones(): void
    {
        $s = $this->stolen();
        $s->drainEvents();
        $s->sacrifice(1, ['p0_c50']);
        $this->assertSame(['p0_c50'], self::ids($s->player(0)->graveyard), 'sacrifié par J1, au cimetière de J0');
        $this->assertSame([], $s->player(1)->graveyard);
        $this->assertSame(['player' => 1, 'card' => $s->player(0)->graveyard[0]->toPublic(), 'destination' => 'graveyard', 'owner' => 0],
            $this->of($s->drainEvents(), 'PermanentSacrificed')[0]->payload);

        $s = $this->stolen();
        $s->returnToHand(1, 'p0_c51', 'Chain of Vapor');
        $this->assertSame(['p0_c51'], self::ids($s->player(0)->hand));
        $returned = $this->of($s->drainEvents(), 'PermanentReturnedToHand')[0]->payload;
        $this->assertSame(0, $returned['owner']);
        $this->assertSame(1, $returned['hand'], 'taille de la main du propriétaire');

        $s = $this->stolen();
        $s->exilePermanent(1, 'p0_c51', 'Swords to Plowshares');
        $this->assertSame(['p0_c51'], self::ids($s->player(0)->exile));
        $this->assertSame(0, $this->of($s->drainEvents(), 'CardExiled')[0]->payload['owner']);

        $s = $this->stolen();
        $s->destroyPermanent(1, 'p0_c50', 'Pyroblast');
        $this->assertSame(['p0_c50'], self::ids($s->player(0)->graveyard));
        $this->assertSame(0, $this->of($s->drainEvents(), 'PermanentDestroyed')[0]->payload['owner']);
    }

    public function testOwnerFieldIsOmittedWhenTheControllerOwnsIt(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::talisman())->build(self::PASS);
        $s->drainEvents();
        $s->sacrifice(0, ['p0_c50']);
        $this->assertArrayNotHasKey('owner', $this->of($s->drainEvents(), 'PermanentSacrificed')[0]->payload, 'empreintes inchangées');
    }

    public function testStolenCreatureDiesIntoItsOwnersGraveyardAndTriggersForItsController(): void
    {
        $s = $this->stolen();
        $s->player(1)->battlefield[1]->damage = 2;
        $s->drainEvents();
        (new StateBasedActions(new AgentRegistry(), new LegalActionGenerator()))->check($s);
        $this->assertSame(['p0_c51'], self::ids($s->player(0)->graveyard));
        $died = $this->of($s->drainEvents(), 'CreatureDied')[0]->payload;
        $this->assertSame(1, $died['player']);
        $this->assertSame(0, $died['owner']);
    }

    public function testStolenCommanderReturnsToItsOwnersCommandZone(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::bear('p0_c0', commander: true))->build(self::PASS);
        $s->gainControl(0, 'p0_c0', 1, 'test');
        $s->player(1)->battlefield[0]->damage = 2;
        $s->drainEvents();
        (new StateBasedActions(new AgentRegistry(), new LegalActionGenerator()))->check($s);
        $this->assertSame(['p0_c0'], self::ids($s->player(0)->command), 'cimetière de J0, puis sa zone de commandement');
        $this->assertSame(0, $this->of($s->drainEvents(), 'CommanderReturned')[0]->payload['player']);
    }

    public function testEliminatedControllerGivesBackWhatItControlsAndTakesAwayWhatItOwns(): void
    {
        $s = $this->stolen();
        $s->player(0)->battlefield[] = self::bear('p1_c60');
        $s->gainControl(0, 'p1_c60', 2, 'test'); // la créature de J1 chez J2
        $s->player(1)->life = 0;
        $s->drainEvents();
        (new StateBasedActions(new AgentRegistry(), new LegalActionGenerator()))->check($s);

        $this->assertSame(['p0_c50', 'p0_c51'], self::ids($s->player(0)->battlefield), 'revenus à J0, leur propriétaire');
        $this->assertSame([], $s->player(2)->battlefield, 'la carte de J1 quitte la partie, même contrôlée par J2');
        $events = $s->drainEvents();
        $this->assertCount(2, $this->of($events, 'PermanentControlChanged'));
        $this->assertSame('player_left', $this->of($events, 'PermanentControlChanged')[0]->payload['source']);
        $this->assertSame(['p1_c60'], $this->of($events, 'PlayerLeftGame')[0]->payload['battlefield']);
    }
}
