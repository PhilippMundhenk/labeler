<?php

// Contract checks that need PHP: parse real ptouch-print output, compare PNG pixels.
//   php tools/contract/check.php version "<output of --version>"
//   php tools/contract/check.php info <exit code> "<output of --info>"
//   php tools/contract/check.php same <a.png> <b.png>

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../ptouch-sim/Png.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

switch ($argv[1] ?? '') {
    case 'version':
        if (!preg_match('/(\d+)\.(\d+)/', $argv[2], $m)) {
            fail("no version in '{$argv[2]}'");
        }
        echo "version {$m[1]}.{$m[2]}\n";
        break;
    case 'info':
        $info = Labeler\PtouchPrint::parseInfo($argv[3], (int) $argv[2]);
        if ($info['ready'] || $info['connected'] || strpos($info['message'], 'No printer found') !== 0) {
            fail('unexpected parse of a missing printer: ' . json_encode($info));
        }
        echo "info without printer: {$info['message']}\n";
        break;
    case 'same':
        $a = Labeler\Sim\Png::read($argv[2]);
        $b = Labeler\Sim\Png::read($argv[3]);
        if ($a === null || $b === null || !$b['palette']) {
            fail('cannot read the images');
        }
        if ($a['rows'] !== $b['rows']) {
            fail("{$argv[3]} differs from {$argv[2]}");
        }
        echo "same pixels: {$a['width']} x {$a['height']}, {$a['black']} black\n";
        break;
    default:
        fail('unknown check');
}
