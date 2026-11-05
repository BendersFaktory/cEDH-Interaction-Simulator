<?php

declare(strict_types=1);

namespace App\Service\Deck;

use App\Engine\Card\Decks;
use App\Service\Scryfall\CardCatalog;

/**
 * Decklist texte -> `LoadedDeck` jouable : noms résolus via `CardCatalog` (Scryfall + cache), chaque carte mappée par
 * `CardMapper`, règles Commander vérifiées par `Decks::validate()`. Tout problème est collecté dans une `DeckLoadException`.
 */
final class DeckLoader
{
    public function __construct(private readonly CardCatalog $catalog, private readonly CardMapper $mapper = new CardMapper())
    {
    }

    /**
     * @param ?string $commander nom du commandant s'il n'est pas dans la decklist (section « Commander » ou `CMDR:`) ; il
     *        remplace alors les commandants de la decklist (partenaire compris)
     * @param ?string $partner nom du second commandant (Partner), avec `$commander`
     * @throws DeckLoadException
     */
    public function load(string $decklist, ?string $commander = null, ?string $partner = null): LoadedDeck
    {
        $parsed = DecklistParser::parse($decklist);
        $given = $commander !== null && trim($commander) !== '';
        $commanderName = $given ? trim($commander) : $parsed['commander'];
        $partnerName = $given ? ($partner !== null && trim($partner) !== '' ? trim($partner) : null) : $parsed['partner'];
        if ($commanderName === null) {
            throw new DeckLoadException(['Aucun commandant indiqué (section « Commander » ou ligne « CMDR: nom »).']);
        }
        // Les commandants ne sont pas comptés dans la bibliothèque s'ils figurent aussi dans la liste principale.
        $entries = $parsed['entries'];
        $commanderNames = array_values(array_filter([$commanderName, $partnerName], static fn (?string $n): bool => $n !== null));
        foreach ($commanderNames as $name) {
            unset($entries[$name]);
        }

        $found = $this->catalog->get(array_merge($commanderNames, array_keys($entries)));
        $problems = [];
        foreach ($found['missing'] as $name) {
            $problems[] = $found['degraded']
                ? "« $name » : introuvable (Scryfall indisponible et absente du cache)."
                : "« $name » : carte inconnue de Scryfall.";
        }

        $definitions = [];
        $images = [];
        foreach ($found['cards'] as $requested => $entry) {
            try {
                $definitions[$requested] = $this->mapper->map($entry['data']);
                $images[$definitions[$requested]->name] = $entry['images'];
            } catch (UnsupportedCard $e) {
                $problems[] = $e->getMessage();
            }
        }
        if ($problems !== []) {
            throw new DeckLoadException($problems);
        }

        $library = [];
        foreach ($entries as $name => $quantity) {
            for ($i = 0; $i < $quantity; $i++) {
                $library[] = $definitions[$name];
            }
        }
        $deck = new LoadedDeck($definitions[$commanderName], $library, $images, $found['degraded'], $partnerName === null ? null : $definitions[$partnerName]);

        try {
            Decks::validate($deck->instantiate(0), 0);
        } catch (\InvalidArgumentException $e) {
            throw new DeckLoadException([preg_replace('/^Joueur \d+ : /', '', $e->getMessage())]);
        }

        return $deck;
    }
}
