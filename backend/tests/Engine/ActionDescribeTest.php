<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PregameChoice;
use App\Engine\Action\PutTrigger;
use App\Engine\Action\ScryArrange;
use App\Engine\Card\SpellEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use PHPUnit\Framework\TestCase;

/** Libellés des actions (journal et boutons du front) : cibles et coûts lisibles, ids absents remplacés par leur id brut. */
final class ActionDescribeTest extends TestCase
{
    private static function state(): GameState
    {
        $island = new CardInstance('isl1', 'Island', CardInstance::LAND);
        $island->tapped = true;
        $s = (new ScenarioBuilder())
            ->hand(0, ScenarioBuilder::bolt('bolt'), new CardInstance('bs', 'Brainstorm', CardInstance::INSTANT, 1, 'draw', 3),
                new CardInstance('pyro', 'Pyroblast', CardInstance::INSTANT, 1, effects: [new SpellEffect('counter', mode: 1), new SpellEffect('destroy', mode: 2, target: 'permanent')]),
                new CardInstance('gc', 'Gemstone Caverns', CardInstance::LAND))
            ->permanent(0, $island)
            ->creature(1, 'bear', 2, 2)
            ->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->player(1)->graveyard[] = new CardInstance('dead', 'Dead Card', CardInstance::CREATURE, 1, power: 1, toughness: 1);

        return $s;
    }

    public function testLancerUnSortAvecCoutAlternatifModeEtCibles(): void
    {
        $s = self::state();

        self::assertStringContainsString('(X=2)', (new CastSpell(0, 'bolt', x: 2))->describe($s));
        self::assertStringContainsString('renvoyer en main Island engagée, ghost', (new CastSpell(0, 'bolt', alt: CastSpell::ALT_RETURN . 'isl1,ghost'))->describe($s));
        self::assertStringContainsString('(coût alternatif : exil de Brainstorm)', (new CastSpell(0, 'bolt', alt: CastSpell::ALT_PITCH . 'bs'))->describe($s));
        self::assertStringContainsString('(mode 1 : counter)', (new CastSpell(0, 'pyro', mode: 1))->describe($s));
        self::assertSame(
            'Lancer Lightning Bolt → permanent ghost, sorts a, b, joueurs 1 et 2, aucune carte',
            (new CastSpell(0, 'bolt', ['permanent:ghost', 'stack:a+b', 'players:1+2', 'library:ghost']))->describe($s),
        );
    }

    public function testActiverUneCapaciteNommeCoutsEtCibles(): void
    {
        $s = self::state();

        self::assertSame('Activer Island en sacrifiant Island, Brainstorm, ghost', (new ActivateAbility(0, 'isl1', 5, costChoices: ['isl1', 'bs', 'ghost']))->describe($s));
        self::assertSame('Activer Island → Test Creature (joueur 1)', (new ActivateAbility(0, 'isl1', 5, targets: ['permanent:bear']))->describe($s));
        self::assertSame('Activer Island → Dead Card (cimetière du joueur 1), {B}', (new ActivateAbility(0, 'isl1', 5, targets: ['card:dead', 'mana:B']))->describe($s));
        self::assertFalse((new ActivateAbility(0, 'ghost', 0))->isManaAbility($s));
    }

    public function testMettreUnTriggerSurLaPile(): void
    {
        $s = self::state();

        self::assertSame('Mettre sur la pile : t1 → Land', (new PutTrigger(0, 't1', ['library:p0_lib0']))->describe($s));
        self::assertSame('Mettre sur la pile : t1 → rien à chercher', (new PutTrigger(0, 't1', ['library:ghost']))->describe($s));
        self::assertSame('Mettre sur la pile : t1 → Test Creature', (new PutTrigger(0, 't1', ['permanent:bear']))->describe($s));
        self::assertSame('Mettre sur la pile : t1', (new PutTrigger(0, 't1'))->describe($s));
    }

    public function testScryEtChoixDAvantPartie(): void
    {
        $s = self::state();

        $scry = new ScryArrange(0, ['p0_lib1'], 2);
        self::assertSame('scry:0:2:p0_lib1', $scry->id());
        self::assertSame('Scry 2 : dessus = Land ; dessous : 1 carte(s)', $scry->describe($s));
        self::assertSame('Scry 1 : dessus = aucune ; dessous : 1 carte(s)', (new ScryArrange(0, [], 1))->describe($s));
        self::assertSame(['type' => 'scry', 'player' => 0, 'kept' => ['p0_lib1'], 'count' => 2], $scry->toArray());

        $keep = new PregameChoice(0, 'gc', null);
        $play = new PregameChoice(0, 'gc', 'bs');
        self::assertSame('pregame:0:gc:no', $keep->id());
        self::assertSame('Garder Gemstone Caverns en main', $keep->describe($s));
        self::assertSame('Commencer avec Gemstone Caverns en jeu, en exilant Brainstorm', $play->describe($s));
        self::assertSame(['type' => 'pregame', 'player' => 0, 'sourceId' => 'gc', 'cardId' => 'bs'], $play->toArray());
    }
}
