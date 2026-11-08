<?php

// Fails when line coverage in a Clover report is below the threshold, overall and per file.
// usage: php tools/coverage-gate.php coverage/php/clover.xml 95

declare(strict_types=1);

[$self, $file, $min] = $argv + [null, 'coverage/php/clover.xml', '95'];
$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "cannot read $file\n");
    exit(2);
}
$failed = false;
$total = [0, 0];
foreach ($xml->xpath('//file') as $f) {
    $m = $f->metrics;
    $lines = (int) $m['statements'];
    $covered = (int) $m['coveredstatements'];
    $total[0] += $covered;
    $total[1] += $lines;
    $pct = $lines ? 100 * $covered / $lines : 100;
    $name = preg_replace('#^.*?/(src|tools)/#', '$1/', (string) $f['name']);
    printf("%6.1f %%  %s\n", $pct, $name);
    if ($pct < (float) $min) {
        $failed = true;
    }
}
$pct = $total[1] ? 100 * $total[0] / $total[1] : 100;
printf("%6.1f %%  total (gate: %s %% per file)\n", $pct, $min);
exit($failed ? 1 : 0);
