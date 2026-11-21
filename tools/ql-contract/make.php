<?php

// Writes one label PNG (as the editor draws it) and Labeler's raster job for each case of
// cases.json into the folder given as argument.

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../ptouch-sim/Png.php';

$dir = $argv[1] ?? '';
$cases = json_decode((string) file_get_contents(__DIR__ . '/cases.json'), true);
foreach ($cases as $i => $case) {
    [, , $across, $along] = Labeler\QlRaster::MEDIA[$case['media']];
    $length = $along > 0 ? $along : $case['length'];
    $rows = [];
    for ($y = 0; $y < $across; $y++) {
        $row = '';
        for ($x = 0; $x < $length; $x++) {
            // a frame, a diagonal and stripes: wrong orientation or offsets show up
            $edge = $x === 0 || $y === 0 || $x === $length - 1 || $y === $across - 1;
            $row .= $edge || $x === $y || ($x * 3 + $y) % 17 < 2 || ($x < 40 && $y < 20) ? '1' : '0';
        }
        $rows[] = $row;
    }
    $name = sprintf('%s/case-%02d', $dir, $i);
    file_put_contents($name . '.png', Labeler\Sim\Png::fromRows($rows));
    file_put_contents($name . '.bin', Labeler\QlRaster::job(Labeler\Png::rows((string) file_get_contents($name . '.png')), $case['media'], [
        'model' => $case['model'],
        'cut' => $case['cut'],
    ]));
}
echo count($cases) . " labels written\n";
