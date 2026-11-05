<?php

declare(strict_types=1);

namespace App\Service\Scryfall;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal de l'API Scryfall (POST /cards/collection), par lots de 75 noms max.
 * Respecte la politique de Scryfall : User-Agent + Accept explicites, 100 ms entre deux requêtes.
 * Les erreurs réseau / HTTP lèvent une exception (TransportExceptionInterface, HttpExceptionInterface) :
 * c'est CardCatalog qui décide du mode dégradé.
 */
class ScryfallClient
{
    public const BATCH_SIZE = 75;
    private const URL = 'https://api.scryfall.com/cards/collection';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        /** Pause entre deux requêtes, en microsecondes (100 ms ; 0 dans les tests). */
        private readonly int $delayMicroseconds = 100_000,
        /** Recette UI : répond depuis `FixtureCards` au lieu du réseau (variable d'environnement `SCRYFALL_FIXTURES`). */
        private readonly bool $useFixtures = false,
    ) {
    }

    /**
     * @param list<string> $names
     * @return array{cards: list<array<string, mixed>>, not_found: list<string>}
     */
    public function fetchCollection(array $names): array
    {
        $names = array_values(array_unique($names));
        if ($this->useFixtures) {
            return $this->fromFixtures($names);
        }
        $cards = [];
        $notFound = [];
        // /cards/collection ne connaît pas « Fire // Ice » (nom complet) mais accepte « Fire » : on demande la première face
        // des cartes à plusieurs faces et on rend les introuvables sous le nom demandé.
        $asked = [];
        foreach ($names as $name) {
            $asked[mb_strtolower(self::frontFace($name))] ??= $name;
        }

        foreach (array_chunk($names, self::BATCH_SIZE) as $i => $batch) {
            if ($i > 0 && $this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }

            $response = $this->httpClient->request('POST', self::URL, [
                'headers' => [
                    'User-Agent' => 'cEDH-Interaction-Simulator/0.1',
                    'Accept' => 'application/json',
                ],
                'json' => ['identifiers' => array_map(static fn (string $n): array => ['name' => self::frontFace($n)], $batch)],
            ]);
            $payload = $response->toArray();

            foreach ($payload['data'] ?? [] as $card) {
                $cards[] = $card;
            }
            foreach ($payload['not_found'] ?? [] as $identifier) {
                if (isset($identifier['name']) && is_string($identifier['name'])) {
                    $notFound[] = $asked[mb_strtolower($identifier['name'])] ?? $identifier['name'];
                }
            }
        }

        return ['cards' => $cards, 'not_found' => $notFound];
    }

    /** Première face d'une carte à plusieurs faces (« Fire // Ice » => « Fire »), le nom tel quel sinon. */
    private static function frontFace(string $name): string
    {
        return trim(explode(' // ', $name, 2)[0]);
    }

    /**
     * @param list<string> $names
     * @return array{cards: list<array<string, mixed>>, not_found: list<string>}
     */
    private function fromFixtures(array $names): array
    {
        $byName = [];
        foreach (FixtureCards::all() as $name => $card) {
            $full = mb_strtolower($name);
            $byName[$full] = $card;
            if (str_contains($full, ' // ')) {
                $byName[explode(' // ', $full)[0]] ??= $card;
            }
        }
        $cards = [];
        $notFound = [];
        foreach ($names as $name) {
            $card = $byName[mb_strtolower(trim($name))] ?? null;
            $card === null ? $notFound[] = $name : $cards[] = $card;
        }

        return ['cards' => $cards, 'not_found' => $notFound];
    }
}
