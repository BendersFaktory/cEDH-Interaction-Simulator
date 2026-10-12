<?php

declare(strict_types=1);

/**
 * Smoke test sans composer : joue une partie complète à 3 IA et affiche un journal condensé.
 *   php bin/smoke.php [seed]
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
use App\Engine\Game\GameState;
use App\Engine\Snapshot\CheckpointListener;

$seed = (int) ($argv[1] ?? 42);

$state = GameFactory::create($seed, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
// Compte les checkpoints sans garder les états (InMemoryCheckpoints dépasse 128 Mo sur une longue partie).
$checkpoints = new class () implements CheckpointListener {
    public int $count = 0;

    public function onCheckpoint(GameState $state): void
    {
        $this->count++;
    }
};
$engine = new GameEngine(new AgentRegistry(), $checkpoints);

$result = $engine->advance($state);

$verbose = 0;
foreach ($result->events as $e) {
    if ($e->verbose) {
        $verbose++;
        continue;
    }
    if (in_array($e->type, ['CardDrawn', 'PhaseChanged', 'LandPlayed'], true)) {
        continue;
    }
    printf("#%-5d %-15s %s\n", $e->seq, $e->type, json_encode($e->payload['card']['name'] ?? $e->payload, JSON_UNESCAPED_UNICODE));
}

printf(
    "\nFin : over=%s winner=%s turns=%d events=%d (dont %d verbeux) checkpoints=%d\n",
    var_export($result->over, true),
    var_export($state->winner, true),
    $state->turn,
    $state->eventSeq,
    $verbose,
    $checkpoints->count,
);
foreach ($state->players as $p) {
    printf("  joueur %d : %d PV%s\n", $p->id, $p->life, $p->lost ? ' (éliminé)' : '');
}
