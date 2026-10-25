<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Snapshot\InMemoryCheckpoints;
use App\Service\Deck\DeckLoader;
use App\Service\Scryfall\CardCatalog;
use App\Tests\Service\Deck\ScryfallFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Teste la couche Symfony de bout en bout (contrôleur -> service -> moteur -> Doctrine/MySQL),
 * avec le même flux que le front (frontend/src/api/client.ts).
 */
final class SimulationApiTest extends WebTestCase
{
    private const BASE = '/api/simulations';
    private const FOUR_AI = ['0' => 'ai:heuristic', '1' => 'ai:heuristic', '2' => 'ai:heuristic', '3' => 'ai:heuristic'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /** @return array{0:int,1:mixed} statut HTTP et corps JSON décodé */
    private function call(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], $body === null ? null : json_encode($body));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** @return array<string,mixed> */
    private function createGame(int $seed, ?array $controllers = null): array
    {
        [$status, $sim] = $this->call('POST', self::BASE, ['seed' => $seed] + ($controllers === null ? [] : ['controllers' => $controllers]));
        self::assertSame(202, $status);

        return $sim;
    }

    /**
     * Partie avec J0 humain, mulligan passé : la première décision est l'avant-partie (garder ou mulligan), on garde.
     *
     * @return array<string,mixed>
     */
    private function createKept(int $seed): array
    {
        $sim = $this->createGame($seed);
        self::assertSame('waiting', $sim['status']);
        [$status, $sim] = $this->call('POST', self::BASE . "/{$sim['id']}/decisions", ['actionId' => 'keep:0']);
        self::assertSame(200, $status);

        return $sim;
    }

    /** @return list<array{seq:int,type:string,payload:array,verbose:bool}> */
    private function events(int $id): array
    {
        [$status, $events] = $this->call('GET', self::BASE . "/$id/events");
        self::assertSame(200, $status);

        return $events;
    }

    /** Premier checkpoint (PriorityGranted checkpoint=true) dont le seq est >= $min. */
    private function checkpointSeq(array $events, int $min = 100): int
    {
        foreach ($events as $e) {
            if ($e['type'] === 'PriorityGranted' && ($e['payload']['checkpoint'] ?? false) && $e['seq'] >= $min) {
                return $e['seq'];
            }
        }
        self::fail('Aucun checkpoint trouvé');
    }

    public function testQuatreIaJouentUnePartieCompleteEtLeJournalEstServi(): void
    {
        $sim = $this->createGame(42, self::FOUR_AI);

        self::assertSame('over', $sim['status']);
        self::assertNull($sim['pending']);
        // Même partie que bin/smoke.php 42 : le moteur seul donne le nombre d'événements attendu.
        $expected = count((new \App\Engine\Game\GameEngine(new \App\Engine\Agent\AgentRegistry()))->advance(
            \App\Engine\Game\GameFactory::create(42, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']),
        )->events);
        self::assertSame($expected, $sim['eventCount']);

        $events = $this->events($sim['id']);
        self::assertCount($expected, $events);
        self::assertSame('GameOver', end($events)['type']);
        $seqs = array_column($events, 'seq');
        $sorted = $seqs;
        sort($sorted);
        self::assertSame($sorted, $seqs, 'Journal ordonné');
        self::assertSame(count($seqs), count(array_unique($seqs)), 'Pas de seq en double');
    }

    public function testParDefautLeSiegeZeroEstHumainEtLaPartieAttendUneDecision(): void
    {
        $sim = $this->createGame(7);

        self::assertSame('waiting', $sim['status']);
        self::assertSame(0, $sim['pending']['player']);
        self::assertNotEmpty($sim['pending']['actions']);

        [$status, $pending] = $this->call('GET', self::BASE . "/{$sim['id']}/pending");
        self::assertSame(200, $status);
        self::assertSame($sim['pending'], $pending, 'Colonnes json_text : le texte est stocké tel quel, clés dans le même ordre');
    }

    public function testLeJournalDeLaBaseEstExactementCelDuMoteurClesDansLOrdre(): void
    {
        $sim = $this->createGame(42, self::FOUR_AI);

        // Référence : la même partie jouée directement par le moteur, sans passer par l'API ni la base.
        $state = GameFactory::create(42, array_fill(0, 4, 'ai:heuristic'));
        $result = (new GameEngine(new AgentRegistry(), new InMemoryCheckpoints()))->advance($state);
        $expected = [];
        foreach ($result->events as $e) {
            $expected[] = ['seq' => $e->seq, 'type' => $e->type, 'payload' => $e->payload, 'verbose' => $e->verbose];
        }

        // assertSame (et non assertEquals) : ni perte, ni doublon, ni changement de l'ordre des clés
        // après l'insertion par lots et l'aller-retour dans la colonne json_text.
        self::assertSame(
            json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true),
            $this->events($sim['id']),
        );
    }

    public function testDecisionsValidesInvalidesEtSurPartieTerminee(): void
    {
        $sim = $this->createKept(7);
        $id = $sim['id'];

        [$status, $body] = $this->call('POST', self::BASE . "/$id/decisions", ['actionId' => 'nimporte:quoi']);
        self::assertSame(422, $status);
        self::assertStringContainsString('illégale', $body['error']);

        $land = array_values(array_filter($sim['pending']['actions'], static fn (array $a) => $a['type'] === 'land'))[0];
        [$status, $after] = $this->call('POST', self::BASE . "/$id/decisions", ['actionId' => $land['id']]);
        self::assertSame(200, $status);
        self::assertGreaterThan($sim['eventCount'], $after['eventCount']);

        $over = $this->createGame(42, self::FOUR_AI);
        [$status] = $this->call('POST', self::BASE . "/{$over['id']}/decisions", ['actionId' => 'pass:0']);
        self::assertSame(409, $status);
    }

    public function testMulliganDeJ0AvantLaPremierePrioriteEtReconstruction(): void
    {
        $sim = $this->createGame(7);
        self::assertSame('waiting', $sim['status']);
        self::assertSame(['keep:0', 'mulligan:0'], array_column($sim['pending']['actions'], 'id'));
        self::assertSame(['keep', 'mulligan'], array_column($sim['pending']['actions'], 'type'));
        $id = $sim['id'];

        // Avant le premier PriorityGranted : aucun checkpoint, donc ni état reconstruit ni fork.
        self::assertSame([], array_filter($this->events($id), static fn (array $e) => $e['type'] === 'PriorityGranted'));
        [, $none] = $this->call('GET', self::BASE . "/$id/state?at={$sim['eventCount']}");
        self::assertSame(['error' => 'Aucun checkpoint'], $none);
        [$status] = $this->call('POST', self::BASE . "/$id/fork", ['atEvent' => $sim['eventCount']]);
        self::assertSame(409, $status);

        // Deux mulligans (le premier gratuit), garder, une carte au fond.
        foreach (['mulligan:0', 'mulligan:0', 'keep:0'] as $actionId) {
            [$status, $sim] = $this->call('POST', self::BASE . "/$id/decisions", ['actionId' => $actionId]);
            self::assertSame(200, $status, $actionId);
        }
        $bottoms = $sim['pending']['actions'];
        self::assertCount(7, $bottoms);
        self::assertSame(array_fill(0, 7, 'bottom'), array_column($bottoms, 'type'));
        self::assertStringStartsWith('bottom:0:', $bottoms[0]['id']);
        [$status, $sim] = $this->call('POST', self::BASE . "/$id/decisions", ['actionId' => $bottoms[0]['id']]);
        self::assertSame(200, $status);
        self::assertNotContains('bottom', array_column($sim['pending']['actions'] ?? [], 'type'), 'La partie a commencé');

        $events = $this->events($id);
        $types = array_column($events, 'type');
        $mulligansOfJ0 = array_filter($events, static fn (array $e) => $e['type'] === 'MulliganTaken' && $e['payload']['player'] === 0);
        self::assertCount(2, $mulligansOfJ0, 'Les IA peuvent aussi prendre des mulligans');
        self::assertSame(4, count(array_keys($types, 'HandKept', true)));
        $put = array_values(array_filter($events, static fn (array $e) => $e['type'] === 'CardPutOnBottom' && $e['payload']['player'] === 0));
        self::assertCount(1, $put);
        self::assertSame(6, $put[0]['payload']['hand']);

        // Premier checkpoint reconstruit par rejeu depuis l'ancre de pause (décision de mise au fond).
        $first = $this->checkpointSeq($events, 0);
        [$status, $state] = $this->call('GET', self::BASE . "/$id/state?at=$first");
        self::assertSame(200, $status);
        self::assertSame($first, $state['eventSeq']);
        self::assertArrayNotHasKey('mulligan', $state['state']);
        self::assertCount(6, $state['state']['players'][0]['hand']);
        [$status, $fork] = $this->call('POST', self::BASE . "/$id/fork", ['atEvent' => $first]);
        self::assertSame(201, $status);
        self::assertSame($first, $fork['forkEventSeq']);
    }

    public function testUnHumainQuiPasseToujoursMeneLaPartieAuBout(): void
    {
        $sim = $this->createGame(7);
        $decisions = 0;
        while ($sim['status'] === 'waiting' && $decisions < 500) {
            // « Passer toujours » : passer ; la défausse (main > 7 au cleanup) est obligatoire, on prend la première carte proposée.
            $actions = $sim['pending']['actions'];
            $pass = array_values(array_filter($actions, static fn (array $a) => in_array($a['type'], ['pass', 'done'], true)))[0] ?? $actions[0];
            [$status, $sim] = $this->call('POST', self::BASE . "/{$sim['id']}/decisions", ['actionId' => $pass['id']]);
            self::assertSame(200, $status);
            ++$decisions;
        }

        self::assertSame('over', $sim['status']);
        self::assertGreaterThan(0, $decisions);
        self::assertLessThan(500, $decisions);
    }

    public function testForkPrendUnSiegeEtReprendAuCheckpoint(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $at = $this->checkpointSeq($this->events($parent['id']));

        [$status, $fork] = $this->call('POST', self::BASE . "/{$parent['id']}/fork", ['atEvent' => $at, 'controllers' => ['1' => 'human:auto']]);

        self::assertSame(201, $status);
        self::assertSame($parent['id'], $fork['parentId']);
        self::assertLessThanOrEqual($at, $fork['forkEventSeq']);
        self::assertSame('waiting', $fork['status']);
        self::assertSame(1, $fork['pending']['player']);
        self::assertSame('human:auto', $fork['controllers'][1]);
    }

    public function testForkRendLaMainALIaDansUneBranche(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $at = $this->checkpointSeq($this->events($parent['id']));
        [, $human] = $this->call('POST', self::BASE . "/{$parent['id']}/fork", ['atEvent' => $at, 'controllers' => ['1' => 'human:auto']]);

        [$status, $back] = $this->call('POST', self::BASE . "/{$human['id']}/fork", ['atEvent' => $human['forkEventSeq'], 'controllers' => ['1' => 'ai:aggro']]);

        self::assertSame(201, $status);
        self::assertSame($human['id'], $back['parentId']);
        self::assertSame('ai:aggro', $back['controllers'][1]);
        self::assertNull($back['pending'], 'Plus aucune décision humaine à attendre');
        self::assertSame('human:auto', $human['controllers'][1], 'La branche d\'origine garde son humain');
    }

    public function testBranchesDonneLArbreEntier(): void
    {
        $root = $this->createGame(42, self::FOUR_AI);
        $at = $this->checkpointSeq($this->events($root['id']));
        [, $a] = $this->call('POST', self::BASE . "/{$root['id']}/fork", ['atEvent' => $at, 'controllers' => ['1' => 'human:auto']]);
        [, $b] = $this->call('POST', self::BASE . "/{$root['id']}/fork", ['atEvent' => $at, 'controllers' => ['2' => 'human:auto']]);
        [, $aa] = $this->call('POST', self::BASE . "/{$a['id']}/fork", ['atEvent' => $a['forkEventSeq'], 'controllers' => ['3' => 'ai:control']]);

        [$status, $tree] = $this->call('GET', self::BASE . "/{$aa['id']}/branches");

        self::assertSame(200, $status);
        self::assertSame($aa['id'], $tree['current']);
        $byId = [];
        foreach ($tree['branches'] as $node) {
            $byId[$node['id']] = $node;
        }
        self::assertSame([$root['id'], $a['id'], $b['id'], $aa['id']], array_keys($byId), 'Racine d\'abord, puis les fils en largeur');
        self::assertNull($byId[$root['id']]['parentId']);
        self::assertSame($root['id'], $byId[$a['id']]['parentId']);
        self::assertSame($a['id'], $byId[$aa['id']]['parentId']);
        self::assertSame([1], $byId[$a['id']]['humans']);
        self::assertSame([], $byId[$root['id']]['humans']);
        self::assertSame($a['forkEventSeq'], $byId[$aa['id']]['forkEventSeq']);
    }

    public function testForkEchoueProprementSurEntreesInvalides(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $at = $this->checkpointSeq($this->events($parent['id']));
        $uri = self::BASE . "/{$parent['id']}/fork";

        [$status] = $this->call('POST', $uri, ['atEvent' => 0]);
        self::assertSame(409, $status, 'Aucun checkpoint avant l\'événement 0');

        [$status] = $this->call('POST', $uri, ['atEvent' => $at, 'controllers' => ['1' => 'robot']]);
        self::assertSame(422, $status, 'Agent inconnu');

        [$status] = $this->call('POST', $uri, ['atEvent' => $at, 'controllers' => ['9' => 'human:auto']]);
        self::assertSame(422, $status, 'Joueur inconnu');
    }

    public function testLeJournalDuForkEstLeJournalDuParentJusquaLaBifurcationPuisLeSien(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $parentEvents = $this->events($parent['id']);
        $at = $this->checkpointSeq($parentEvents);
        [, $fork] = $this->call('POST', self::BASE . "/{$parent['id']}/fork", ['atEvent' => $at, 'controllers' => ['1' => 'human:auto']]);

        $events = $this->events($fork['id']);
        $seqs = array_column($events, 'seq');

        self::assertSame(count($seqs), count(array_unique($seqs)), 'Aucun doublon de seq');
        $sorted = $seqs;
        sort($sorted);
        self::assertSame($sorted, $seqs, 'Ordre croissant');
        self::assertSame($fork['eventCount'], max($seqs));

        $beforeFork = array_values(array_filter($events, static fn (array $e) => $e['seq'] <= $fork['forkEventSeq']));
        $parentBefore = array_values(array_filter($parentEvents, static fn (array $e) => $e['seq'] <= $fork['forkEventSeq']));
        self::assertSame($parentBefore, $beforeFork, 'Avant la bifurcation : exactement le journal du parent');
        self::assertGreaterThan(count($beforeFork), count($events), 'Le fork a ses propres événements');
    }

    public function testUnForkSansChangementRejoueExactementLaFinDeLaPartieParente(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $parentEvents = $this->events($parent['id']);
        $at = $this->checkpointSeq($parentEvents);

        [$status, $fork] = $this->call('POST', self::BASE . "/{$parent['id']}/fork", ['atEvent' => $at]);
        self::assertSame(201, $status);
        self::assertSame('over', $fork['status']);

        self::assertSame($parentEvents, $this->events($fork['id']), 'Reprise identique (déterminisme)');
        self::assertSame($parent['eventCount'], $fork['eventCount']);
    }

    public function testStateRenvoieLeCheckpointLePlusProcheMemeAvantLaBifurcation(): void
    {
        $parent = $this->createGame(42, self::FOUR_AI);
        $at = $this->checkpointSeq($this->events($parent['id']), 150);
        [, $fork] = $this->call('POST', self::BASE . "/{$parent['id']}/fork", ['atEvent' => $at, 'controllers' => ['1' => 'human:auto']]);

        [$status, $state] = $this->call('GET', self::BASE . "/{$parent['id']}/state?at=$at");
        self::assertSame(200, $status);
        self::assertSame($at, $state['eventSeq']);
        self::assertArrayHasKey('players', $state['state']);

        // Antérieur à la bifurcation : le checkpoint vit chez l'ancêtre.
        [$status, $early] = $this->call('GET', self::BASE . "/{$fork['id']}/state?at=" . ($at - 1));
        self::assertSame(200, $status);
        self::assertLessThan($at, $early['eventSeq']);

        [$status, $none] = $this->call('GET', self::BASE . "/{$parent['id']}/state?at=0");
        self::assertSame(200, $status);
        self::assertSame(['error' => 'Aucun checkpoint'], $none);
    }

    public function testCasLimitesHttp(): void
    {
        [$status] = $this->call('GET', self::BASE . '/9999');
        self::assertSame(404, $status);

        [$status] = $this->call('GET', self::BASE . '/abc');
        self::assertSame(404, $status);

        $this->client->request('POST', self::BASE, [], [], ['CONTENT_TYPE' => 'application/json'], '{pas du json');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        [$status] = $this->call('POST', self::BASE, ['seed' => 1, 'controllers' => ['0' => 'robot']]);
        self::assertSame(422, $status, 'Agent inconnu à la création');
    }

    private function stopsUri(int $id): string
    {
        return self::BASE . "/$id/stops";
    }

    private const NO_STEPS = ['steps' => [], 'onOpponentSpell' => false, 'onDeclareAttackers' => false, 'onDeclareBlockers' => false];

    public function testStopsChangesAuPointCourantEtExposesDansLaReponse(): void
    {
        $sim = $this->createKept(7);
        self::assertSame([], $sim['stops']);
        $seq = $sim['eventCount'];

        [$status, $after] = $this->call('PUT', $this->stopsUri($sim['id']), ['player' => 0, 'stops' => self::NO_STEPS]);
        self::assertSame(200, $status);
        self::assertSame($sim['id'], $after['id']);
        self::assertSame(self::NO_STEPS, $after['stops']['0']);
        self::assertGreaterThan($seq, $after['eventCount'], 'Sans aucun stop, l\'agent passe et la partie avance');

        [, $shown] = $this->call('GET', self::BASE . "/{$sim['id']}");
        self::assertSame(self::NO_STEPS, $shown['stops']['0']);
    }

    public function testStopsEntrees404Et400Et409(): void
    {
        $sim = $this->createGame(7);
        $uri = $this->stopsUri($sim['id']);

        [$status] = $this->call('PUT', $uri, ['player' => 0, 'stops' => ['steps' => ['nimporte']]]);
        self::assertSame(400, $status, 'Étape inconnue (ValueError)');
        [$status] = $this->call('PUT', $uri, ['player' => 9, 'stops' => null]);
        self::assertSame(400, $status, 'Joueur inconnu');
        [$status] = $this->call('PUT', $uri, ['player' => 1, 'stops' => null]);
        self::assertSame(400, $status, 'Joueur IA refusé');
        [$status] = $this->call('PUT', $uri, ['stops' => null]);
        self::assertSame(400, $status, 'Joueur manquant');
        [$status] = $this->call('PUT', $uri, ['player' => 0, 'stops' => 'x']);
        self::assertSame(400, $status);

        $over = $this->createGame(42, self::FOUR_AI);
        [$status] = $this->call('PUT', $this->stopsUri($over['id']), ['player' => 0, 'stops' => null]);
        self::assertSame(409, $status, 'Partie terminée');
        [$status] = $this->call('PUT', $this->stopsUri(999999), ['player' => 0, 'stops' => null]);
        self::assertSame(404, $status);
    }

    public function testStopsDoubleChangementAuMemePointEtReconstruction(): void
    {
        $sim = $this->createKept(7);
        $id = $sim['id'];
        $two = ['steps' => ['main2', 'end'], 'onOpponentSpell' => false, 'onDeclareAttackers' => true, 'onDeclareBlockers' => false];
        $one = ['steps' => ['main1'], 'onOpponentSpell' => true, 'onDeclareAttackers' => false, 'onDeclareBlockers' => false];

        [$s1] = $this->call('PUT', $this->stopsUri($id), ['player' => 0, 'stops' => $one]);
        self::assertSame(200, $s1);
        [$s2, $r2] = $this->call('PUT', $this->stopsUri($id), ['player' => 0, 'stops' => $two]);
        self::assertSame(200, $s2);
        self::assertSame($two, $r2['stops']['0']);

        [$s3, $r3] = $this->call('PUT', $this->stopsUri($id), ['player' => 0, 'stops' => null]);
        self::assertSame(200, $s3);
        self::assertArrayNotHasKey('0', $r3['stops']);

        // Branche forkée : l'ancre de fork et l'ancre de stops tombent au même seq.
        $at = $this->checkpointSeq($this->events($id), 20);
        [, $fork] = $this->call('POST', self::BASE . "/$id/fork", ['atEvent' => $at, 'controllers' => ['0' => 'human:auto']]);
        [$s4, $r4] = $this->call('PUT', $this->stopsUri($fork['id']), ['player' => 0, 'stops' => $one]);
        self::assertSame(200, $s4);
        [$s5, $r5] = $this->call('PUT', $this->stopsUri($fork['id']), ['player' => 0, 'stops' => $two]);
        self::assertSame(200, $s5);
        self::assertGreaterThanOrEqual($r4['eventCount'], $r5['eventCount']);

        // Reconstruction d'un checkpoint postérieur : l'état rejoué porte les stops.
        [$st, $here] = $this->call('GET', self::BASE . "/{$fork['id']}/state?at={$r5['eventCount']}");
        self::assertSame(200, $st);
        self::assertSame($two, $here['state']['stops']['0']);

        // Avec un stop sur chaque étape, on décide « passer » puis on reconstruit un checkpoint postérieur par rejeu.
        $all = ['steps' => ['main1', 'main2', 'end']] + $one;
        [, $r6] = $this->call('PUT', $this->stopsUri($fork['id']), ['player' => 0, 'stops' => $all]);
        self::assertSame('waiting', $r6['status']);
        $pass = array_values(array_filter($r6['pending']['actions'], static fn (array $a) => str_starts_with($a['id'], 'pass')))[0]['id'];
        [$sd] = $this->call('POST', self::BASE . "/{$fork['id']}/decisions", ['actionId' => $pass]);
        self::assertSame(200, $sd);
        $later = $this->checkpointSeq($this->events($fork['id']), $r6['eventCount'] + 1);
        [$st, $state] = $this->call('GET', self::BASE . "/{$fork['id']}/state?at=$later");
        self::assertSame(200, $st);
        self::assertSame($later, $state['eventSeq']);
        self::assertSame(['main1', 'main2', 'end'], $state['state']['stops']['0']['steps']);
    }

    public function testStopsChangeablesALaPauseHorsCheckpoint(): void
    {
        $sim = $this->createKept(7);
        $id = $sim['id'];
        $end = ['steps' => ['end'], 'onOpponentSpell' => false, 'onDeclareAttackers' => false, 'onDeclareBlockers' => false];

        // Arrêt en fin de tour (une priorité de J0 qui n'ouvre pas forcément de ronde), puis retour au défaut à cette pause.
        [$s1, $r1] = $this->call('PUT', $this->stopsUri($id), ['player' => 0, 'stops' => $end]);
        self::assertSame(200, $s1);
        self::assertSame('waiting', $r1['status']);
        [$s2, $r2] = $this->call('PUT', $this->stopsUri($id), ['player' => 0, 'stops' => null]);
        self::assertSame(200, $s2, 'La pause humaine est un point d\'ancre : les stops s\'y changent');
        self::assertArrayNotHasKey('0', $r2['stops']);

        // La décision suivante se prend normalement et le rejeu repart de l'ancre posée à la pause.
        [$sd] = $this->call('POST', self::BASE . "/$id/decisions", ['actionId' => $r2['pending']['actions'][0]['id']]);
        self::assertSame(200, $sd);
        $later = $this->checkpointSeq($this->events($id), $r2['eventCount'] + 1);
        [$st, $state] = $this->call('GET', self::BASE . "/$id/state?at=$later");
        self::assertSame(200, $st);
        self::assertSame($later, $state['eventSeq']);
        self::assertArrayNotHasKey('stops', $state['state']);
    }

    public function testQueuedSimulationExposesStatusAndRefusesGameEndpoints(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sim = new \App\Entity\Simulation(42);
        $sim->setStatus(\App\Entity\Simulation::QUEUED);
        $sim->setControllers([0 => 'ai:heuristic']);
        $em->persist($sim);
        $em->flush();
        $id = $sim->getId();

        [$status, $view] = $this->call('GET', self::BASE . "/$id");
        self::assertSame(200, $status);
        self::assertSame('queued', $view['status']);
        self::assertNull($view['progress']);

        foreach ([['GET', "/$id/events"], ['GET', "/$id/state"], ['POST', "/$id/decisions"], ['POST', "/$id/fork"], ['PUT', "/$id/stops"]] as [$method, $path]) {
            [$status] = $this->call($method, self::BASE . $path, $method === 'GET' ? null : ['actionId' => 'x', 'atEvent' => 1, 'player' => 0, 'stops' => null]);
            self::assertSame(409, $status, "$method $path");
        }
    }

    private function useFixtureCatalog(): void
    {
        $catalog = $this->createStub(CardCatalog::class);
        $catalog->method('get')->willReturnCallback(static function (array $names): array {
            $fixtures = ScryfallFixtures::all();
            $cards = [];
            $missing = [];
            foreach ($names as $n) {
                isset($fixtures[$n])
                    ? $cards[$n] = ['data' => $fixtures[$n], 'images' => ['small' => "s/$n", 'normal' => "n/$n"], 'fetchedAt' => new \DateTimeImmutable(), 'stale' => false]
                    : $missing[] = $n;
            }

            return ['cards' => $cards, 'missing' => $missing, 'degraded' => false];
        });
        static::getContainer()->set(DeckLoader::class, new DeckLoader($catalog));
    }

    public function testCustomDeckIsPlayedAndImagesExposed(): void
    {
        $this->useFixtureCatalog();
        $list = "CMDR: Isamaru, Hound of Konda\n1 Sol Ring\n98 Plains";
        [$status, $sim] = $this->call('POST', self::BASE, ['seed' => 5, 'controllers' => self::FOUR_AI, 'decks' => ['1' => $list]]);
        self::assertSame(202, $status);
        self::assertSame('n/Sol Ring', ((array) $sim['cardImages'])['Sol Ring']['normal']);

        [, $view] = $this->call('GET', self::BASE . '/' . $sim['id']);
        self::assertSame('over', $view['status']);
        self::assertSame('n/Plains', $view['cardImages']['Plains']['normal']);
        [, $events] = $this->call('GET', self::BASE . '/' . $sim['id'] . '/events');
        $started = array_values(array_filter($events, static fn (array $e) => $e['type'] === 'GameStarted'))[0];
        self::assertSame('Isamaru, Hound of Konda', $started['payload']['players'][1]['command'][0]['name']);
        self::assertStringStartsWith('Commandant J0', $started['payload']['players'][0]['command'][0]['name']);
    }

    public function testRefusedDecklistReturns422WithProblems(): void
    {
        $this->useFixtureCatalog();
        [$status, $body] = $this->call('POST', self::BASE, ['seed' => 5, 'decks' => ['0' => "CMDR: Isamaru, Hound of Konda\n1 Rhystic Study\n1 Inconnue"]]);
        self::assertSame(422, $status);
        self::assertCount(2, $body['problems']['J0']);
    }
}
