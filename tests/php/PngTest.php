<?php

declare(strict_types=1);

namespace Labeler\Tests;

use InvalidArgumentException;
use Labeler\Png;

final class PngTest extends TestCase
{
    public function testAcceptsTwoColourPalettePng(): void
    {
        $this->assertSame(['width' => 100, 'height' => 76], Png::inspect(self::labelPng(100, 76), 76));
        $this->assertSame(['width' => 3, 'height' => 500], Png::inspect(self::labelPng(3, 500)));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badImages(): array
    {
        $ihdr = static function (int $w, int $h, int $depth, int $type): string {
            return pack('NNCCCCC', $w, $h, $depth, $type, 0, 0, 0);
        };
        return [
            'not a png' => ['GIF89a' . str_repeat("\0", 40), 'not a PNG'],
            'too short' => ["\x89PNG\r\n\x1a\n", 'not a PNG'],
            'no header' => ["\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IDAT' . str_repeat("\0", 30), 'header missing'],
            'zero size' => [self::pngChunks($ihdr(0, 10, 1, 3), "\xff\xff\xff\0\0\0", ''), 'unsupported image size'],
            'too wide' => [self::pngChunks($ihdr(Png::MAX_WIDTH + 1, 10, 1, 3), "\xff\xff\xff\0\0\0", ''), 'unsupported image size'],
            'truecolour' => [self::pngChunks($ihdr(4, 4, 8, 2), null, str_repeat("\0", 52)), 'palette PNG'],
            'odd depth' => [self::pngChunks($ihdr(4, 4, 16, 3), "\xff\xff\xff\0\0\0", ''), 'palette PNG'],
            'no palette' => [self::pngChunks($ihdr(4, 4, 1, 3), null, ''), 'exactly two colours'],
            'three colours' => [self::pngChunks($ihdr(4, 4, 2, 3), str_repeat("\x10\x20\x30", 3), ''), 'exactly two colours'],
            'broken palette' => [self::pngChunks($ihdr(4, 4, 2, 3), "\xff\xff", ''), 'exactly two colours'],
        ];
    }

    /** @dataProvider badImages */
    public function testRejects(string $bytes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Png::inspect($bytes);
    }

    public function testRejectsLabelsHigherThanTheTape(): void
    {
        $this->expectExceptionMessage('the label is 128 px high, the tape takes 76 px');
        Png::inspect(self::labelPng(10, 128), 76);
    }

    public function testFindsThePaletteAfterOtherChunks(): void
    {
        $png = self::labelPng(8, 8);
        $text = pack('N', 4) . 'tEXt' . 'a=bc' . pack('N', crc32('tEXta=bc'));
        $png = substr($png, 0, 33) . $text . substr($png, 33);
        $this->assertSame(8, Png::inspect($png)['width']);
    }

    public function testTruncatedFileHasNoPalette(): void
    {
        $this->expectExceptionMessage('exactly two colours');
        Png::inspect(substr(self::labelPng(8, 8), 0, 40));
    }
}
