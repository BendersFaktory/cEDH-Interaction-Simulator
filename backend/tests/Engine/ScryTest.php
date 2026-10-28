<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\ScryArrange;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Scry (CR 701.22) : le contrôleur du sort range le dessus de sa bibliothèque avant la résolution (une décision), puis le sort
 * se résout (Preordain pioche après son Scry).
 */
final class ScryTest extends TestCase
{
    private const PASS = [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function preordain(): CardInstance
    {
        return new CardInstance('pre', 'Preordain', CardInstance::SORCERY, 1, effects: [new SpellEffect('scry', 2), new SpellEffect('draw', 1)]);
    }

    /** Preordain sur la pile de J0, tous les joueurs ont passé, bibliothèque a, b, c (a en premier). */
    private static function resolving(): \App\Engine\Game\GameState
    {
        $s = (new ScenarioBuilder())->build(self::PASS);
        $s->player(0)->hand = [];
        $s->player(0)->library = [new CardInstance('a', 'Card A', CardInstance::CREATURE, 1, power: 1, toughness: 1),
            new CardInstance('b', 'Card B', CardInstance::CREATURE, 1, power: 1, toughness: 1),
            new CardInstance('c', 'Card C', CardInstance::CREATURE, 1, power: 1, toughness: 1)];
        $s->stack[] = new StackItem('st', self::preordain(), 0);
        $s->priority = new PriorityState(0, [0, 1, 2], true);

        return $s;
    }

    public function testTheTopTwoCardsCanGoInFiveArrangements(): void
    {
        $s = self::resolving();
        $s->scryPending = true;

        $arrangements = array_values(array_filter((new LegalActionGenerator())->for($s, 0), static fn ($a) => $a instanceof ScryArrange));

        $this->assertCount(5, $arrangements, 'Dessus seul (a, b, puis b, a), un seul gardé (a ou b), rien gardé');
        $this->assertSame(['a', 'b'], $arrangements[0]->kept, 'Le premier choix garde tout dans l\'ordre (agent passif)');
    }

    public function testBottomedCardsGoUnderTheLibraryAndTheSpellDrawsAfterwards(): void
    {
        $s = self::resolving();
        $s->scryPending = true;

        $result = (new GameEngine(new AgentRegistry()))->advance($s, new ScryArrange(0, ['b'], 2));

        $types = array_map(static fn ($e) => $e->type, $result->events);
        $scried = $result->events[array_search('Scried', $types, true)];
        $this->assertSame([1, 1], [$scried->payload['top'], $scried->payload['bottom']]);
        $drawn = $result->events[array_search('CardDrawn', $types, true)];
        $this->assertSame('b', $drawn->payload['card']['id'], 'Preordain pioche après son Scry : la carte gardée sur le dessus');
        $this->assertLessThan(array_search('SpellResolved', $types, true), array_search('Scried', $types, true), 'Le rangement précède la résolution');
    }

    public function testTheAiKeepsNonLandsOnTopAndBottomsLands(): void
    {
        $s = self::resolving();
        $s->player(0)->library = [new CardInstance('l', 'Forest', CardInstance::LAND, 0), new CardInstance('x', 'Card X', CardInstance::CREATURE, 1, power: 1, toughness: 1),
            new CardInstance('y', 'Card Y', CardInstance::CREATURE, 1, power: 1, toughness: 1)];
        $s->scryPending = true;

        $action = (new HeuristicAgent())->decide($s, 0, (new LegalActionGenerator())->for($s, 0));

        $this->assertSame(['x'], $action->kept, 'Le terrain part dessous, les autres restent dessus');
    }
}
