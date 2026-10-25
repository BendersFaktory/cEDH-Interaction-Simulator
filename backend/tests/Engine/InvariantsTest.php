<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\Decks;
use App\Engine\Card\SpellEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use App\Engine\Snapshot\CheckpointListener;
use App\Service\Deck\CardMapper;
use App\Service\Deck\LoadedDeck;
use App\Tests\Service\Deck\ScryfallFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests de propriété du moteur (ROADMAP §5) : des parties complètes à 4 IA, sur de nombreuses graines, doivent respecter
 * des invariants observés sur le flux d'événements et sur l'état à chaque checkpoint (`PriorityGranted` avec
 * `checkpoint: true`, seul point où l'état est cohérent) :
 *
 * - conservation des cartes : par propriétaire, bibliothèque + main + champ de bataille + cimetière + exil + zone de commandement
 *   + sorts sur la pile + objets sortis de la partie (`PlayerLeftGame`, CR 800.4a) = taille du deck ; jetons exclus ;
 *   chaque carte n'est que dans une zone, et dans les zones de son propriétaire (le contrôle ne change jamais ici) ;
 * - aucun trigger en attente à un checkpoint ; réserves de mana vides au premier checkpoint de chaque étape, et un
 *   `ManaPoolEmptied` n'apparaît qu'en fin d'étape (suivi du changement d'étape ou de la fin de partie) ;
 * - pile : le nombre d'éléments suivi par les événements (lancés, activés, triggers / résolus, contrés, sortis de la partie)
 *   égale `stackSize` de chaque `PriorityGranted` et la pile de l'état ; elle est vide à chaque changement d'étape.
 *   Exception tranchée (« laissée en l'état », docs/EVENTS.md) : la pile peut rester non vide après `GameOver` ;
 * - PV : chaque `DamageDealt` vers un joueur, `LifePaid`, `LifeGained` porte des PV résultants égaux aux PV précédents
 *   ± le montant ; les PV suivis égalent ceux de l'état à chaque checkpoint et en fin de partie ; une défaite `life` a
 *   lieu à 0 PV ou moins ;
 * - déterminisme : deux exécutions d'une graine donnent la même empreinte sha1 (méthode de bin/bench.php) ; reprendre
 *   depuis un checkpoint passé par toArray() / JSON / fromArray() donne les mêmes événements et le même état final.
 */
final class InvariantsTest extends TestCase
{
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        foreach ([...range(1, 30), 42] as $seed) {
            yield "graine $seed" => [$seed];
        }
    }

    /** @return iterable<string, array{int}> */
    public static function slowSeeds(): iterable
    {
        foreach (range(31, 100) as $seed) {
            if ($seed !== 42) {
                yield "graine $seed" => [$seed];
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function fetchSeeds(): iterable
    {
        // Graines où l'IA active au moins une fetchland (2, 5, 10 et 15 n'en ont pas).
        foreach ([1, 3, 7, 11] as $seed) {
            yield "graine $seed" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testInvariantsOnDemoDecks(int $seed): void
    {
        $this->checkSeed($seed, null);
    }

    #[DataProvider('slowSeeds')]
    #[Group('slow')]
    public function testInvariantsOnMoreSeeds(int $seed): void
    {
        $this->checkSeed($seed, null);
    }

    /** Decks à shocklands / fetchlands (cartes Scryfall figées) : mélanges de bibliothèque et terrains cherchés. */
    #[DataProvider('fetchSeeds')]
    public function testInvariantsWithFetchAndShockLands(int $seed): void
    {
        $types = $this->checkSeed($seed, self::fetchDecks());
        // La couverture visée est bien exercée : au moins un terrain cherché et un mélange de bibliothèque.
        self::assertContains('LandFetched', $types, "graine $seed : aucun terrain cherché");
        self::assertContains('LibraryShuffled', $types, "graine $seed : aucun mélange");
    }

    /** @return iterable<string, array{int}> */
    public static function exileSeeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6] as $seed) {
            yield "graine $seed" => [$seed];
        }
    }

    /** Decks de démo avec des sorts d'exil : l'exil compte dans la conservation des cartes. */
    #[DataProvider('exileSeeds')]
    public function testInvariantsWithExileSpells(int $seed): void
    {
        $types = $this->checkSeed($seed, self::exileDecks());
        self::assertContains('CardExiled', $types, "graine $seed : aucune carte exilée");
    }

    /** Déterminisme : même graine, mêmes événements ; aller-retour sérialisé à un checkpoint, même suite. */
    #[DataProvider('roundTripSeeds')]
    public function testRoundTripAtCheckpointGivesSameContinuation(int $seed, bool $fetchDecks): void
    {
        $decks = $fetchDecks ? self::fetchDecks() : null;
        [$events, $final] = self::play($seed, $decks);

        // Trois checkpoints répartis dans la partie : on y sérialise l'état (comme une ancre en base) puis on reprend.
        $checkpointSeqs = [];
        foreach ($events as $e) {
            if ($e->type === 'PriorityGranted' && $e->payload['checkpoint'] === true) {
                $checkpointSeqs[] = $e->seq;
            }
        }
        self::assertNotEmpty($checkpointSeqs);
        $n = count($checkpointSeqs);
        $targets = array_values(array_unique([$checkpointSeqs[intdiv($n, 4)], $checkpointSeqs[intdiv($n, 2)], $checkpointSeqs[intdiv(3 * $n, 4)]]));

        $listener = new class ($targets) implements CheckpointListener {
            /** @var array<int, string> eventSeq => état sérialisé en JSON */
            public array $saved = [];

            /** @param list<int> $targets */
            public function __construct(private readonly array $targets)
            {
            }

            public function onCheckpoint(GameState $state): void
            {
                if (in_array($state->eventSeq, $this->targets, true)) {
                    $this->saved[$state->eventSeq] = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
                }
            }
        };
        $state = GameFactory::create($seed, self::FOUR_AI, $decks);
        // Seconde exécution de la même graine (avec un écouteur qui ne fait que lire) : même empreinte.
        $again = (new GameEngine(new AgentRegistry(), $listener))->advance($state)->events;
        self::assertSame(self::fingerprint($events), self::fingerprint($again), "graine $seed : empreinte différente en double exécution");
        self::assertSame(array_values($targets), array_keys($listener->saved));

        foreach ($listener->saved as $seq => $json) {
            $resumed = GameState::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($json, json_encode($resumed->toArray(), JSON_THROW_ON_ERROR), "graine $seed : toArray(fromArray()) diffère à #$seq");
            $result = (new GameEngine(new AgentRegistry()))->advance($resumed);
            $expected = array_values(array_filter($events, static fn (GameEvent $e) => $e->seq > $seq));
            self::assertSame(self::fingerprint($expected), self::fingerprint($result->events), "graine $seed : suite différente après reprise à #$seq");
            self::assertSame($expected[0]->seq ?? null, $result->events[0]->seq ?? null);
            self::assertSame(json_encode($final->toArray()), json_encode($resumed->toArray()), "graine $seed : état final différent après reprise à #$seq");
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function roundTripSeeds(): iterable
    {
        foreach ([1, 6, 16, 42, 122] as $seed) {
            yield "graine $seed" => [$seed, false];
        }
        yield 'graine 11, fetchlands' => [11, true];
    }

    /**
     * Joue la graine en relevant l'état à chaque checkpoint, puis vérifie les invariants sur le flux d'événements.
     *
     * @param (callable(int): array{commander: CardInstance, library: list<CardInstance>})|null $decks
     * @return list<string> types d'événements rencontrés (pour des assertions de couverture)
     */
    private function checkSeed(int $seed, ?callable $decks): array
    {
        $recorder = new class () implements CheckpointListener {
            /** @var array<int, array{cards: array<int, int>, stack: int, pending: int, poolsEmpty: bool, life: array<int, int>, problems: list<string>}> */
            public array $records = [];

            public function onCheckpoint(GameState $state): void
            {
                $this->records[$state->eventSeq] = InvariantsTest::observe($state);
            }
        };
        $state = GameFactory::create($seed, self::FOUR_AI, $decks);
        $result = (new GameEngine(new AgentRegistry(), $recorder))->advance($state);
        self::assertTrue($result->over, "graine $seed : la partie ne s'est pas terminée");

        $events = $result->events;
        // Violations collectées puis vérifiées en une assertion (une assertion PHPUnit par événement coûte trop cher).
        $failures = [];
        $check = static function (bool $ok, GameEvent $e, string $message) use (&$failures, $seed): void {
            if (!$ok) {
                $failures[] = "graine $seed, #$e->seq $e->type : $message";
            }
        };

        $life = [];
        $deckSize = [];
        $leftGame = [];
        /** @var array<string, string|null> $stack stackId => id de la carte du sort (null pour une capacité ou un trigger) */
        $stack = [];
        $tokens = [];
        $newStep = false;
        $checkpoints = 0;
        $types = [];

        foreach ($events as $i => $e) {
            $types[$e->type] = true;
            $p = $e->payload;
            switch ($e->type) {
                case 'GameStarted':
                    foreach ($p['players'] as $player) {
                        $life[$player['id']] = $player['life'];
                        $deckSize[$player['id']] = $player['library'] + count($player['command']);
                        $leftGame[$player['id']] = 0;
                    }
                    break;

                case 'PriorityGranted':
                    $check(count($stack) === $p['stackSize'], $e, 'stackSize ≠ pile suivie');
                    if ($p['checkpoint'] !== true) {
                        $check(!isset($recorder->records[$e->seq]), $e, 'état relevé hors checkpoint');
                        break;
                    }
                    $checkpoints++;
                    $r = $recorder->records[$e->seq] ?? null;
                    if ($r === null) {
                        $check(false, $e, 'checkpoint sans état relevé');
                        break;
                    }
                    $check($r['problems'] === [], $e, implode(' ; ', $r['problems']));
                    $check($r['pending'] === 0, $e, 'trigger en attente à un checkpoint');
                    $check($r['stack'] === count($stack), $e, 'pile de l\'état ≠ pile suivie');
                    $check($r['life'] === $life, $e, 'PV de l\'état ≠ PV des événements');
                    foreach ($deckSize as $owner => $size) {
                        $check(($r['cards'][$owner] ?? 0) + $leftGame[$owner] === $size, $e, "cartes de J$owner non conservées");
                    }
                    if ($newStep) {
                        $check($r['poolsEmpty'], $e, 'réserve de mana non vide au début de l\'étape');
                        $newStep = false;
                    }
                    break;

                case 'PhaseChanged':
                    $check($stack === [], $e, 'pile non vide au changement d\'étape');
                    $newStep = true;
                    break;

                case 'SpellCast':
                    $stack[$p['stackId']] = $p['card']['id'];
                    break;

                case 'AbilityActivated':
                case 'AbilityTriggered':
                    $stack[$p['stackId']] = null;
                    break;

                case 'SpellResolved':
                case 'SpellCountered':
                case 'AbilityResolved':
                    $check(array_key_exists($p['stackId'], $stack), $e, 'élément de pile inconnu');
                    unset($stack[$p['stackId']]);
                    break;

                case 'TokenCreated':
                    $tokens[$p['card']['id']] = true;
                    break;

                case 'PlayerLeftGame':
                    foreach ($p['battlefield'] as $id) {
                        if (!isset($tokens[$id])) {
                            $leftGame[self::owner($id)]++;
                        }
                    }
                    foreach ($p['stack'] as $stackId) {
                        $check(array_key_exists($stackId, $stack), $e, 'élément de pile inconnu');
                        $cardId = $stack[$stackId] ?? null;
                        if ($cardId !== null && !isset($tokens[$cardId])) {
                            $leftGame[self::owner($cardId)]++;
                        }
                        unset($stack[$stackId]);
                    }
                    break;

                case 'DamageDealt':
                    if (str_starts_with($p['target'], 'player:')) {
                        $id = (int) substr($p['target'], 7);
                        $life[$id] -= $p['amount'];
                        $check($life[$id] === $p['life'], $e, 'PV résultants incohérents');
                    }
                    break;

                case 'LifePaid':
                    $life[$p['player']] -= $p['amount'];
                    $check($life[$p['player']] === $p['life'], $e, 'PV résultants incohérents');
                    break;

                case 'LifeGained':
                    $life[$p['player']] += $p['amount'];
                    $check($life[$p['player']] === $p['life'], $e, 'PV résultants incohérents');
                    break;

                case 'PlayerLost':
                    if ($p['reason'] === 'life') {
                        $check($life[$p['player']] <= 0, $e, 'défaite aux PV avec des PV positifs');
                    }
                    break;

                case 'ManaPoolEmptied':
                    $check($p['lost'] !== [] && $p['pool'] === [] && !isset($p['restricted']), $e, 'payload de vidage incohérent');
                    $next = $events[$i + 1] ?? null;
                    $check(in_array($next?->type, ['ManaPoolEmptied', 'PhaseChanged', 'GameOver'], true), $e, 'vidage de réserve hors fin d\'étape');
                    break;
            }
        }

        self::assertSame([], array_slice($failures, 0, 20), "graine $seed : invariants violés (" . count($failures) . ')');
        self::assertGreaterThan(0, $checkpoints);
        self::assertSame('GameOver', end($events)->type, "graine $seed : dernier événement");
        // Fin de partie : la pile peut rester non vide (tranché, docs/EVENTS.md) ; elle doit alors égaler celle de l'état.
        self::assertSame(count($stack), count($state->stack), "graine $seed : pile finale");
        $final = self::observe($state);
        self::assertSame([], $final['problems'], "graine $seed : état final");
        self::assertSame($life, $final['life'], "graine $seed : PV finaux ≠ PV des événements");
        foreach ($deckSize as $owner => $size) {
            self::assertSame($size, ($final['cards'][$owner] ?? 0) + $leftGame[$owner], "graine $seed : cartes de J$owner non conservées en fin de partie");
        }

        return array_keys($types);
    }

    /**
     * Relevé de l'état (sans le modifier) : cartes par propriétaire (jetons exclus, sorts sur la pile compris), éléments de
     * pile, triggers en attente, réserves, PV, et anomalies (carte en double, carte hors des zones de son propriétaire).
     *
     * @return array{cards: array<int, int>, stack: int, pending: int, poolsEmpty: bool, life: array<int, int>, problems: list<string>}
     */
    public static function observe(GameState $s): array
    {
        $cards = [];
        $seen = [];
        $problems = [];
        $count = static function (CardInstance $card, int $zoneOwner, string $zone) use (&$cards, &$seen, &$problems): void {
            if ($card->token) {
                if ($zone !== 'battlefield' && $zone !== 'stack') {
                    $problems[] = "jeton {$card->id} en zone $zone";
                }

                return;
            }
            if (isset($seen[$card->id])) {
                $problems[] = "carte {$card->id} à la fois en {$seen[$card->id]} et en $zone";
            }
            $seen[$card->id] = $zone;
            $owner = self::owner($card->id);
            if ($owner !== $zoneOwner) {
                $problems[] = "carte {$card->id} dans la zone $zone de J$zoneOwner";
            }
            $cards[$owner] = ($cards[$owner] ?? 0) + 1;
        };

        $poolsEmpty = true;
        $life = [];
        foreach ($s->players as $p) {
            foreach (['library', 'hand', 'battlefield', 'graveyard', 'exile', 'command'] as $zone) {
                foreach ($p->{$zone} as $card) {
                    $count($card, $p->id, $zone);
                }
            }
            $poolsEmpty = $poolsEmpty && $p->pool->isEmpty();
            $life[$p->id] = $p->life;
        }
        foreach ($s->stack as $item) {
            if (!$item->isAbility()) {
                $count($item->card, $item->controller, 'stack');
            }
        }

        return ['cards' => $cards, 'stack' => count($s->stack), 'pending' => count($s->pendingTriggers), 'poolsEmpty' => $poolsEmpty, 'life' => $life, 'problems' => $problems];
    }

    /** Propriétaire d'une carte d'après son id (`p<siège>_c<n>`, `p<siège>_t<n>` pour un jeton). */
    private static function owner(string $cardId): int
    {
        if (preg_match('/^p(\d+)_[ct]\d+$/', $cardId, $m) !== 1) {
            throw new \UnexpectedValueException("Id de carte inattendu : $cardId");
        }

        return (int) $m[1];
    }

    /**
     * @param (callable(int): array{commander: CardInstance, library: list<CardInstance>})|null $decks
     * @return array{list<GameEvent>, GameState}
     */
    private static function play(int $seed, ?callable $decks): array
    {
        $state = GameFactory::create($seed, self::FOUR_AI, $decks);
        $result = (new GameEngine(new AgentRegistry()))->advance($state);

        return [$result->events, $state];
    }

    /** Empreinte du flux d'événements, même méthode que bin/bench.php (type + payload JSON, une ligne par événement). */
    private static function fingerprint(array $events): string
    {
        $hash = hash_init('sha1');
        foreach ($events as $e) {
            hash_update($hash, $e->type . json_encode($e->payload, JSON_UNESCAPED_UNICODE) . "\n");
        }

        return hash_final($hash);
    }

    /**
     * Decks de démo dont les 8 derniers terrains de base sont remplacés par des éphémères incolores à {1} qui exilent une
     * créature (4) ou un permanent (4), sous le même id (noms distincts : singleton).
     *
     * @return callable(int): array{commander: CardInstance, library: list<CardInstance>}
     */
    private static function exileDecks(): callable
    {
        return static function (int $id): array {
            $deck = Decks::commanderDemo($id);
            for ($k = 0; $k < 8; $k++) {
                $i = count($deck['library']) - 1 - $k;
                $target = $k < 4 ? 'creature' : 'permanent';
                $deck['library'][$i] = new CardInstance($deck['library'][$i]->id, "Exil test $k", CardInstance::INSTANT, 1, effects: [new SpellEffect('exile', target: $target)]);
            }

            return $deck;
        };
    }

    /**
     * Deux decks à shocklands / fetchlands faits de cartes Scryfall figées : Dimir (J0, J2) et blanc (J1, J3).
     *
     * @return callable(int): array{commander: CardInstance, library: list<CardInstance>}
     */
    private static function fetchDecks(): callable
    {
        $mapper = new CardMapper();
        $fixtures = ScryfallFixtures::all();
        // Commandant Dimir de test (aucun commandant bleu-noir dans les cartes figées).
        $fixtures['Dimir Test Rogue'] = [
            'name' => 'Dimir Test Rogue', 'layout' => 'normal', 'type_line' => 'Legendary Creature — Human Rogue', 'mana_cost' => '{U}{B}',
            'cmc' => 2.0, 'oracle_text' => '', 'keywords' => [], 'power' => '2', 'toughness' => '2',
        ];
        $build = static function (string $commander, array $singles, array $basics) use ($mapper, $fixtures): LoadedDeck {
            $library = [];
            foreach ($singles as $name) {
                $library[] = $mapper->map($fixtures[$name]);
            }
            foreach ($basics as $name => $n) {
                $definition = $mapper->map($fixtures[$name]);
                for ($i = 0; $i < $n; $i++) {
                    $library[] = $definition;
                }
            }

            return new LoadedDeck($mapper->map($fixtures[$commander]), $library);
        };
        $dimir = $build('Dimir Test Rogue', [
            'Watery Grave', 'Polluted Delta', 'Prismatic Vista', 'Evolving Wilds', 'Underground Sea', 'Underground River',
            'Mana Confluence', 'Ancient Tomb', 'Command Tower', 'Sol Ring', 'Mind Stone', 'Talisman of Dominance', 'Lotus Petal',
            'Counterspell', 'Dark Ritual', 'Divination', 'Wind Drake', 'Perilous Myr',
        ], ['Island' => 40, 'Swamp' => 41]);
        $white = $build('Isamaru, Hound of Konda', [
            'Prismatic Vista', 'Evolving Wilds', 'Mana Confluence', 'Ancient Tomb', 'Command Tower', 'Sol Ring', 'Mind Stone',
            'Lotus Petal', 'Serra Angel', 'Wall of Omens', 'Perilous Myr',
        ], ['Plains' => 88]);

        return static fn (int $id): array => ($id % 2 === 0 ? $dimir : $white)->instantiate($id);
    }
}
