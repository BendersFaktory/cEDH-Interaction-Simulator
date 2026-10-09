<?php

declare(strict_types=1);

/**
 * Génère une partie de référence pour le test de contrat du reducer front :
 *   php bin/export-fixture.php [seed] > ../frontend/src/engine/__fixtures__/game.json
 * Le test Vitest rejoue les événements et compare avec l'état final calculé ici par le moteur.
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
use App\Engine\Game\GameEngine;
use App\Engine\Game\GameFactory;

$seed = (int) ($argv[1] ?? 42);
$state = GameFactory::create($seed, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
$result = (new GameEngine(new AgentRegistry()))->advance($state);

$final = [
    'turn' => $state->turn,
    'winner' => $state->winner,
    'over' => $state->over,
    'players' => [],
];
foreach ($state->players as $p) {
    $final['players'][$p->id] = [
        'life' => $p->life,
        'lost' => $p->lost,
        'library' => count($p->library),
        'hand' => count($p->hand),
        'battlefield' => count($p->battlefield),
        'tappedLands' => count(array_filter($p->battlefield, static fn ($c) => $c->tapped)),
        'graveyard' => count($p->graveyard),
        'command' => count($p->command),
    ];
}

echo json_encode([
    'seed' => $seed,
    'events' => array_map(static fn ($e) => $e->toArray(), $result->events),
    'final' => $final,
], JSON_UNESCAPED_UNICODE), "\n";
