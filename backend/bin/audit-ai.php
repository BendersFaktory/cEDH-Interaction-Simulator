<?php

declare(strict_types=1);

/**
 * Audit de simulation sans composer : joue une partie à 4 IA par graine et compte les événements utiles.
 *   php backend/bin/audit-ai.php 42,6,16,122,7,99,150
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
use App\Engine\Snapshot\CheckpointListener;

/**
 * Joue une partie et renvoie les statistiques de la graine.
 *
 * @return array<string, int|string|bool|null>
 */
function auditSeed(int $seed): array
{
    $state = GameFactory::create($seed, [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']);
    $checkpoints = new class () implements CheckpointListener {
        public function onCheckpoint(App\Engine\Game\GameState $state): void
        {
        }
    };
    $engine = new GameEngine(new AgentRegistry(), $checkpoints);
    $result = $engine->advance($state);

    $stats = [
        'seed' => $seed,
        'turns' => $state->turn,
        'over' => $result->over,
        'winner' => $state->winner,
        'manaLost' => 0,
        'spellsCast' => 0,
        'countersCast' => 0,
        'countered' => 0,
        'attacks' => 0,
        'blocks' => 0,
        'doubleBlocks' => 0,
        'died' => 0,
        'commandersDied' => 0,
    ];

    $currentTurn = 0;
    $blockersPerAttacker = [];
    $trace = [];
    foreach ($result->events as $e) {
        $trace[] = [$e->type, $e->payload];
        $p = $e->payload;
        switch ($e->type) {
            case 'PhaseChanged':
                $currentTurn = (int) ($p['turn'] ?? $currentTurn);
                break;
            case 'ManaPoolEmptied':
                $stats['manaLost'] += array_sum(array_map('intval', $p['lost'] ?? []));
                break;
            case 'SpellCast':
                $stats['spellsCast']++;
                $card = $p['card'] ?? [];
                if (($card['effect'] ?? null) === 'counter' || ($card['name'] ?? null) === 'Counterspell') {
                    $stats['countersCast']++;
                }
                break;
            case 'SpellCountered':
                $stats['countered']++;
                break;
            case 'AttackerDeclared':
                $stats['attacks']++;
                break;
            case 'BlockerDeclared':
                $stats['blocks']++;
                $key = $currentTurn . ':' . $p['attacker'];
                $blockersPerAttacker[$key] = ($blockersPerAttacker[$key] ?? 0) + 1;
                break;
            case 'CreatureDied':
                $stats['died']++;
                if (($p['destination'] ?? null) === 'command') {
                    $stats['commandersDied']++;
                }
                break;
        }
    }

    foreach ($blockersPerAttacker as $count) {
        if ($count >= 2) {
            $stats['doubleBlocks']++;
        }
    }

    $stats['sha1'] = sha1((string) json_encode($trace, JSON_UNESCAPED_UNICODE));

    return $stats;
}

$seeds = array_map('intval', array_filter(array_map('trim', explode(',', $argv[1] ?? '42,6,16,122,7,99,150'))));

$columns = [
    ['seed', '%6s'], ['turns', '%6s'], ['over', '%-5s'], ['winner', '%7s'],
    ['manaLost', '%9s'], ['spellsCast', '%11s'], ['countersCast', '%13s'], ['countered', '%10s'],
    ['attacks', '%8s'], ['blocks', '%7s'], ['doubleBlocks', '%13s'], ['died', '%6s'],
    ['commandersDied', '%15s'], ['sha1', '%-40s'],
];

$header = '';
foreach ($columns as [$name, $fmt]) {
    $header .= sprintf($fmt . ' ', $name);
}
echo rtrim($header), "\n";

$totals = ['turns' => 0, 'manaLost' => 0, 'spellsCast' => 0, 'countersCast' => 0, 'countered' => 0,
    'attacks' => 0, 'blocks' => 0, 'doubleBlocks' => 0, 'died' => 0, 'commandersDied' => 0];

foreach ($seeds as $seed) {
    $s = auditSeed($seed);
    $cells = [
        'seed' => (string) $s['seed'],
        'turns' => (string) $s['turns'],
        'over' => var_export($s['over'], true),
        'winner' => var_export($s['winner'], true),
        'manaLost' => (string) $s['manaLost'],
        'spellsCast' => (string) $s['spellsCast'],
        'countersCast' => (string) $s['countersCast'],
        'countered' => (string) $s['countered'],
        'attacks' => (string) $s['attacks'],
        'blocks' => (string) $s['blocks'],
        'doubleBlocks' => (string) $s['doubleBlocks'],
        'died' => (string) $s['died'],
        'commandersDied' => (string) $s['commandersDied'],
        'sha1' => (string) $s['sha1'],
    ];
    $line = '';
    foreach ($columns as [$name, $fmt]) {
        $line .= sprintf($fmt . ' ', $cells[$name]);
    }
    echo rtrim($line), "\n";
    foreach ($totals as $k => $_) {
        $totals[$k] += (int) $s[$k];
    }
}

$cells = ['seed' => 'TOTAL', 'turns' => (string) $totals['turns'], 'over' => '', 'winner' => '',
    'manaLost' => (string) $totals['manaLost'], 'spellsCast' => (string) $totals['spellsCast'],
    'countersCast' => (string) $totals['countersCast'], 'countered' => (string) $totals['countered'],
    'attacks' => (string) $totals['attacks'], 'blocks' => (string) $totals['blocks'],
    'doubleBlocks' => (string) $totals['doubleBlocks'], 'died' => (string) $totals['died'],
    'commandersDied' => (string) $totals['commandersDied'], 'sha1' => ''];
$line = '';
foreach ($columns as [$name, $fmt]) {
    $line .= sprintf($fmt . ' ', $cells[$name]);
}
echo rtrim($line), "\n";
