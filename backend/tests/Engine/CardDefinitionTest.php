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

        $this->assertSame(['effect' => 'damage', 'amount' => 3], $bolt->effect('damage')?->toArray());
        $this->assertCount(1, $bolt->effects);
        $this->assertTrue($bolt->isInstantSpeed());
    }

    public function testUnknownSpellEffectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CardDefinition::fromArray(['name' => 'Truc', 'type' => 'instant', 'spell' => ['effect' => 'explode']]);
    }

    public function testSeveralEffectsRoundTripAndKeepSingleEffectFormat(): void
    {
        $data = ['name' => 'Choc et pioche', 'type' => 'instant', 'cost' => 2, 'manaCost' => '{1}{R}',
            'effects' => [['effect' => 'damage', 'amount' => 2], ['effect' => 'draw', 'amount' => 1]]];
        $def = CardDefinition::fromArray($data);
        $this->assertSame(['damage', 'draw'], array_map(static fn ($e) => $e->effect, $def->effects));
        $this->assertSame($data['effects'], $def->toArray()['effects']);
        $this->assertArrayNotHasKey('spell', $def->toArray());

        // Sérialisation de partie : premier effet sous les clés historiques, liste complète sous `effects`.
        $card = $def->instantiate('c1')->toArray();
        $this->assertSame(['damage', 2], [$card['effect'], $card['amount']]);
        $this->assertSame($data['effects'], $card['effects']);
        $this->assertSame($card, \App\Engine\Game\CardInstance::fromArray($card)->toArray());

        // Un seul effet : aucune clé `effects` (format et empreintes inchangés).
        $this->assertArrayNotHasKey('effects', CardDefinition::fromArray(['name' => 'Bolt', 'type' => 'instant', 'spell' => ['effect' => 'damage', 'amount' => 3]])->instantiate('c2')->toArray());
    }

    public function testTwoTargetedEffectsAreKeptInTextOrder(): void
    {
        $card = CardDefinition::fromArray(['name' => 'Double', 'type' => 'instant', 'effects' => [['effect' => 'damage', 'amount' => 1], ['effect' => 'counter']]])->instantiate('d1');

        $this->assertSame(['damage', 'counter'], array_map(static fn ($e) => $e->effect, $card->targetedEffects()), 'Une cible par effet ciblé (CR 608.2b)');
    }

    private function definitionOf(\App\Engine\Game\CardInstance $c): array
    {
        return (new CardDefinition(
            $c->name, count($c->types) === 1 ? $c->types[0] : $c->types, $c->cost, $c->manaCost, $c->power, $c->toughness, null,
            0, null, $c->produces, $c->abilities, $c->triggers, $c->subtypes, $c->keywords, $c->supertypes, $c->effects,
        ))->toArray();
    }
}
