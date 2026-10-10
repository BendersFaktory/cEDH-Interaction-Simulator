<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Snapshot\InMemoryCheckpoints;
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
        self::assertSame(201, $status);

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
        self::assertSame(3294, $sim['eventCount'], 'Même partie que bin/smoke.php 42');

        $events = $this->events($sim['id']);
        self::assertCount(3294, $events);
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
        $sim = $this->createGame(7);
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
}
