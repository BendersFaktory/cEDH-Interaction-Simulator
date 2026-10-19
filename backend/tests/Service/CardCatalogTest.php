<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CardCache;
use App\Repository\CardCacheRepository;
use App\Service\Scryfall\CardCatalog;
use App\Service\Scryfall\ScryfallClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Client Scryfall + cache en base, sans aucun appel réseau (MockHttpClient). */
final class CardCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->requests = [];
    }

    private static function card(string $name): array
    {
        return [
            'name' => $name,
            'image_uris' => ['small' => "https://img/small/$name.jpg", 'normal' => "https://img/normal/$name.jpg"],
        ];
    }

    /** Scryfall simulé : connaît tout nom sauf ceux commençant par « Ghost ». */
    private function catalog(?\Closure $handler = null): CardCatalog
    {
        $handler ??= function (array $names): MockResponse {
            $data = [];
            $notFound = [];
            foreach ($names as $n) {
                str_starts_with($n, 'Ghost') ? $notFound[] = ['name' => $n] : $data[] = self::card($n);
            }

            return new MockResponse(json_encode(['data' => $data, 'not_found' => $notFound]), ['response_headers' => ['content-type: application/json']]);
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($handler): MockResponse {
            $body = json_decode($options['body'], true);
            $names = array_map(static fn (array $i): string => $i['name'], $body['identifiers']);
            $headers = $options['normalized_headers'] ?? [];
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options, 'names' => $names, 'headers' => $headers];

            return $handler($names);
        });

        return new CardCatalog(new ScryfallClient($http, 0), static::getContainer()->get(CardCacheRepository::class), $this->em);
    }

    public function testBatchesOf75AndHeaders(): void
    {
        $names = [];
        for ($i = 1; $i <= 160; $i++) {
            $names[] = "Card $i";
        }
        $result = $this->catalog()->get($names);

        self::assertCount(160, $result['cards']);
        self::assertSame([], $result['missing']);
        self::assertFalse($result['degraded']);
        self::assertCount(3, $this->requests);
        self::assertSame([75, 75, 10], array_map(static fn (array $r): int => count($r['names']), $this->requests));
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.scryfall.com/cards/collection', $this->requests[0]['url']);
        self::assertSame(['User-Agent: cEDH-Interaction-Simulator/0.1'], $this->requests[0]['headers']['user-agent']);
        self::assertSame(['Accept: application/json'], $this->requests[0]['headers']['accept']);
        self::assertSame('https://img/small/Card 1.jpg', $result['cards']['Card 1']['images']['small']);
    }

    public function testFreshCacheIsNotQueriedAgain(): void
    {
        $catalog = $this->catalog();
        $catalog->get(['Sol Ring']);
        self::assertCount(1, $this->requests);

        $result = $catalog->get(['sol  ring', 'Mana Crypt']);
        self::assertCount(2, $this->requests);
        self::assertSame(['Mana Crypt'], $this->requests[1]['names']);
        self::assertCount(2, $result['cards']);
        self::assertFalse($result['cards']['sol  ring']['stale']);

        $catalog->get(['Sol Ring', 'Mana Crypt']);
        self::assertCount(2, $this->requests);
    }

    public function testStaleCacheIsRefreshed(): void
    {
        $old = new \DateTimeImmutable('-25 hours');
        $this->em->persist(new CardCache('sol ring', ['name' => 'Sol Ring', 'old' => true], null, null, $old));
        $this->em->flush();

        $result = $this->catalog()->get(['Sol Ring']);

        self::assertCount(1, $this->requests);
        self::assertArrayNotHasKey('old', $result['cards']['Sol Ring']['data']);
        self::assertFalse($result['cards']['Sol Ring']['stale']);
        self::assertGreaterThan($old, $result['cards']['Sol Ring']['fetchedAt']);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(CardCache::class)->findAll());
    }

    public function testDegradedModeServesStaleCache(): void
    {
        $this->em->persist(new CardCache('sol ring', ['name' => 'Sol Ring', 'old' => true], 's', 'n', new \DateTimeImmutable('-3 days')));
        $this->em->flush();

        $down = static fn (array $names): MockResponse => new MockResponse('', ['error' => 'connexion refusée']);
        $result = $this->catalog($down)->get(['Sol Ring', 'Mana Crypt']);

        self::assertTrue($result['degraded']);
        self::assertTrue($result['cards']['Sol Ring']['stale']);
        self::assertTrue($result['cards']['Sol Ring']['data']['old']);
        self::assertSame(['Mana Crypt'], $result['missing']);
    }

    public function testHttpErrorIsAlsoDegraded(): void
    {
        $err = static fn (array $names): MockResponse => new MockResponse('{}', ['http_code' => 503]);
        $result = $this->catalog($err)->get(['Sol Ring']);

        self::assertTrue($result['degraded']);
        self::assertSame(['Sol Ring'], $result['missing']);
        self::assertSame([], $result['cards']);
    }

    public function testNotFoundIsReportedAndNotCached(): void
    {
        $result = $this->catalog()->get(['Sol Ring', 'Ghost Card']);

        self::assertSame(['Ghost Card'], $result['missing']);
        self::assertSame(['Sol Ring'], array_keys($result['cards']));
        self::assertFalse($result['degraded']);
        self::assertCount(1, $this->em->getRepository(CardCache::class)->findAll());
    }

    public function testClientReturnsNotFoundNames(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'data' => [self::card('Sol Ring')],
            'not_found' => [['name' => 'Ghost Card']],
        ]), ['response_headers' => ['content-type: application/json']]));

        $result = (new ScryfallClient($http, 0))->fetchCollection(['Sol Ring', 'Ghost Card']);

        self::assertSame(['Sol Ring'], array_column($result['cards'], 'name'));
        self::assertSame(['Ghost Card'], $result['not_found']);
    }

    public function testDoubleFacedCardAndFaceImages(): void
    {
        $dfc = ['name' => 'Delver of Secrets // Insectile Aberration', 'card_faces' => [
            ['image_uris' => ['small' => 'https://img/s.jpg', 'normal' => 'https://img/n.jpg']],
            ['image_uris' => ['small' => 'https://img/s2.jpg', 'normal' => 'https://img/n2.jpg']],
        ]];
        $h = static fn (array $names): MockResponse => new MockResponse(json_encode(['data' => [$dfc], 'not_found' => []]), ['response_headers' => ['content-type: application/json']]);

        $result = $this->catalog($h)->get(['Delver of Secrets']);

        self::assertSame([], $result['missing']);
        self::assertSame('https://img/s.jpg', $result['cards']['Delver of Secrets']['images']['small']);
    }
}
