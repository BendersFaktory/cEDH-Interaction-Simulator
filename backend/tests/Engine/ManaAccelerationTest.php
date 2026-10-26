<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PutTrigger;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\CardDefinition;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;
use App\Engine\Rules\LegalActionGenerator;
use App\Service\Deck\CardMapper;
use App\Service\Deck\UnsupportedCard;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\TestCase;

/** Lot G1 : accélération de mana cEDH (Mana Vault, Grim Monolith, Chrome Mox, Fellwar Stone, Exotic Orchard). */
final class ManaAccelerationTest extends TestCase
{
    private const J0_HUMAN = [0 => 'human', 1 => 'ai:pass', 2 => 'ai:pass', 3 => 'ai:pass'];

    private static function def(string $name): CardDefinition
    {
        return (new CardMapper())->map(ScryfallFixtures::all()[$name]);
    }

    private static function card(string $name, string $id): CardInstance
    {
        return self::def($name)->instantiate($id);
    }

    private static function mountain(string $id): CardInstance
    {
        return new CardInstance($id, 'Mountain', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['R']);
    }

    private static function island(string $id): CardInstance
    {
        return new CardInstance($id, 'Island', CardInstance::LAND, 0, null, 0, false, false, 0, 0, 0, 0, null, ['U']);
    }

    private static function redSpell(string $id): CardInstance
    {
        return new CardInstance($id, 'Red Spell', CardInstance::SORCERY, 2, 'damage', 2, manaCost: '{1}{R}');
    }

    /** @return list<ActivateAbility> */
    private function abilities(GameState $s, int $player = 0): array
    {
        return array_values(array_filter((new LegalActionGenerator())->for($s, $player), static fn (Action $a) => $a instanceof ActivateAbility));
    }

    /** @param list<GameEvent> $events @return list<GameEvent> */
    private function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (GameEvent $e) => $e->type === $type));
    }

    // --- Correspondance Scryfall -------------------------------------------------------------------------------------------

    public function testMapsManaVaultAndGrimMonolith(): void
    {
        $vault = self::def('Mana Vault');
        self::assertTrue($vault->noUntap);
        self::assertSame([['isManaAbility' => true, 'effect' => 'mana', 'tap' => true, 'manaCost' => null, 'options' => ['{C}{C}{C}'], 'amount' => 0, 'sacrifice' => false],
            ['isManaAbility' => false, 'effect' => 'untap', 'tap' => false, 'manaCost' => '{4}', 'options' => [], 'amount' => 0, 'sacrifice' => false, 'upkeepOnly' => true]],
            array_map(static fn (ActivatedAbility $a) => $a->toArray(), $vault->abilities));
        self::assertSame([['event' => 'draw_step', 'effect' => 'self_damage', 'amount' => 1, 'filter' => 'tapped']],
            array_map(static fn (TriggeredAbility $t) => $t->toArray(), $vault->triggers));

        $monolith = self::def('Grim Monolith');
        self::assertTrue($monolith->noUntap);
        self::assertSame(['mana', 'untap'], array_map(static fn (ActivatedAbility $a) => $a->effect, $monolith->abilities));
        self::assertFalse($monolith->abilities[1]->upkeepOnly, 'Le Monolithe se dégage à tout moment');
        self::assertSame('{4}', (string) $monolith->abilities[1]->manaCost);
    }

    public function testMapsChromeMoxFellwarStoneAndExoticOrchard(): void
    {
        $mox = self::def('Chrome Mox');
        self::assertFalse($mox->noUntap);
        self::assertSame([['event' => 'etb', 'effect' => 'imprint', 'amount' => 0]], array_map(static fn (TriggeredAbility $t) => $t->toArray(), $mox->triggers));
        self::assertSame(ActivatedAbility::DYNAMIC_IMPRINT, $mox->abilities[0]->spec);
        self::assertTrue($mox->abilities[0]->isDynamicMana());
        self::assertSame(['{W}', '{U}', '{B}', '{R}', '{G}'], $mox->abilities[0]->toArray()['options']);

        self::assertSame(ActivatedAbility::DYNAMIC_OPPONENT_LAND, self::def('Fellwar Stone')->abilities[0]->spec);
        $orchard = self::def('Exotic Orchard');
        self::assertSame(CardInstance::LAND, $orchard->type);
        self::assertSame(ActivatedAbility::DYNAMIC_OPPONENT_LAND, $orchard->abilities[0]->spec);
        self::assertSame([], $orchard->produces);
        self::assertCount(1, $orchard->instantiate('o')->abilities(), 'Pas de {C} implicite en plus de sa capacité');
    }

    public function testSplitCardIsRefusedWithAnExplicitMessage(): void
    {
        $this->expectException(UnsupportedCard::class);
        $this->expectExceptionMessage('carte scindée non modélisée');
        self::def('Fire // Ice');
    }

    public function testNewFieldsAreOmittedByDefaultAndRoundTrip(): void
    {
        $plain = self::mountain('m');
        self::assertArrayNotHasKey('noUntap', $plain->toArray());
        self::assertArrayNotHasKey('imprint', $plain->toArray());

        $mox = self::card('Chrome Mox', 'mox');
        self::assertArrayNotHasKey('imprint', $mox->toArray());
        $mox->imprint = ['R', 'G'];
        self::assertSame(['R', 'G'], $mox->toArray()['imprint'], 'Le mémo de toArray() tient compte de l\'imprint');
        self::assertSame(['R', 'G'], CardInstance::fromArray($mox->toArray())->imprint);

        $vault = self::card('Mana Vault', 'v');
        self::assertTrue($vault->toArray()['noUntap']);
        $back = CardInstance::fromArray($vault->toArray());
        self::assertTrue($back->noUntap);
        self::assertTrue($back->abilities()[1]->upkeepOnly);
        self::assertSame($vault->toArray(), $back->toArray());
    }

    // --- Ne se dégage pas, {4} : dégager, dégât de la pioche -------------------------------------------------------------------

    public function testNoUntapCardStaysTappedDuringTheUntapStep(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Mana Vault', 'v'))->permanent(0, self::mountain('m'))->build(self::J0_HUMAN);
        foreach ($s->player(0)->battlefield as $c) {
            $c->tapped = true;
        }
        $s->phase = Phase::Untap;
        $s->stepStarted = false;
        $s->turn = 1;

        $r = (new GameEngine(new AgentRegistry()))->advance($s);

        self::assertTrue($s->player(0)->findOnBattlefield('v')->tapped, 'Mana Vault reste engagée');
        self::assertFalse($s->player(0)->findOnBattlefield('m')->tapped, 'Une Montagne se dégage');
        self::assertSame(['m'], $this->of($r->events, 'PermanentsUntapped')[0]->payload['cards']);
    }

    public function testGrimMonolithUntapsForFourAndIsOnlyOfferedWhenTapped(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Grim Monolith', 'gm'))->untappedLands(0, 4)->build(self::J0_HUMAN);
        $untap = static fn (array $as) => array_values(array_filter($as, static fn (ActivateAbility $a) => $a->abilityIndex === 1));
        self::assertSame([], $untap($this->abilities($s)), 'Rien à dégager tant qu\'il est dégagé');

        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        [$mana] = array_values(array_filter($this->abilities($s), static fn (ActivateAbility $a) => $a->cardId === 'gm' && $a->abilityIndex === 0));
        $engine->advance($s, $mana);
        self::assertTrue($s->player(0)->findOnBattlefield('gm')->tapped);
        self::assertSame(['C' => 3], $s->player(0)->pool->toArray());

        // {4} : le mana de la réserve (CCC) plus une terre paient le coût, jamais le Monolithe lui-même.
        $offer = $untap($this->abilities($s));
        self::assertCount(1, $offer);
        self::assertFalse($offer[0]->mana);
        $r = $engine->advance($s, $offer[0]);
        self::assertCount(1, $s->stack, 'Capacité non-mana : elle passe par la pile');
        self::assertSame(0, $s->player(0)->pool->total());

        $r = $engine->advance($s, new PassPriority(0));
        self::assertFalse($s->player(0)->findOnBattlefield('gm')->tapped);
        self::assertSame(['gm'], $this->of($r->events, 'PermanentsUntapped')[0]->payload['cards']);
    }

    public function testManaVaultUntapsOnlyDuringItsControllersUpkeep(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Mana Vault', 'v'))->untappedLands(0, 4)->build(self::J0_HUMAN);
        $s->player(0)->findOnBattlefield('v')->tapped = true;
        $untap = static fn (array $as) => array_values(array_filter($as, static fn (ActivateAbility $a) => $a->abilityIndex === 1));
        self::assertSame([], $untap($this->abilities($s)), 'Pas en phase principale');

        $s->phase = Phase::Upkeep;
        self::assertCount(1, $untap($this->abilities($s)));

        $s->activePlayer = 1;
        self::assertSame([], $untap($this->abilities($s)), 'Ni pendant l\'entretien d\'un autre joueur');
    }

    public function testManaVaultDealsOneDamageAtDrawStepOnlyIfTapped(): void
    {
        foreach ([true, false] as $tapped) {
            $s = (new ScenarioBuilder())->life(40)->permanent(0, self::card('Mana Vault', 'v'))->build(self::J0_HUMAN);
            $s->player(0)->findOnBattlefield('v')->tapped = $tapped;
            $s->phase = Phase::Draw;
            $s->stepStarted = false;
            $engine = new GameEngine(new AgentRegistry());

            $engine->advance($s);
            self::assertCount($tapped ? 1 : 0, $s->stack, 'Trigger de l\'étape de pioche seulement si engagée');
            $engine->advance($s, new PassPriority(0));

            self::assertSame($tapped ? 39 : 40, $s->player(0)->life);
        }
    }

    public function testManaVaultTriggerChecksTappedAgainOnResolution(): void
    {
        $s = (new ScenarioBuilder())->life(40)->permanent(0, self::card('Mana Vault', 'v'))->build(self::J0_HUMAN);
        $s->player(0)->findOnBattlefield('v')->tapped = true;
        $s->phase = Phase::Draw;
        $s->stepStarted = false;
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $s->player(0)->findOnBattlefield('v')->tapped = false; // dégagée en réponse
        $engine->advance($s, new PassPriority(0));

        self::assertSame(40, $s->player(0)->life, 'Clause « si engagée » revérifiée à la résolution (CR 603.4)');
    }

    // --- Chrome Mox ---------------------------------------------------------------------------------------------------------

    public function testChromeMoxImprintExilesTheChosenCardAndProducesItsColor(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Chrome Mox', 'mox'), self::redSpell('red'), self::card('Mana Vault', 'art'), self::mountain('land'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);

        $engine->advance($s, new CastSpell(0, 'mox'));
        $r = $engine->advance($s, new PassPriority(0));
        // Le trigger d'entrée attend le choix : ne rien exiler ou la carte rouge (ni l'artefact, ni le terrain).
        self::assertSame(['trigger:0:tr1:', 'trigger:0:tr1:card:red'], array_map(static fn (array $a) => $a['id'], $r->pending->toArray()['actions']));
        self::assertSame(['Mettre sur la pile : Chrome Mox (Quand elle entre : exiler une carte de votre main (imprint)) → ne rien exiler',
            'Mettre sur la pile : Chrome Mox (Quand elle entre : exiler une carte de votre main (imprint)) → exiler Red Spell'],
            array_map(static fn (array $a) => $a['label'], $r->pending->toArray()['actions']));

        $engine->advance($s, new PutTrigger(0, 'tr1', ['card:red']));
        $r = $engine->advance($s, new PassPriority(0));

        self::assertSame(['R'], $s->player(0)->findOnBattlefield('mox')->imprint);
        self::assertSame(['red'], array_map(static fn (CardInstance $c) => $c->id, $s->player(0)->exile));
        self::assertNull($s->player(0)->findInHand('red'));
        self::assertSame(['player' => 0, 'card' => 'mox', 'exiled' => 'Red Spell', 'colors' => ['R']], $this->of($r->events, 'CardImprinted')[0]->payload);
        self::assertSame('hand', $this->of($r->events, 'CardExiled')[0]->payload['from']);

        $offers = $this->abilities($s);
        self::assertCount(1, $offers, 'Une seule couleur permise : le rouge');
        self::assertSame(3, $offers[0]->option, 'Les alternatives sont W U B R G : le rouge est la 4e (indice 3)');
        $engine->advance($s, $offers[0]);
        self::assertSame(['R' => 1], $s->player(0)->pool->toArray());
    }

    public function testChromeMoxWithNothingExiledProducesNothing(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Chrome Mox', 'mox'), self::redSpell('red'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $engine->advance($s, new CastSpell(0, 'mox'));
        $engine->advance($s, new PassPriority(0));
        $engine->advance($s, new PutTrigger(0, 'tr1', []));
        $engine->advance($s, new PassPriority(0));

        self::assertSame([], $s->player(0)->findOnBattlefield('mox')->imprint);
        self::assertNotNull($s->player(0)->findInHand('red'));
        self::assertSame([], $this->abilities($s));
        self::assertNull(ManaPayment::plan($s->player(0), ManaCost::parse('{1}')), 'Un Mox sans imprint ne paie rien');
    }

    public function testChromeMoxWithoutEligibleCardPutsItsTriggerWithoutDecision(): void
    {
        $s = (new ScenarioBuilder())->hand(0, self::card('Chrome Mox', 'mox'), self::mountain('land'))->build(self::J0_HUMAN);
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $engine->advance($s, new CastSpell(0, 'mox'));
        $r = $engine->advance($s, new PassPriority(0));

        self::assertCount(1, $this->of($r->events, 'AbilityTriggered'));
        self::assertSame([], $s->pendingTriggers);
    }

    public function testChromeMoxPaysItsImprintedColorInAutoPayment(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Chrome Mox', 'mox'))->untappedLands(0, 1)->build(self::J0_HUMAN);
        $mox = $s->player(0)->findOnBattlefield('mox');
        $mox->imprint = ['R'];

        $plan = ManaPayment::plan($s->player(0), ManaCost::parse('{R}'));
        self::assertSame(['mox'], array_column($plan->taps, 'card'));
        self::assertNull(ManaPayment::plan($s->player(0), ManaCost::parse('{U}')));

        $mox->imprint = ['R', 'U']; // carte multicolore : l'une ou l'autre couleur
        self::assertNotNull(ManaPayment::plan($s->player(0), ManaCost::parse('{U}')));
        self::assertSame([], self::card('Chrome Mox', 'x')->colors(), 'Un artefact sans symbole coloré est incolore');
    }

    public function testHeuristicAgentImprintsAColorItNeedsAndDeclinesOtherwise(): void
    {
        $blue = new CardInstance('blue', 'Blue Draw', CardInstance::SORCERY, 2, 'draw', 1, manaCost: '{1}{U}');
        $counter = new CardInstance('ctr', 'Counterspell', CardInstance::INSTANT, 2, 'counter', manaCost: '{U}{U}');
        $red2 = new CardInstance('red2', 'Red Ping', CardInstance::INSTANT, 1, 'damage', 1, manaCost: '{R}');
        $agent = new HeuristicAgent();

        // Deux cartes rouges et une main de trois sorts : la moins chère partage sa couleur avec une autre (la rouge de 1).
        $s = (new ScenarioBuilder())->hand(0, self::redSpell('red'), $red2, $blue)->permanent(0, self::card('Chrome Mox', 'mox'))->build(self::J0_HUMAN);
        $s->queueTriggers(0, $s->player(0)->findOnBattlefield('mox'), TriggeredAbility::ETB);
        $legal = (new LegalActionGenerator())->triggerActions($s, 0);
        self::assertCount(4, $legal);
        $choice = $agent->decide($s, 0, $legal);
        self::assertSame(['card:red2'], $choice->targets);

        // Contresort jamais exilé, et aucune autre couleur partagée : rien à exiler.
        $green = new CardInstance('green', 'Green Draw', CardInstance::SORCERY, 2, 'draw', 1, manaCost: '{1}{G}');
        $s = (new ScenarioBuilder())->hand(0, $counter, $green, self::redSpell('red'))->permanent(0, self::card('Chrome Mox', 'mox'))->build(self::J0_HUMAN);
        $s->queueTriggers(0, $s->player(0)->findOnBattlefield('mox'), TriggeredAbility::ETB);
        self::assertSame([], $agent->decide($s, 0, (new LegalActionGenerator())->triggerActions($s, 0))->targets);

        // Main trop courte : on garde ses cartes.
        $s = (new ScenarioBuilder())->hand(0, self::redSpell('red'), $red2)->permanent(0, self::card('Chrome Mox', 'mox'))->build(self::J0_HUMAN);
        $s->queueTriggers(0, $s->player(0)->findOnBattlefield('mox'), TriggeredAbility::ETB);
        self::assertSame([], $agent->decide($s, 0, (new LegalActionGenerator())->triggerActions($s, 0))->targets);
    }

    // --- Fellwar Stone / Exotic Orchard ----------------------------------------------------------------------------------------

    public function testFellwarStoneProducesOnlyColorsOfOpponentLands(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Fellwar Stone', 'fs'))->permanent(0, self::island('mine'))
            ->permanent(1, self::mountain('theirs'))->permanent(2, self::card('Exotic Orchard', 'orch'))->build(self::J0_HUMAN);
        $p = $s->player(0);

        // Les terrains adverses : Montagne (R) ; l'Orchard adverse ne produit rien par lui-même ; ma propre Île ne compte pas.
        self::assertSame(['R' => true], $s->opponentLandColors(0));
        $fellwar = array_values(array_filter($this->abilities($s), static fn (ActivateAbility $a) => $a->cardId === 'fs'));
        self::assertSame([3], array_map(static fn (ActivateAbility $a) => $a->option, $fellwar));
        self::assertNotNull(ManaPayment::plan($p, ManaCost::parse('{R}')));
        self::assertNull(ManaPayment::plan($p, ManaCost::parse('{G}')), 'Aucun terrain adverse ne fait du vert');
        self::assertSame(['fs'], array_column(ManaPayment::plan($p, ManaCost::parse('{R}'))->taps, 'card'));

        // Dynamique : un Île adverse de plus et le bleu devient disponible (aucun cache).
        $s->player(3)->battlefield[] = self::island('i3');
        self::assertSame(['R' => true, 'U' => true], $s->opponentLandColors(0));
        self::assertNotNull(ManaPayment::plan($p, ManaCost::parse('{U}{R}'), 0, null, null, null));
    }

    public function testFellwarStoneWithoutOpponentLandsDoesNothing(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Fellwar Stone', 'fs'))->build(self::J0_HUMAN);

        self::assertSame([], $s->opponentLandColors(0));
        self::assertSame([], $this->abilities($s));
        self::assertNull(ManaPayment::plan($s->player(0), ManaCost::parse('{1}')));
        $this->expectException(\App\Engine\Decision\IllegalActionException::class);
        AbilityManager::activate($s, new ActivateAbility(0, 'fs', 0, 3));
    }

    public function testFellwarStoneRejectsAColorNoOpponentLandMakes(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Fellwar Stone', 'fs'))->permanent(1, self::mountain('theirs'))->build(self::J0_HUMAN);

        $this->expectException(\App\Engine\Decision\IllegalActionException::class);
        $this->expectExceptionMessage('Couleur de mana non permise');
        AbilityManager::activate($s, new ActivateAbility(0, 'fs', 0, 4)); // vert
    }

    public function testExoticOrchardIgnoresColorlessAndEliminatedPlayersLands(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Exotic Orchard', 'orch'))
            ->permanent(1, new CardInstance('tomb', 'Ancient Tomb', CardInstance::LAND, 0, abilities: [ActivatedAbility::mana('{C}{C}')]))
            ->permanent(2, self::mountain('theirs'))->build(self::J0_HUMAN);

        self::assertSame(['R' => true], $s->opponentLandColors(0), 'L\'incolore n\'est pas une couleur');
        $s->player(2)->lost = true;
        self::assertSame([], $s->opponentLandColors(0), 'Les terrains d\'un joueur éliminé ne comptent plus');
    }

    public function testPlayerCopiesKeepTheOpponentLink(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::card('Fellwar Stone', 'fs'))->permanent(1, self::mountain('theirs'))->build(self::J0_HUMAN);
        $copy = $s->player(0)->duplicate();

        self::assertNotNull(ManaPayment::plan($copy, ManaCost::parse('{R}')), 'La simulation locale de l\'IA voit les terrains adverses');
        $copy->findOnBattlefield('fs')->tapped = true;
        self::assertFalse($s->player(0)->findOnBattlefield('fs')->tapped, 'Copie indépendante');
        self::assertNull(ManaPayment::plan(\App\Engine\Game\PlayerState::fromArray($s->player(0)->toArray()), ManaCost::parse('{R}')), 'Sans branchement : aucune couleur');
    }
}
