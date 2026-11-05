<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Card\SpellEffect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Le constructeur de `SpellEffect` refuse les options qui n'ont pas de sens pour l'effet (erreur du `CardMapper`). */
final class SpellEffectValidationTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): SpellEffect}> */
    public static function invalid(): iterable
    {
        yield 'filtre de tuteur inconnu' => [fn () => new SpellEffect('tutor', tutorFilter: 'land')];
        yield 'perte de PV négative' => [fn () => new SpellEffect('tutor', lifeLoss: -1)];
        yield 'coût à payer mal écrit' => [fn () => new SpellEffect('counter', payCost: '{X}')];
        yield 'sélection sans nombre' => [fn () => new SpellEffect('put_back')];
        yield 'pact sans coût' => [fn () => new SpellEffect('pact')];
        yield 'option de contresort hors contresort' => [fn () => new SpellEffect('damage', 3, manaValue: 1)];
        yield 'destination de sort contré inconnue' => [fn () => new SpellEffect('counter', counterTo: 'hand')];
        yield 'option de rituel hors mana' => [fn () => new SpellEffect('damage', 3, perGraveyardNamed: true)];
        yield 'freeCastMax négatif' => [fn () => new SpellEffect('tutor', freeCastMax: -1)];
        yield 'discardRandom hors tuteur' => [fn () => new SpellEffect('damage', 3, discardRandom: true)];
        yield 'couleur bleue inconnue' => [fn () => new SpellEffect('counter', blue: 'red')];
        yield 'mode négatif' => [fn () => new SpellEffect('damage', 3, mode: -1)];
        yield 'powerLife hors exil' => [fn () => new SpellEffect('damage', 3, powerLife: true)];
        yield 'redirect sans genre de cible' => [fn () => new SpellEffect('redirect')];
        yield 'chain hors bounce' => [fn () => new SpellEffect('damage', 3, chain: true)];
        yield 'gift sans jeton' => [fn () => new SpellEffect('gift')];
        yield 'mots-clés sans jeton' => [fn () => new SpellEffect('damage', 3, tokenKeywords: ['flying'])];
    }

    /** @param \Closure(): SpellEffect $build */
    #[DataProvider('invalid')]
    public function testOptionIncoherenteRefusee(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }
}
