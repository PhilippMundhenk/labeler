<?php

declare(strict_types=1);

namespace Labeler\Tests;

use InvalidArgumentException;
use Labeler\Png;
use Labeler\QlRaster;
use Labeler\Sim\Png as SimPng;

final class QlRasterTest extends TestCase
{
    /** @return list<string> */
    private static function rows(int $height, int $length, string $fill = '0'): array
    {
        return array_fill(0, $height, str_repeat($fill, $length));
    }

    public function testMediaIds(): void
    {
        $this->assertSame('62', QlRaster::mediaId(QlRaster::CONTINUOUS, 62, 0));
        $this->assertSame('12', QlRaster::mediaId(QlRaster::CONTINUOUS, 12, 0));
        $this->assertSame('29x90', QlRaster::mediaId(QlRaster::DIE_CUT, 29, 90));
        $this->assertSame('62x100', QlRaster::mediaId(QlRaster::DIE_CUT, 62, 100));
        $this->assertSame('d24', QlRaster::mediaId(QlRaster::DIE_CUT, 24, 24));
        $this->assertSame('23x23', QlRaster::mediaId(QlRaster::DIE_CUT, 23, 23));
        $this->assertNull(QlRaster::mediaId(QlRaster::DIE_CUT, 29, 77));
        $this->assertNull(QlRaster::mediaId(QlRaster::CONTINUOUS, 104, 0));
        $this->assertNull(QlRaster::mediaId(0, 62, 0));
    }

    public function testModels(): void
    {
        $this->assertSame('QL-570', QlRaster::modelName(0x32));
        $this->assertNull(QlRaster::modelName(0x01));
        $this->assertSame(90, QlRaster::lineBytes('QL-570'));
        $this->assertSame(162, QlRaster::lineBytes('QL-1060N'));
        $this->assertSame(90, QlRaster::lineBytes(null));
        $this->assertSame(QlRaster::MODELS['QL-570'], QlRaster::model('QL-9999'));
    }

    public function testJobBytes(): void
    {
        $rows = self::rows(696, 3);
        $rows[695][2] = '1'; // bottom row, end of the label
        $rows[0][0] = '1';   // top row, start of the label: last line, last printable dot
        $job = QlRaster::job($rows, '62', ['model' => 'QL-570']);
        $head = str_repeat("\x00", 200) . "\x1b@"
            . "\x1biS\x1biz\xce\x0a\x3e\x00\x96\x00\x00\x00\x00\x00"
            . "\x1biM\x40\x1biA\x01\x1biK\x08\x1bid\x23\x00";
        $this->assertSame(bin2hex($head), bin2hex(substr($job, 0, strlen($head))), 'QL-570: no ESC i a');
        // shorter than the QL-570's 150 dots: white lines first (the end of the label)
        $lines = str_split(substr($job, strlen($head), 150 * 93), 93);
        $this->assertCount(150, $lines);
        foreach ($lines as $line) {
            $this->assertSame("g\x00\x5a", substr($line, 0, 3));
        }
        $this->assertSame(str_repeat("\x00", 90), substr($lines[0], 3));
        // dot 12 (right margin) is byte 1, bit 0x08; dot 12 + 695 = 707 is byte 88, bit 0x10
        $this->assertSame(0x08, ord($lines[147][3 + 1]));
        $this->assertSame(str_repeat("\x00", 90), substr($lines[148], 3));
        $this->assertSame(0x10, ord($lines[149][3 + 88]));
        $this->assertSame("\x1a", substr($job, -1));
        $this->assertSame(strlen($head) + 150 * 93 + 1, strlen($job));
    }

    public function testJobOptions(): void
    {
        $rows = self::rows(306, 10, '1');
        $job = QlRaster::job($rows, '29x90', ['copies' => 3, 'cut' => false, 'quality' => false, 'model' => 'QL-820NWB']);
        $this->assertSame("\x1bia\x01" . str_repeat("\x00", 400) . "\x1b@\x1bia\x01", substr($job, 0, 410), 'raster mode before and after');
        $this->assertSame(3, substr_count($job, "\x1biS\x1biz"));
        $this->assertStringContainsString("\x1biz\x8e\x0b\x1d\x5a\xdf\x03\x00\x00\x00\x00", $job, 'die-cut, 991 lines, first page');
        $this->assertStringContainsString("\x1biz\x8e\x0b\x1d\x5a\xdf\x03\x00\x00\x01\x00", $job, 'later pages');
        $this->assertStringNotContainsString("\x1biM", $job);
        $this->assertStringContainsString("\x1biK\x00\x1bid\x00\x00", $job, 'no cut at the end, no feed margin');
        $this->assertSame(2, substr_count($job, "\x0c"));
        $this->assertSame(1, substr_count($job, "\x1a"));
        $this->assertStringContainsString("\x1biA\x05", QlRaster::job($rows, '29', ['copies' => 2, 'cut_every' => 5]));
        $this->assertStringContainsString("\x1biK\x00", QlRaster::job($rows, '29', ['cut_at_end' => false]));
        $wide = QlRaster::job(self::rows(10, 1, '1'), '102', ['model' => 'QL-1060N']);
        $this->assertSame(295, substr_count($wide, "g\x00\xa2"), 'shortest label of the QL-1060N');
        // QL-500: no cutter, no expanded mode
        $old = QlRaster::job($rows, '29', ['model' => 'QL-500']);
        $this->assertStringNotContainsString("\x1biM", $old);
        $this->assertStringNotContainsString("\x1biK", $old);
        $this->assertStringContainsString("\x1bid\x23\x00", $old);
    }

    public function testJobRefusesWhatDoesNotFit(): void
    {
        $cases = [
            ['unknown DK media 99', self::rows(10, 10), '99'],
            ['the label is empty', [], '62'],
            ['the label is 307 px high, the DK media takes 306 px', self::rows(307, 10), '29'],
            ['the label is 992 px long, the die-cut label takes 991 px', self::rows(10, 992), '29x90'],
            ['102 mm media needs a wide QL printer', self::rows(10, 10), '102'],
        ];
        foreach ($cases as [$message, $rows, $media]) {
            try {
                QlRaster::job($rows, $media);
                $this->fail("no exception for $message");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function testStatusRoundTrip(): void
    {
        $bytes = QlRaster::status(0x32, QlRaster::DIE_CUT, 29, 90, 0x1004, QlRaster::STATUS_ERROR, 1);
        $this->assertSame(32, strlen($bytes));
        $this->assertSame([
            'type' => 2, 'phase' => 1, 'model_code' => 0x32, 'error_code' => 0x1004,
            'media_type' => 0x0b, 'media_width_mm' => 29, 'media_length_mm' => 90,
        ], QlRaster::parseStatus($bytes));
        $this->assertNull(QlRaster::parseStatus(''));
        $this->assertNull(QlRaster::parseStatus(str_repeat("\x00", 32)));
        $this->assertSame(str_repeat("\x00", 200) . "\x1b@\x1biS", QlRaster::statusRequest());
        $this->assertSame(str_repeat("\x00", 400) . "\x1b@\x1biS", QlRaster::statusRequest('QL-800'));
    }

    public function testInfo(): void
    {
        $status = static function (int $type, int $w, int $l, int $error = 0, int $model = 0x32): array {
            return (array) QlRaster::parseStatus(QlRaster::status($model, $type, $w, $l, $error));
        };
        $info = QlRaster::info($status(QlRaster::CONTINUOUS, 62, 0), null, 'S1');
        $this->assertTrue($info['ready']);
        $this->assertSame(['QL-570', 'S1', 300, 696, 696, 62], [$info['model'], $info['serial'], $info['dpi'], $info['tape_px'], $info['max_px'], $info['media_width_mm']]);
        $this->assertSame(['id' => '62', 'kind' => 'continuous', 'width_mm' => 62, 'length_mm' => null, 'length_px' => null, 'round' => false], $info['media']);

        $info = QlRaster::info($status(QlRaster::DIE_CUT, 24, 24), 'QL-1060N', null);
        $this->assertSame(['d24', 'die-cut', 24, 236, true, 1164, 295], [$info['media']['id'], $info['media']['kind'], $info['media']['length_mm'], $info['media']['length_px'], $info['media']['round'], $info['max_px'], $info['min_px']]);
        $this->assertSame('Labeler does not know 102 mm DK rolls.', QlRaster::info($status(QlRaster::CONTINUOUS, 102, 0), 'QL-570', null)['message'], 'wide media on a narrow printer');
        $this->assertTrue(QlRaster::info($status(QlRaster::CONTINUOUS, 102, 0), 'QL-1060N', null)['ready']);

        $info = QlRaster::info($status(0, 0, 0), null, null);
        $this->assertFalse($info['ready']);
        $this->assertSame('No DK roll: insert one and close the cover.', $info['message']);

        $info = QlRaster::info($status(QlRaster::CONTINUOUS, 62, 0, 0x1004), null, null);
        $this->assertSame('Cutter jam: open the cover and remove the jammed label. The cover is open: close it.', $info['message']);
        $this->assertSame(0x1004, $info['error_code']);

        $this->assertSame('Labeler does not know DK labels of 29 × 77 mm.', QlRaster::info($status(QlRaster::DIE_CUT, 29, 77), null, null)['message']);
        $this->assertSame('Labeler does not know 104 mm DK rolls.', QlRaster::info($status(QlRaster::CONTINUOUS, 104, 0), null, null)['message']);
        $this->assertSame('Brother QL (model 0x01)', QlRaster::info($status(QlRaster::CONTINUOUS, 62, 0, 0, 1), null, null)['model']);
    }

    public function testErrors(): void
    {
        $this->assertSame(['The printer reports error 0x0008.'], QlRaster::decodeErrors(0x0008));
        $this->assertCount(16, QlRaster::decodeErrors(0xffff));
        $this->assertSame([], QlRaster::decodeErrors(0));
    }

    public function testPngRows(): void
    {
        $rows = ['10110', '00001', '11111'];
        $this->assertSame($rows, Png::rows(SimPng::fromRows($rows)));
        // palette with black first: colour 0 is the ink
        $png = self::pngChunks(pack('NNCCCCC', 8, 1, 1, 3, 0, 0, 0), "\0\0\0\xff\xff\xff", "\0\x0f");
        $this->assertSame(['11110000'], Png::rows($png));
        // 8-bit palette indices with all filter types
        $raw = "\x00\x01\x00" . "\x01\x01\xff" . "\x02\x00\x01" . "\x03\x01\x00" . "\x04\x00\x00";
        $png = self::pngChunks(pack('NNCCCCC', 2, 5, 8, 3, 0, 0, 0), "\xff\xff\xff\0\0\0", $raw);
        $this->assertSame(['10', '10', '11', '11', '11'], Png::rows($png));
        foreach ([
            'interlaced PNGs are not supported' => self::pngChunks(pack('NNCCCCC', 8, 1, 1, 3, 0, 0, 1), "\xff\xff\xff\0\0\0", "\0\0"),
            'the PNG image data is damaged' => self::pngChunks(pack('NNCCCCC', 8, 4, 1, 3, 0, 0, 0), "\xff\xff\xff\0\0\0", "\0\0"),
        ] as $message => $bad) {
            try {
                Png::rows($bad);
                $this->fail($message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}
