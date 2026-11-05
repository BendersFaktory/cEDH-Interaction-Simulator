<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Game\CardInstance;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Service\Deck\CardMapper;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/** Étape 15 : approximations corrigées pendant la relecture des 42 cartes acceptées avant H1 (roadmap §2). */
final class CardReviewTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function card(string $name, string $id, bool $commander = false): CardInstance
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name])->instantiate($id, $commander);
    }

    public function testCommanderIdentityManaIsMappedAsDynamic(): void
    {
        foreach (['Command Tower', 'Arcane Signet'] as $name) {
            $card = self::card($name, 'x');
            self::assertSame([], $card->produces, $name);
            self::assertSame(ActivatedAbility::DYNAMIC_IDENTITY, $card->abilities()[0]->spec, $name);
            self::assertCount(1, $card->abilities(), "$name : aucune capacité {C} implicite");
            self::assertSame([], $card->colorIdentity(), "$name : incolore pour l'identité de couleurs");
        }
    }

    public function testCommandTowerProducesOnlyTheCommandersColors(): void
    {
        $s = (new ScenarioBuilder())->commander(0, self::card("Kraum, Ludevic's Opus", 'p0_c0', true))
            ->permanent(0, self::card('Command Tower', 'p0_c5'))->permanent(0, self::card('Arcane Signet', 'p0_c6'))
            ->permanent(0, self::card('Tymna the Weaver', 'p0_c1', true))->build(self::J0_HUMAN);
        $p = $s->player(0);

        // Kraum (U/R) en zone de commandement, Tymna (W/B) sur le champ de bataille : W, U, B, R, pas de vert.
        self::assertSame(['U' => true, 'R' => true, 'W' => true, 'B' => true], $s->commanderIdentity(0));
        self::assertNotNull(ManaPayment::plan($p, ManaCost::parse('{W}{R}')));
        self::assertNull(ManaPayment::plan($p, ManaCost::parse('{G}')), 'Le vert est hors identité');
    }

    public function testCommandTowerWithoutCommanderProducesNothing(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Command Tower', 'p0_c5'))->build(self::J0_HUMAN);

        self::assertSame([], $s->commanderIdentity(0));
        self::assertNull(ManaPayment::plan($s->player(0), ManaCost::parse('{1}')), 'CR 903.4f');
    }

    public function testExoticOrchardSeesTheIdentityOfAnOpponentsCommandTower(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Exotic Orchard', 'p0_c5'))
            ->commander(1, self::card("Kraum, Ludevic's Opus", 'p1_c0', true))->permanent(1, self::card('Command Tower', 'p1_c5'))
            ->build(self::J0_HUMAN);

        self::assertSame(['U' => true, 'R' => true], $s->opponentLandColors(0));
        self::assertNotNull(ManaPayment::plan($s->player(0), ManaCost::parse('{U}')));
        self::assertNull(ManaPayment::plan($s->player(0), ManaCost::parse('{W}')));
    }
}
