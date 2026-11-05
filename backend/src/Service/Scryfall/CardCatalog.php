<?php

declare(strict_types=1);

namespace App\Service\Scryfall;

use App\Entity\CardCache;
use App\Repository\CardCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Catalogue de cartes : cache en base (24 h) devant Scryfall.
 * Scryfall indisponible : on sert le cache même périmé et on signale les manquants, sans exception.
 */
class CardCatalog
{
    public const TTL_SECONDS = 86400;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ScryfallClient $client,
        private readonly CardCacheRepository $repository,
        private readonly EntityManagerInterface $em,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * @param list<string> $names
     * @return array{
     *     cards: array<string, array{data: array<string, mixed>, images: array{small: ?string, normal: ?string}, fetchedAt: \DateTimeImmutable, stale: bool}>,
     *     missing: list<string>,
     *     degraded: bool
     * } cards indexé par le nom demandé ; missing = introuvables ou non récupérables
     */
    public function get(array $names): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $limit = $now->modify('-' . self::TTL_SECONDS . ' seconds');

        /** @var array<string, string> $requested normalisé => nom demandé */
        $requested = [];
        foreach ($names as $name) {
            $requested[CardCache::normalizeName($name)] ??= $name;
        }

        $cached = $this->repository->findByNames(array_keys($requested));
        $toFetch = [];
        foreach ($requested as $key => $name) {
            if (!isset($cached[$key]) || $cached[$key]->getFetchedAt() < $limit) {
                $toFetch[$key] = $name;
            }
        }

        $notFound = [];
        $degraded = false;
        if ($toFetch !== []) {
            try {
                $result = $this->client->fetchCollection(array_values($toFetch));
                $this->store($toFetch, $result['cards'], $cached, $now);
                foreach ($result['not_found'] as $name) {
                    $notFound[CardCache::normalizeName($name)] = true;
                }
                $this->em->flush();
            } catch (\Throwable) {
                $degraded = true;
            }
        }

        $cards = [];
        $missing = [];
        foreach ($requested as $key => $name) {
            $entry = $cached[$key] ?? null;
            if ($entry === null || isset($notFound[$key])) {
                $missing[] = $name;
                continue;
            }
            $cards[$name] = [
                'data' => $entry->getData(),
                'images' => ['small' => $entry->getImageSmall(), 'normal' => $entry->getImageNormal()],
                'fetchedAt' => $entry->getFetchedAt(),
                'stale' => $entry->getFetchedAt() < $limit,
            ];
        }

        return ['cards' => $cards, 'missing' => $missing, 'degraded' => $degraded];
    }

    /**
     * @param array<string, string> $toFetch
     * @param list<array<string, mixed>> $fetched
     * @param array<string, CardCache> $cached mis à jour par référence
     */
    private function store(array $toFetch, array $fetched, array &$cached, \DateTimeImmutable $now): void
    {
        // Une carte à deux faces s'appelle « A // B » mais se demande aussi par « A ».
        $byName = [];
        foreach ($fetched as $card) {
            $full = CardCache::normalizeName((string) ($card['name'] ?? ''));
            $byName[$full] = $card;
            if (str_contains($full, ' // ')) {
                $byName[explode(' // ', $full)[0]] ??= $card;
            }
        }

        foreach (array_keys($toFetch) as $key) {
            if (!isset($byName[$key])) {
                continue;
            }
            $card = $byName[$key];
            [$small, $normal] = self::imageUrls($card);
            if (isset($cached[$key])) {
                $cached[$key]->refresh($card, $small, $normal, $now);
            } else {
                $cached[$key] = new CardCache($key, $card, $small, $normal, $now);
                $this->em->persist($cached[$key]);
            }
        }
    }

    /**
     * @param array<string, mixed> $card
     * @return array{0: ?string, 1: ?string}
     */
    private static function imageUrls(array $card): array
    {
        $uris = $card['image_uris'] ?? $card['card_faces'][0]['image_uris'] ?? [];

        return [
            is_string($uris['small'] ?? null) ? $uris['small'] : null,
            is_string($uris['normal'] ?? null) ? $uris['normal'] : null,
        ];
    }
}
