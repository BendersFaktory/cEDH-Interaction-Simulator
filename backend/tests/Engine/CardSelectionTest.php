<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\ArrangeTop;
use App\Engine\Action\CastSpell;
use App\Engine\Action\PayCost;
use App\Engine\Action\PutBack;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Agent\HeuristicAgent;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;
use App\Engine\Game\StackItem;
use App\Engine\Rules\LegalActionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Sélection de cartes (lot G3) : Brainstorm (piocher 3, remettre 2 cartes de sa main sur le dessus, une décision par carte),
 * Ponder (ordre des 3 cartes du dessus ou mélange, puis pioche), Rhystic Study (trigger « un adversaire lance un sort » :
 * il paie {1} ou le contrôleur pioche). Les décisions se prennent au milieu de la résolution, hors priorité : pas de checkpoint.
 */
final class CardSelectionTest extends TestCase
{
    private const HUMAN = [0 => 'human:auto', 1 => 'ai:pass', 2 => 'ai:pass'];

    private static function brainstorm(): CardInstance
    {
        return new CardInstance('bs', 'Brainstorm', CardInstance::INSTANT, 1, manaCost: '{U}', effects: [new SpellEffect('draw', 3), new SpellEffect('put_back', 2)]);
    }

    private static function ponder(): CardInstance
    {
        return new CardInstance('po', 'Ponder', CardInstance::SORCERY, 1, manaCost: '{U}', effects: [new SpellEffect('arrange', 3), new SpellEffect('draw', 1)]);
    }

    private static function rhystic(): CardInstance
    {
        return new CardInstance('rs', 'Rhystic Study', CardInstance::ENCHANTMENT, 3, manaCost: '{2}{U}', triggers: [new TriggeredAbility(TriggeredAbility::OPPONENT_CAST, 'draw_unless', 1, '{1}')]);
    }

    private static function card(string $id, string $name, int $cost = 1): CardInstance
    {
        return new CardInstance($id, $name, CardInstance::SORCERY, $cost, manaCost: '{' . $cost . '}', effects: [new SpellEffect('draw', 1)]);
    }

    private static function land(string $id): CardInstance
    {
        return new CardInstance($id, 'Forest ' . $id, CardInstance::LAND, produces: ['G']);
    }

    /** `$spell` de J0 sur la pile, J0 et J1 ont passé, J2 va passer : le sort se résout. Bibliothèque de J0 : a, b, c, d, e. */
    private static function resolving(CardInstance $spell, array $controllers = self::HUMAN): GameState
    {
        $s = (new ScenarioBuilder())->build($controllers);
        $s->player(0)->library = [self::card('a', 'Card A'), self::card('b', 'Card B'), self::card('c', 'Card C'), self::card('d', 'Card D'), self::card('e', 'Card E')];
        $s->player(0)->hand = [self::card('h', 'Card H')];
        $s->stack[] = new StackItem('st', $spell, 0);
        $s->priority = new PriorityState(2, [0, 1]);

        return $s;
    }

    /** @param list<GameEvent> $events @return list<string> */
    private static function types(array $events): array
    {
        return array_map(static fn (GameEvent $e) => $e->type, $events);
    }

    /** @param list<CardInstance> $cards @return list<string> */
    private static function ids(array $cards): array
    {
        return array_map(static fn (CardInstance $c) => $c->id, $cards);
    }

    /** Depuis la résolution du sort, aucun `PriorityGranted` : la décision n'est pas un checkpoint (règle d'or 6). */
    private function assertNoPriorityAfterResolution(array $events): void
    {
        $types = self::types($events);
        $resolved = array_search('SpellResolved', $types, true);
        $this->assertNotFalse($resolved);
        $this->assertNotContains('PriorityGranted', array_slice($types, $resolved), 'Décision au milieu de la résolution : pas de checkpoint');
    }

    public function testBrainstormDrawsThreeThenTheHumanPutsTwoCardsBackOneAtATime(): void
    {
        $s = self::resolving(self::brainstorm());
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s);

        $this->assertNotNull($result->pending);
        $this->assertSame(0, $result->pending->player);
        $this->assertSame(['h', 'a', 'b', 'c'], self::ids($s->player(0)->hand), 'Brainstorm a pioché trois cartes');
        $this->assertSame(['putback:0:h', 'putback:0:a', 'putback:0:b', 'putback:0:c'], array_map(static fn ($a) => $a->id(), $result->pending->legalActions));
        $this->assertNoPriorityAfterResolution($result->events);
        $this->assertSame(['player' => 0, 'kind' => 'put_back', 'count' => 2, 'source' => 'Brainstorm', 'draw' => 0], $s->selection);
        $this->assertSame('Remettre Card H sur le dessus de la bibliothèque (encore 1 ensuite, au-dessus)', $result->pending->legalActions[0]->describe($s));

        // Reprise depuis l'état sérialisé (ancre de pause humaine) : même sélection.
        $s = GameState::fromArray(json_decode(json_encode($s->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(2, $s->selection['count']);
        $result = $engine->advance($s, new PutBack(0, 'h'));
        $this->assertNotNull($result->pending, 'Deuxième carte à remettre');
        $this->assertSame(['CardPutOnTop'], self::types($result->events));
        $this->assertSame(['h', 'd'], self::ids(array_slice($s->player(0)->library, 0, 2)));

        $result = $engine->advance($s, new PutBack(0, 'c'));
        $put = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'CardPutOnTop'));
        $this->assertSame(['card' => 'c', 'player' => 0, 'hand' => 2, 'library' => 4, 'source' => 'Brainstorm'], ['card' => $put[0]->payload['card']['id']] + $put[0]->payload);
        $this->assertNull($s->selection);
        $this->assertSame('PriorityGranted', $result->events[1]->type, 'Sélection finie : nouvelle ronde de priorité');
    }

    public function testThePutBackOrderIsTheReverseOfTheDecisions(): void
    {
        $s = self::resolving(self::brainstorm(), [0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->player(0)->library = [...$s->player(0)->library, ...array_map(static fn (int $i) => self::land("l$i"), range(1, 30))];
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s);

        // Agent passif : la première carte de la main (h), puis la première restante (a) ; a est donc sur le dessus, puis h.
        $put = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'CardPutOnTop'));
        $this->assertSame(['h', 'a'], array_map(static fn (GameEvent $e) => $e->payload['card']['id'], $put));
        $drawn = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'CardDrawn' && $e->payload['player'] === 0));
        $this->assertSame(['a', 'b', 'c', 'a', 'h'], array_slice(array_map(static fn (GameEvent $e) => $e->payload['card']['id'], $drawn), 0, 5), 'J0 repioche a puis h aux tours suivants');
    }

    public function testTheAiPutsBackSurplusLandsWithBrainstorm(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 5)->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->player(0)->hand = [self::land('l1'), self::card('x', 'Card X', 2), self::land('l2'), self::card('y', 'Card Y', 3)];
        $s->selection = ['player' => 0, 'kind' => 'put_back', 'count' => 2, 'source' => 'Brainstorm', 'draw' => 0];
        $s->priority = new PriorityState(0, [], true);
        $legal = (new LegalActionGenerator())->for($s, 0);

        $first = (new HeuristicAgent())->decide($s, 0, $legal);
        $this->assertInstanceOf(PutBack::class, $first);
        $this->assertContains($first->cardId, ['l1', 'l2'], 'Cinq terrains en jeu : un terrain de la main est remis');
        $this->assertInstanceOf(PutBack::class, AutoPassAgent::pass($legal, 0), 'L\'agent passif sait répondre');
    }

    public function testPonderOffersEveryOrderAndAShuffleThenDraws(): void
    {
        $s = self::resolving(self::ponder());
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s);

        $this->assertNotNull($result->pending);
        $ids = array_map(static fn ($a) => $a->id(), $result->pending->legalActions);
        $this->assertSame('arrange:0:a,b,c', $ids[0], 'L\'ordre actuel en premier (agent passif)');
        $this->assertCount(7, $ids, 'Six ordres et « mélanger »');
        $this->assertSame('arrange:0:shuffle', end($ids));
        $this->assertNoPriorityAfterResolution($result->events);
        $this->assertSame(['h'], self::ids($s->player(0)->hand), 'La pioche attend la décision');

        $s = GameState::fromArray($s->toArray());
        $result = $engine->advance($s, new ArrangeTop(0, ['c', 'a', 'b']));
        $types = self::types($result->events);
        $this->assertSame(['Scried', 'CardDrawn'], array_slice($types, 0, 2));
        $this->assertSame(['top' => 3, 'bottom' => 0], array_intersect_key($result->events[0]->payload, ['top' => 0, 'bottom' => 0]));
        $this->assertSame('c', $result->events[1]->payload['card']['id'], 'Ponder pioche la carte remise sur le dessus');
        $this->assertSame(4, $result->events[1]->payload['library']);
    }

    public function testPonderShuffleIsDeterministic(): void
    {
        $s = self::resolving(self::ponder());
        $engine = new GameEngine(new AgentRegistry());
        $engine->advance($s);
        $saved = $s->toArray();

        $runs = [];
        foreach ([1, 2] as $run) {
            $copy = GameState::fromArray($saved);
            $result = $engine->advance($copy, new ArrangeTop(0, [], true));
            $this->assertSame(['LibraryShuffled', 'CardDrawn'], array_slice(self::types($result->events), 0, 2));
            $this->assertSame(5, $result->events[0]->payload['library']);
            // Toute la suite de la partie (ordre des pioches compris) : même flux d'événements.
            $runs[] = json_encode(array_map(static fn (GameEvent $e) => [$e->type, $e->payload], $result->events), JSON_THROW_ON_ERROR);
        }
        $this->assertSame($runs[0], $runs[1], 'Mélange par GameState::$rng : même résultat depuis le même état');
    }

    public function testTheAiShufflesAwayUselessCardsWithPonder(): void
    {
        $s = (new ScenarioBuilder())->untappedLands(0, 5)->build([0 => 'ai:heuristic', 1 => 'ai:pass', 2 => 'ai:pass']);
        $s->player(0)->library = [self::land('l1'), self::card('big', 'Big Spell', 12), self::land('l2'), self::card('ok', 'Cheap Spell', 2)];
        $s->selection = ['player' => 0, 'kind' => 'arrange', 'count' => 3, 'source' => 'Ponder', 'draw' => 1];
        $s->priority = new PriorityState(0, [], true);
        $agent = new HeuristicAgent();

        $action = $agent->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertInstanceOf(ArrangeTop::class, $action);
        $this->assertTrue($action->shuffle, 'Assez de terrains et un sort hors de portée : mélanger');

        $s->player(0)->library = [self::land('l1'), self::card('ok', 'Cheap Spell', 2), self::card('big', 'Big Spell', 12)];
        $action = $agent->decide($s, 0, (new LegalActionGenerator())->for($s, 0));
        $this->assertSame(['ok', 'l1', 'big'], $action->order, 'Le sort jouable d\'abord');
    }

    /** J0 contrôle Rhystic Study ; J1 (priorité) lance un sort à {1} avec `$lands` terrains. */
    private static function rhysticScenario(int $lands, array $controllers, ?CardInstance $extra = null): GameState
    {
        $s = (new ScenarioBuilder())->permanent(0, self::rhystic())->untappedLands(1, $lands)
            ->hand(1, new CardInstance('t1', 'Test Instant', CardInstance::INSTANT, 1, manaCost: '{1}', effects: [new SpellEffect('draw', 1)]), ...($extra === null ? [] : [$extra]))
            ->build($controllers);
        $s->priority = new PriorityState(1);

        return $s;
    }

    /** @return list<string> */
    private static function rhysticOutcome(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            if (in_array($e->type, ['CostPaid', 'CostNotPaid'], true)) {
                $out[] = $e->type . ':' . $e->payload['player'];
            } elseif ($e->type === 'CardDrawn' && $e->payload['player'] === 0 && $out !== [] && count($out) === 1) {
                $out[] = 'J0 pioche';
            } elseif ($e->type === 'PhaseChanged') {
                break;
            }
        }

        return $out;
    }

    public function testRhysticStudyTriggersOnAnOpponentsSpellAndTheCasterPays(): void
    {
        $s = self::rhysticScenario(2, [0 => 'ai:pass', 1 => 'ai:heuristic', 2 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(1, 't1'));

        $triggered = array_values(array_filter($result->events, static fn (GameEvent $e) => $e->type === 'AbilityTriggered'))[0];
        $this->assertSame([0, ['player:1']], [$triggered->payload['player'], $triggered->payload['targets']], 'Trigger de J0, payeur J1');
        $this->assertSame(['CostPaid:1'], self::rhysticOutcome($result->events), 'J1 a de quoi payer : il paie, J0 ne pioche pas');
    }

    public function testRhysticStudyDrawsWhenTheCasterCannotPay(): void
    {
        $s = self::rhysticScenario(1, [0 => 'ai:pass', 1 => 'ai:heuristic', 2 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(1, 't1'));

        $this->assertSame(['CostNotPaid:1', 'J0 pioche'], self::rhysticOutcome($result->events));
    }

    public function testTheAiDoesNotPayRhysticWhenItWouldLoseItsCounterspellMana(): void
    {
        $counter = new CardInstance('cs', 'Counterspell', CardInstance::INSTANT, 2, manaCost: '{2}', effects: [new SpellEffect('counter')]);
        $s = self::rhysticScenario(3, [0 => 'ai:pass', 1 => 'ai:heuristic', 2 => 'ai:pass'], $counter);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(1, 't1'));
        $this->assertSame(['CostNotPaid:1', 'J0 pioche'], self::rhysticOutcome($result->events), 'Payer {1} laisserait 1 mana : plus de Counterspell');

        $s = self::rhysticScenario(4, [0 => 'ai:pass', 1 => 'ai:heuristic', 2 => 'ai:pass'], $counter);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(1, 't1'));
        $this->assertSame(['CostPaid:1'], self::rhysticOutcome($result->events), 'Assez pour les deux : il paie');
    }

    public function testAHumanCasterChoosesWhetherToPayForRhysticStudy(): void
    {
        $s = self::rhysticScenario(2, [0 => 'ai:pass', 1 => 'human:auto', 2 => 'ai:pass']);
        $engine = new GameEngine(new AgentRegistry());
        $result = $engine->advance($s, new CastSpell(1, 't1'));

        $this->assertNotNull($result->pending);
        $this->assertSame(['pay:1:yes', 'pay:1:no', 'ability:1:p1_land1:0:0:'], array_map(static fn ($a) => $a->id(), $result->pending->legalActions));
        $this->assertSame('Ne pas payer {1} (Rhystic Study) : J0 pioche', $result->pending->legalActions[1]->describe($s));
        $this->assertNotSame('PriorityGranted', $result->events[array_key_last($result->events)]->type, 'Décision hors priorité : pas de checkpoint');

        $s = GameState::fromArray($s->toArray());
        $this->assertNotNull($s->stack[1]->trigger, 'Le trigger de Rhystic Study est au sommet de la pile');
        $result = $engine->advance($s, new PayCost(1, false, '{1}'));
        $this->assertSame(['CostNotPaid:1', 'J0 pioche'], self::rhysticOutcome($result->events));
    }

    public function testRhysticStudyIgnoresItsControllersOwnSpells(): void
    {
        $s = (new ScenarioBuilder())->permanent(0, self::rhystic())->untappedLands(0, 1)
            ->hand(0, new CardInstance('t0', 'Test Instant', CardInstance::INSTANT, 1, manaCost: '{1}', effects: [new SpellEffect('draw', 1)]))
            ->build([0 => 'ai:pass', 1 => 'ai:pass', 2 => 'ai:pass']);
        $result = (new GameEngine(new AgentRegistry()))->advance($s, new CastSpell(0, 't0'));

        $this->assertNotContains('AbilityTriggered', self::types($result->events));
    }
}
