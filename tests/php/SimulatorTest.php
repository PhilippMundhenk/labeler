<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Sim\Png;
use Labeler\Sim\Simulator;

/**
 * The simulator itself: it is the reference the other tests rely on, so it is tested too.
 */
final class SimulatorTest extends TestCase
{
    private function sim(array $args, array $state = []): array
    {
        return (new Simulator($state + ['out' => $this->tmp . '/out']))->run($args);
    }

    public function testVersionStrings(): void
    {
        $this->assertSame("ptouch-print version v1.5 by Dominic Radermacher\n", $this->sim(['--version'], ['version' => '1.5'])['stdout']);
        $this->assertSame("ptouch-print v1.9.r0.g0000000\n", $this->sim(['--version'])['stdout']);
    }

    public function testArgpRejectsWhatTheRealToolRejects(): void
    {
        $r = $this->sim(['--text', 'line 1', 'line 2']);
        $this->assertSame(64, $r['code'], 'the 1.7+ command line has no positional arguments (issue #1)');
        $this->assertStringContainsString('No arguments supported', $r['stderr']);
        $this->assertStringContainsString("unrecognized option '--fly'", $this->sim(['--fly'])['stderr']);
        $this->assertStringContainsString("unrecognized option '--precut'", $this->sim(['--precut'], ['version' => '1.7'])['stderr']);
        $this->assertStringContainsString('requires an argument', $this->sim(['--image'])['stderr']);
        $this->assertSame(1, $this->sim(['--force-tape-width', '76'])['code']);
    }

    public function testLegacyCommandLine(): void
    {
        $state = ['version' => '1.5'];
        $this->assertSame(0, $this->sim(['--text', 'line 1', 'line 2', '--cutmark'], $state)['code'], '1.5 takes several lines after --text');
        $this->assertCount(1, glob($this->tmp . '/out/*.json') ?: []);
        $this->assertSame(1, $this->sim(['--chain'], $state)['code']);
        $this->assertSame(1, $this->sim(['--copies', '2', '--image', 'x'], $state)['code']);
        $this->assertSame(1, $this->sim(['--image'], $state)['code']);
        $this->assertSame(1, $this->sim(['stray'], $state)['code']);
        $this->assertStringContainsString('usage', $this->sim(['--newline', 'x'], $state)['stdout']);
        $this->assertSame(0, $this->sim(['--text'], $state)['code'], 'no lines, nothing to do');
        $this->assertSame(0, $this->sim(['--chain', '--copies', '2', '--text', 'x'], ['version' => '1.6'])['code']);
    }

    public function testListCommands(): void
    {
        $this->assertStringContainsString('PT-P700', $this->sim(['--list-supported'])['stdout']);
        $this->assertSame("PT-P700 serial 000F1Z401234\n", $this->sim(['--list-connected'])['stdout']);
        $this->assertSame(5, $this->sim(['--list-connected'], ['printer' => 'none'])['code']);
    }

    public function testPrintsTextPadCutmarkAndImages(): void
    {
        file_put_contents($this->tmp . '/a.png', self::labelPng(16, 76));
        $r = $this->sim(['--text', 'one', '--newline', 'two', '--newline=three', '--pad', '10', '--cutmark', '--image', $this->tmp . '/a.png', '--font', 'x', '--debug']);
        $this->assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        $record = json_decode((string) file_get_contents($this->tmp . '/out/label-0001.json'), true);
        $this->assertSame([['text' => ['one', 'two', 'three']], ['pad' => 10], ['cutmark' => true], ['image' => '16x76']], $record['parts']);
        $this->assertSame(16 * 76 / 2, $record['black_px']);
        $this->assertFileExists($this->tmp . '/out/label-0001.png');
        $r = $this->sim(['--newline', 'alone', '--newline', 'b', '--newline', 'c', '--newline', 'd', '--newline', 'e']);
        $this->assertSame(0, $r['code']);
        $record = json_decode((string) file_get_contents($this->tmp . '/out/label-0002.json'), true);
        $this->assertSame([['text' => ['alone', 'b', 'c', 'd']], ['text' => ['e']]], $record['parts']);
    }

    public function testNothingToPrintAndNoOutputFolder(): void
    {
        $this->assertSame(0, $this->sim([])['code']);
        file_put_contents($this->tmp . '/a.png', self::labelPng(8, 8));
        $r = (new Simulator(['out' => '']))->run(['--image', $this->tmp . '/a.png']);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('image size (8px x 8px)', $r['stdout']);
    }

    public function testImageErrors(): void
    {
        $this->assertSame("failed to load image file\n", $this->sim(['--image', $this->tmp . '/missing.png'])['stdout']);
        file_put_contents($this->tmp . '/tall.png', self::labelPng(8, 100));
        $r = $this->sim(['--image', $this->tmp . '/tall.png']);
        $this->assertSame(0, $r['code'], 'the real tool ignores the error here');
        $this->assertStringContainsString('image is too large (8px x 100px)', $r['stdout']);
    }

    public function testWritePngWithoutPrinter(): void
    {
        file_put_contents($this->tmp . '/a.png', self::labelPng(36, 76));
        $r = $this->sim(['--force-tape-width', '76', '--writepng', $this->tmp . '/o.png', '--image', $this->tmp . '/a.png'], ['printer' => 'none']);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('requires 5.1 mm of tape', $r['stdout']);
        $this->assertSame(file_get_contents($this->tmp . '/a.png'), file_get_contents($this->tmp . '/o.png'));
        $this->assertSame(0, $this->sim(['--write-png', $this->tmp . '/t.png', '--text', 'hi'])['code']);
        $this->assertSame(76, Png::read($this->tmp . '/t.png')['height']);
        $bad = $this->sim(['--writepng', $this->tmp . '/no/such/dir/x.png', '--text', 'hi']);
        $this->assertSame(1, $bad['code']);
        $this->assertStringContainsString('failed', $bad['stdout']);
    }

    public function testPrinterStates(): void
    {
        $this->assertStringContainsString('No P-Touch printer with serial X found', $this->sim(['--serial', 'X', '--info'])['stderr']);
        $this->assertSame(5, $this->sim(['--info'], ['mode' => 'access'])['code']);
        $this->assertStringContainsString('P-Lite', $this->sim(['--info'], ['mode' => 'plite'])['stdout']);
        $this->assertStringContainsString('unsupported', $this->sim(['--info'], ['mode' => 'unsupported'])['stdout']);
        $this->assertStringContainsString('tape is 0px', $this->sim(['--info'], ['tape_mm' => 0])['stdout']);
        $r = $this->sim(['--info'], ['version' => '1.8', 'tape_color' => 0x99, 'text_color' => 0x99]);
        $this->assertStringContainsString('tape color = 0x99 (unknown)', $r['stdout']);
        $this->assertStringContainsString('maximum printing width for this printer is 128px', $r['stdout']);
        $this->assertStringContainsString('media type = 01 (Laminated tape)', $this->sim(['--info'], ['version' => '1.6'])['stdout']);
    }

    public function testFailureAndDelay(): void
    {
        file_put_contents($this->tmp . '/a.png', self::labelPng(8, 8));
        $r = $this->sim(['--image', $this->tmp . '/a.png'], ['fail' => 'print']);
        $this->assertSame(2, $r['code']);
        $started = microtime(true);
        $this->sim(['--copies', '3', '--chain', '--precut', '--image', $this->tmp . '/a.png'], ['delay_ms' => 50]);
        $this->assertGreaterThan(0.04, microtime(true) - $started);
        $records = glob($this->tmp . '/out/*.json') ?: [];
        $this->assertCount(3, $records);
    }

    public function testJamReportsSuccessThenAnError(): void
    {
        file_put_contents($this->tmp . '/a.png', self::labelPng(8, 8));
        file_put_contents($this->tmp . '/state.json', '{"fail": "jam", "tape_mm": 12}');
        $sim = new Simulator(['out' => $this->tmp . '/out', 'fail' => 'jam'], $this->tmp . '/state.json');
        $this->assertSame(0, $sim->run(['--image', $this->tmp . '/a.png'])['code']);
        $this->assertEquals(['error' => 4, 'fail' => '', 'tape_mm' => 12], json_decode((string) file_get_contents($this->tmp . '/state.json'), true));
        file_put_contents($this->tmp . '/state.json', 'broken');
        (new Simulator(['fail' => 'jam'], $this->tmp . '/state.json'))->run(['--image', $this->tmp . '/a.png']);
        $this->assertSame(['error' => 4, 'fail' => ''], json_decode((string) file_get_contents($this->tmp . '/state.json'), true));
        $this->assertSame(0, (new Simulator(['fail' => 'jam']))->run(['--image', $this->tmp . '/a.png'])['code'], 'without a state file nothing is written');
    }

    public function testStateFromEnvironmentAndFile(): void
    {
        file_put_contents($this->tmp . '/state.json', '{"tape_mm": "24", "printer": "PT-P750W"}');
        $state = Simulator::stateFromEnv(['PTSIM_VERSION' => '1.6', 'PTSIM_TAPE_MM' => '9', 'PTSIM_STATE' => $this->tmp . '/state.json', 'PTSIM_MODE' => false]);
        $this->assertEquals(['version' => '1.6', 'tape_mm' => 24, 'printer' => 'PT-P750W'], $state);
        file_put_contents($this->tmp . '/bad.json', 'nope');
        $this->assertSame([], Simulator::stateFromEnv(['PTSIM_STATE' => $this->tmp . '/bad.json']));
        $this->assertArrayHasKey('version', Simulator::defaults());
    }

    public function testUnknownPrinterAndTape(): void
    {
        $this->assertSame(5, $this->sim(['--info'], ['printer' => 'PT-9999'])['code']);
        $this->assertStringContainsString('tape is 0px', $this->sim(['--info'], ['tape_mm' => 13])['stdout']);
    }

    public function testEntryScript(): void
    {
        $this->simulate(['version' => '1.9']);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::simBin()) . ' --info 2>&1', $out, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('maximum printing width for this tape is 76px', implode("\n", $out));
    }

    /** Forward PNG filter, to build test images with every filter type. */
    private static function filter(int $type, string $line, string $prev, int $bpp): string
    {
        $out = '';
        for ($i = 0; $i < strlen($line); $i++) {
            $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $p = $a + $b - $c;
            $pred = [0, $a, $b, intdiv($a + $b, 2),
                (abs($p - $a) <= abs($p - $b) && abs($p - $a) <= abs($p - $c)) ? $a : (abs($p - $b) <= abs($p - $c) ? $b : $c)][$type];
            $out .= chr((ord($line[$i]) - $pred) & 0xff);
        }
        return $out;
    }

    public function testPngReaderHandlesEveryFilterAndBitDepth(): void
    {
        $width = 9;
        $height = 5;
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels[$y][$x] = ($x * 3 + $y * 5) % 7 < 3 ? 1 : 0;
            }
        }
        foreach ([1, 8] as $depth) {
            $raw = '';
            $prev = str_repeat("\0", (int) ceil($width * $depth / 8));
            for ($y = 0; $y < $height; $y++) {
                $line = '';
                if ($depth === 8) {
                    foreach ($pixels[$y] as $v) {
                        $line .= chr($v);
                    }
                } else {
                    $bits = implode('', $pixels[$y]) . str_repeat('0', 16 - $width);
                    $line = chr(bindec(substr($bits, 0, 8))) . chr(bindec(substr($bits, 8, 8)));
                }
                $type = $y % 5;
                $raw .= chr($type) . self::filter($type, $line, $prev, max(1, intdiv($depth, 8)));
                $prev = $line;
            }
            // black first in the palette: the reader must pick the darker colour
            $file = $this->tmp . "/d$depth.png";
            file_put_contents($file, self::pngChunks(pack('NNCCCCC', $width, $height, $depth, 3, 0, 0, 0), "\x10\x10\x10\xf0\xf0\xf0", $raw));
            $png = Png::read($file);
            $this->assertTrue($png['palette']);
            $expected = array_map(static function (array $row): string {
                return implode('', array_map(static function (int $v): string {
                    return $v === 0 ? '#' : '.';
                }, $row));
            }, $pixels);
            $this->assertSame($expected, $png['rows'], "depth $depth");
        }
    }

    public function testPngReaderRejectsOrFlagsOtherImages(): void
    {
        file_put_contents($this->tmp . '/x.txt', 'hello');
        $this->assertNull(Png::read($this->tmp . '/x.txt'));
        $this->assertNull(Png::read($this->tmp . '/missing.png'));
        file_put_contents($this->tmp . '/noihdr.png', "\x89PNG\r\n\x1a\n" . pack('N', 0) . 'IEND' . pack('N', 0));
        $this->assertNull(Png::read($this->tmp . '/noihdr.png'));
        file_put_contents($this->tmp . '/rgb.png', self::pngChunks(pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0), null, str_repeat("\0", 14)));
        $this->assertFalse(Png::read($this->tmp . '/rgb.png')['palette']);
        file_put_contents($this->tmp . '/short.png', self::pngChunks(pack('NNCCCCC', 20, 20, 1, 3, 0, 0, 0), "\xff\xff\xff\0\0\0", "\0\0"));
        $this->assertNull(Png::read($this->tmp . '/short.png'));
        file_put_contents($this->tmp . '/onecolour.png', self::pngChunks(pack('NNCCCCC', 8, 1, 1, 3, 0, 0, 0), "\xff\xff\xff", "\0\xff"));
        $this->assertSame(8, Png::read($this->tmp . '/onecolour.png')['black'], 'missing colour 1 counts as black, like gd');
        file_put_contents($this->tmp . '/blank.png', Png::blank(10, 3));
        $blank = Png::read($this->tmp . '/blank.png');
        $this->assertSame([10, 3, 0], [$blank['width'], $blank['height'], $blank['black']]);
    }
}
