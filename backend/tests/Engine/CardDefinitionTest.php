<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Card\CardDefinition;
use App\Engine\Card\Decks;
use PHPUnit\Framework\TestCase;

/** `CardDefinition` : données JSON ↔ définition ↔ instance, sans changer le format sérialisé d'une carte. */
final class CardDefinitionTest extends TestCase
{
    public function testEveryDemoCardSurvivesAJsonRoundTripOfItsDefinition(): void
    {
        foreach ([0, 1, 2, 3] as $p) {
            $deck = Decks::commanderDemo($p);
            foreach ([$deck['commander'], ...$deck['library']] as $card) {
                $def = CardDefinition::fromArray(json_decode(json_encode($this->definitionOf($card)), true));
                $this->assertSame($card->toArray(), $def->instantiate($card->id, $card->commander)->toArray(), $card->name);
            }
        }
    }

    public function testSpellEffectIsDeclaredInData(): void
    {
        $bolt = CardDefinition::fromArray([
            'name' => 'Lightning Bolt', 'type' => 'instant', 'cost' => 1, 'manaCost' => '{R}', 'spell' => ['effect' => 'damage', 'amount' => 3],
        ])->instantiate('c1');

        $this->assertSame('damage', $bolt->effect);
        $this->assertSame(3, $bolt->amount);
        $this->assertTrue($bolt->isInstantSpeed());
    }

    public function testUnknownSpellEffectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CardDefinition::fromArray(['name' => 'Truc', 'type' => 'instant', 'spell' => ['effect' => 'explode']]);
    }

    private function definitionOf(\App\Engine\Game\CardInstance $c): array
    {
        return (new CardDefinition(
            $c->name, count($c->types) === 1 ? $c->types[0] : $c->types, $c->cost, $c->manaCost, $c->power, $c->toughness, $c->effect,
            $c->amount, $c->adds, $c->produces, $c->abilities, $c->triggers, $c->subtypes, $c->keywords, $c->supertypes,
        ))->toArray();
    }
}
