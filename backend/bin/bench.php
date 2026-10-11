<?php

declare(strict_types=1);

/**
 * Banc moteur : mesure la vitesse du moteur sur des parties à 4 IA et affiche une empreinte
 * (sha1) du flux d'événements de chaque partie.
 *   php bin/bench.php [seeds...]      seeds entiers ou intervalles, ex. 42 6 16 ou 1..20
 *
 * L'empreinte sert à vérifier qu'une optimisation ne change aucun événement : lancer le banc
 * avant et après la modification, puis comparer les sha1 (ils doivent être identiques).
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

// Arguments : entiers ou intervalles « a..b » ; par défaut 42 6 16.
$args = array_slice($argv, 1);
if ($args === []) {
    $args = ['42', '6', '16'];
}
$seeds = [];
foreach ($args as $arg) {
    if (preg_match('/^(-?\d+)\.\.(-?\d+)$/', $arg, $m) === 1) {
        $from = (int) $m[1];
        $to = (int) $m[2];
        if ($from > $to) {
            fwrite(STDERR, "Intervalle invalide : $arg\n");
            exit(1);
        }
        for ($s = $from; $s <= $to; $s++) {
            $seeds[] = $s;
        }
    } elseif (preg_match('/^-?\d+$/', $arg) === 1) {
        $seeds[] = (int) $arg;
    } else {
        fwrite(STDERR, "Seed invalide : $arg (attendu un entier ou un intervalle a..b)\n");
        exit(1);
    }
}

// Compte les checkpoints sans garder les états (même écouteur que smoke.php).
$checkpoints = new class () implements CheckpointListener {
    public int $count = 0;

    public function onCheckpoint(GameState $state): void
    {
        $this->count++;
    }
};

printf("Banc moteur (4 IA) : %d partie(s)\n", count($seeds));
printf("%-8s %6s %8s %12s %10s %-5s %s\n", 'seed', 'tours', 'events', 'durée (ms)', 'µs/event', 'over', 'sha1');

$totalNs = 0;
foreach ($seeds as $seed) {
    // Chronométrage : création de la partie + avancement jusqu'à la fin (ou la limite du moteur).
    $start = hrtime(true);
    $state = GameFactory::create($seed, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
    $engine = new GameEngine(new AgentRegistry(), $checkpoints);
    $result = $engine->advance($state);
    $durationNs = hrtime(true) - $start;
    $totalNs += $durationNs;

    // Empreinte incrémentale du flux d'événements (type + payload JSON, une ligne par événement).
    $hash = hash_init('sha1');
    foreach ($result->events as $e) {
        hash_update($hash, $e->type . json_encode($e->payload, JSON_UNESCAPED_UNICODE) . "\n");
    }
    $sha1 = hash_final($hash);

    $events = $state->eventSeq;
    $durationMs = $durationNs / 1e6;
    $usPerEvent = $events > 0 ? ($durationNs / 1e3) / $events : 0.0;

    printf(
        "%-8d %6d %8d %12.1f %10.1f %-5s %s\n",
        $seed,
        $state->turn,
        $events,
        $durationMs,
        $usPerEvent,
        $result->over ? 'oui' : 'non',
        $sha1,
    );

    // Libère la mémoire avant la partie suivante.
    unset($result, $state, $engine, $hash);
}

printf("Durée totale : %.1f ms\n", $totalNs / 1e6);
