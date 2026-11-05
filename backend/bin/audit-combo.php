<?php

declare(strict_types=1);

/**
 * Banc de l'IA de combo (étape 16) : parties à 4 IA avec le deck Kraum / Tymna (`docs/decks/kraum-tymna.txt`, cartes figées
 * de `fixtures/scryfall/cards.json`, sans base ni réseau), et ce que deviennent les combos.
 *
 *   php bin/audit-combo.php [graines=1..30] [sièges=all|solo]
 *
 * `all` : le deck sur les quatre sièges ; `solo` : J0 seul, contre les decks de démo. Par graine : tours, gagnant, raison,
 * sorts de combo lancés / contrés, sha1 des événements (à comparer en double exécution : déterminisme). Totaux à la fin : taux
 * de victoire par combo (`GameOver` `won`, Thassa's Oracle), tour moyen de la victoire par combo, combos contrés.
 */

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use App\Engine\Agent\AgentRegistry;
use App\Engine\Card\Decks;
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;
use App\Engine\Snapshot\CheckpointListener;
use App\Service\Deck\CardMapper;
use App\Service\Deck\DecklistParser;
use App\Service\Deck\LoadedDeck;
use App\Service\Scryfall\FixtureCards;

/** Sorts suivis : nom => clé de statistique. */
const COMBO_SPELLS = [
    "Thassa's Oracle" => 'oracle',
    'Demonic Consultation' => 'consultation',
    'Tainted Pact' => 'pact',
    'Underworld Breach' => 'breach',
    'Brain Freeze' => 'freeze',
    "Lion's Eye Diamond" => 'led',
    'Ad Nauseam' => 'nauseam',
];

function kraumDeck(): LoadedDeck
{
    $parsed = DecklistParser::parse((string) file_get_contents(__DIR__ . '/../../docs/decks/kraum-tymna.txt'));
    $cards = FixtureCards::all();
    $mapper = new CardMapper();
    $def = static fn (string $name) => $mapper->map($cards[$name] ?? throw new RuntimeException("Carte absente des fixtures : $name"));
    $library = [];
    foreach ($parsed['entries'] as $name => $quantity) {
        if ($name !== $parsed['commander'] && $name !== $parsed['partner']) {
            for ($i = 0; $i < $quantity; $i++) {
                $library[] = $def($name);
            }
        }
    }

    return new LoadedDeck($def((string) $parsed['commander']), $library, [], false, $def((string) $parsed['partner']));
}

/** @return array<string, int|string|bool|null> */
function auditSeed(int $seed, LoadedDeck $deck, bool $solo): array
{
    $builder = static fn (int $id): array => $solo && $id !== 0 ? Decks::commanderDemo($id) : $deck->instantiate($id);
    $state = GameFactory::create($seed, array_fill(0, 4, 'ai:heuristic'), $builder);
    $engine = new GameEngine(new AgentRegistry(), new class () implements CheckpointListener {
        public function onCheckpoint(App\Engine\Game\GameState $state): void
        {
        }
    });
    $result = $engine->advance($state);

    $stats = ['seed' => $seed, 'turns' => $state->turn, 'over' => $result->over, 'winner' => $state->winner, 'reason' => null, 'decked' => 0]
        + array_fill_keys(array_values(COMBO_SPELLS), 0) + ['countered' => 0, 'escapes' => 0, 'via' => null, 'nauseamDeaths' => 0];
    $lastLine = []; // joueur => dernière ligne de combo lancée (consultation, pact, freeze)
    $names = []; // stackId => nom (sorts suivis seulement)
    $trace = [];
    foreach ($result->events as $e) {
        $trace[] = [$e->type, $e->payload];
        $p = $e->payload;
        switch ($e->type) {
            case 'SpellCast':
                $name = (string) ($p['card']['name'] ?? '');
                if (isset(COMBO_SPELLS[$name])) {
                    $stats[COMBO_SPELLS[$name]]++;
                    $names[(string) $p['stackId']] = $name;
                    if (in_array(COMBO_SPELLS[$name], ['consultation', 'pact', 'freeze'], true)) {
                        $lastLine[(int) $p['player']] = COMBO_SPELLS[$name] === 'freeze' ? 'breach' : COMBO_SPELLS[$name];
                    }
                }
                if (($p['altCast'] ?? null) === 'escape') {
                    $stats['escapes']++;
                }
                break;
            case 'SpellCountered':
                if (isset($names[(string) $p['stackId']])) {
                    $stats['countered']++;
                }
                break;
            case 'PlayerLost':
                if (($p['reason'] ?? null) === 'decked') {
                    $stats['decked']++;
                }
                break;
            case 'LifeLost':
                if (($p['source'] ?? null) === 'Ad Nauseam' && (int) ($p['life'] ?? 1) <= 0) {
                    $stats['nauseamDeaths']++;
                }
                break;
            case 'GameOver':
                $stats['reason'] = $p['reason'] ?? null;
                if ($stats['reason'] === 'won') {
                    $stats['via'] = $lastLine[(int) $p['winner']] ?? 'oracle';
                }
                break;
        }
    }
    $stats['sha1'] = sha1(json_encode($trace, JSON_THROW_ON_ERROR));

    return $stats;
}

$range = $argv[1] ?? '1..30';
$seeds = str_contains($range, '..') ? range(...array_map('intval', explode('..', $range, 2))) : array_map('intval', explode(',', $range));
$solo = ($argv[2] ?? 'all') === 'solo';
$deck = kraumDeck();

$keys = array_values(COMBO_SPELLS);
printf("%-6s %5s %4s %-20s %-12s %s %4s %4s %s\n", 'graine', 'tours', 'gagn', 'raison', 'ligne', implode(' ', array_map(static fn ($k) => sprintf('%5s', substr($k, 0, 5)), $keys)), 'ctré', 'esc', 'sha1');
$all = [];
foreach ($seeds as $seed) {
    $s = auditSeed($seed, $deck, $solo);
    $all[] = $s;
    printf("%-6d %5d %4s %-20s %-12s %s %4d %4d %s\n", $s['seed'], $s['turns'], $s['winner'] ?? '-', (string) $s['reason'], (string) $s['via'],
        implode(' ', array_map(static fn ($k) => sprintf('%5d', $s[$k]), $keys)), $s['countered'], $s['escapes'], $s['sha1']);
}

$n = count($all);
$comboWins = array_values(array_filter($all, static fn (array $s) => $s['reason'] === 'won'));
$sum = static fn (string $k) => array_sum(array_column($all, $k));
echo "\n";
printf("parties : %d, terminées : %d, limite de tours : %d\n", $n, count(array_filter($all, static fn ($s) => $s['over'])), count(array_filter($all, static fn ($s) => $s['reason'] === 'turn_limit')));
printf("victoires par combo (Oracle) : %d / %d (%.0f %%), tour moyen : %s ; joueurs perdus par bibliothèque vide : %d\n", count($comboWins), $n,
    $n === 0 ? 0 : 100 * count($comboWins) / $n, $comboWins === [] ? '-' : number_format(array_sum(array_column($comboWins, 'turns')) / count($comboWins), 1), $sum('decked'));
$via = array_count_values(array_map('strval', array_column($comboWins, 'via')));
ksort($via);
printf("lignes gagnantes : %s ; morts par Ad Nauseam : %d\n", $via === [] ? '-' : implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($via), $via)), $sum('nauseamDeaths'));
printf("sorts de combo lancés : %s ; contrés : %d ; escapes : %d\n", implode(', ', array_map(static fn ($k) => "$k " . $sum($k), $keys)), $sum('countered'), $sum('escapes'));
