<?php

declare(strict_types=1);

// Vérifie le taux de couverture (instructions) d'un rapport clover PHPUnit contre un seuil.
// Usage : php bin/check-coverage.php var/clover.xml 70 [fragment de chemin, ex. src/Engine/]
// Sans fragment : tout src/. Code de sortie 1 sous le seuil.

$file = $argv[1] ?? null;
$min = $argv[2] ?? null;
$prefix = $argv[3] ?? '';
if ($file === null || $min === null || !is_file($file)) {
    fwrite(STDERR, "usage: php bin/check-coverage.php <clover.xml> <seuil %> [fragment de chemin]\n");
    exit(2);
}
$min = (float) $min;

$xml = simplexml_load_file($file);
$total = 0;
$covered = 0;
foreach ($xml->xpath('//file') as $f) {
    $path = str_replace('\\', '/', (string) $f['name']);
    if ($prefix !== '' && !str_contains($path, '/'.ltrim($prefix, '/'))) {
        continue;
    }
    $m = $f->metrics;
    $total += (int) $m['statements'];
    $covered += (int) $m['coveredstatements'];
}
if ($total === 0) {
    fwrite(STDERR, "aucune instruction trouvée dans le rapport\n");
    exit(2);
}
$rate = 100 * $covered / $total;
printf("Couverture%s : %.2f %% (%d/%d), seuil %.2f %%\n", $prefix !== '' ? " de $prefix" : '', $rate, $covered, $total, $min);
exit($rate + 1e-9 >= $min ? 0 : 1);
