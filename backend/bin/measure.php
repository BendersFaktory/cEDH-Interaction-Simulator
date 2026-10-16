<?php

declare(strict_types=1);

/**
 * Mesure taille en base et latence (création + partie jouée, fork, reconstruction d'état) sur des parties 4 IA.
 *   APP_DEBUG=0 php -d memory_limit=512M bin/measure.php [seed...]      (MySQL doit tourner)
 * Les parties créées sont supprimées à la fin (cascade sur événements et checkpoints).
 */

use App\Kernel;
use App\Service\BulkInserter;
use App\Service\SimulationService;
use App\Service\StateReconstructor;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
$em = $doctrine->getManager();
$conn = $doctrine->getConnection();
$reconstructor = new StateReconstructor($em);
// Bus inerte : la partie est jouée juste après par start(), comme le ferait le worker Messenger.
$bus = new class implements MessageBusInterface {
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message, $stamps);
    }
};
$service = new SimulationService($em, new BulkInserter($conn), $reconstructor, $bus, (int) $kernel->getContainer()->getParameter('app.checkpoint_every_n_turns'));

const REPEATS = 5;
$seeds = array_map('intval', array_slice($argv, 1)) ?: [42, 6, 16];
$ai = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

function median(array $xs): float
{
    sort($xs);

    return $xs[intdiv(count($xs), 2)];
}

/** @return array{0:mixed,1:float} résultat et durée en ms */
function timed(callable $f): array
{
    $t = hrtime(true);
    $r = $f();

    return [$r, (hrtime(true) - $t) / 1e6];
}

printf("APP_DEBUG=%s, N=%d tour(s) par ancre, médiane de %d\n", $_SERVER['APP_DEBUG'], (int) $kernel->getContainer()->getParameter('app.checkpoint_every_n_turns'), REPEATS);

$created = [];
foreach ($seeds as $seed) {
    echo "\n== seed $seed ==\n";

    $creates = [];
    $sim = null;
    for ($i = 0; $i < REPEATS; $i++) {
        [$sim, $ms] = timed(fn () => $service->start($service->create($seed, $ai)));
        $creates[] = $ms;
        $created[] = $sim->getId();
        $em->clear();
    }
    $id = $sim->getId();

    $events = (int) $conn->fetchOne('SELECT COUNT(*) FROM game_event WHERE simulation_id = ?', [$id]);
    $verbose = (int) $conn->fetchOne('SELECT COUNT(*) FROM game_event WHERE simulation_id = ? AND verbose = 1', [$id]);
    $anchors = (int) $conn->fetchOne('SELECT COUNT(*) FROM checkpoint WHERE simulation_id = ?', [$id]);
    $evBytes = (int) $conn->fetchOne('SELECT COALESCE(SUM(LENGTH(payload)),0) FROM game_event WHERE simulation_id = ?', [$id]);
    $cpBytes = (int) $conn->fetchOne('SELECT COALESCE(SUM(LENGTH(state)),0) FROM checkpoint WHERE simulation_id = ?', [$id]);
    $lastTurn = (int) $conn->fetchOne('SELECT MAX(JSON_EXTRACT(state, "$.turn")) FROM checkpoint WHERE simulation_id = ?', [$id]);

    printf("create : médiane %.0f ms (min %.0f, max %.0f, 1re = %.0f)\n", median($creates), min($creates), max($creates), $creates[0]);
    printf("événements : %d (dont %d verbeux), ancres : %d, dernier tour ancré : %d\n", $events, $verbose, $anchors, $lastTurn);
    printf("taille : événements %.2f Mo, ancres %.2f Mo\n", $evBytes / 1e6, $cpBytes / 1e6);
    printf("pic mémoire PHP : %.0f Mo\n", memory_get_peak_usage(true) / 1e6);

    // Cibles : début, milieu, fin, et pire cas de rejeu (juste avant la prochaine ancre = fin d'un tour).
    $anchorSeqs = array_map('intval', $conn->fetchFirstColumn('SELECT event_seq FROM checkpoint WHERE simulation_id = ? ORDER BY event_seq', [$id]));
    $targets = ['début' => $anchorSeqs[1] ?? 1, 'milieu' => intdiv($events, 2), 'fin' => $events];
    $worst = 0;
    for ($i = 1; $i < count($anchorSeqs); $i++) {
        $gap = $anchorSeqs[$i] - $anchorSeqs[$i - 1];
        if ($gap > $worst) {
            $worst = $gap;
            $targets['pire écart (juste avant ancre)'] = $anchorSeqs[$i] - 1;
        }
    }
    printf("plus grand écart entre ancres : %d événements\n", $worst);

    foreach ($targets as $label => $seq) {
        $cold = $warm = [];
        for ($i = 0; $i < REPEATS; $i++) {
            $sim = $em->find(App\Entity\Simulation::class, $id);
            [, $ms] = timed(fn () => $service->stateAt($sim, $seq));
            $i === 0 ? $cold[] = $ms : $warm[] = $ms;
            $em->clear();
        }
        printf("state@%-30s seq=%-6d 1re %.0f ms, médiane suivantes %.0f ms\n", $label, $seq, $cold[0], median($warm));
    }

    $forks = [];
    for ($i = 0; $i < REPEATS; $i++) {
        $sim = $em->find(App\Entity\Simulation::class, $id);
        [$child, $ms] = timed(fn () => $service->fork($sim, intdiv($events, 2), [0 => 'human:auto']));
        $forks[] = $ms;
        $created[] = $child->getId();
        $em->clear();
    }
    printf("fork @milieu (human:auto sur J0) : médiane %.0f ms (min %.0f, max %.0f)\n", median($forks), min($forks), max($forks));
}

// Nettoyage : forks puis parents (SET NULL sur parent, CASCADE sur lignes).
$conn->executeStatement('DELETE FROM simulation WHERE id IN (' . implode(',', array_map('intval', $created)) . ')');
echo "\n" . count($created) . " parties de mesure supprimées.\n";
