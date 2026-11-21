<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * Checks that uploaded bytes are a PNG that `ptouch-print --image` prints correctly:
 * a palette image with at most two colours, not higher than the tape.
 */
final class Png
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    public const MAX_WIDTH = 20000;

    /**
     * @return array{width: int, height: int}
     * @throws InvalidArgumentException with a message for the user
     */
    public static function inspect(string $bytes, int $maxHeight = 0): array
    {
        if (strlen($bytes) < 33 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            throw new InvalidArgumentException('not a PNG image');
        }
        $length = unpack('N', substr($bytes, 8, 4))[1];
        if ($length !== 13 || substr($bytes, 12, 4) !== 'IHDR') {
            throw new InvalidArgumentException('PNG header missing');
        }
        $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype', substr($bytes, 16, 10));
        $width = (int) $ihdr['width'];
        $height = (int) $ihdr['height'];
        if ($width < 1 || $height < 1 || $width > self::MAX_WIDTH) {
            throw new InvalidArgumentException("unsupported image size {$width} x {$height} px");
        }
        if ($ihdr['type'] !== 3 || !in_array($ihdr['depth'], [1, 2, 4, 8], true)) {
            throw new InvalidArgumentException('the label must be a palette PNG (black and white)');
        }
        $palette = self::chunk($bytes, 'PLTE');
        if ($palette === null || strlen($palette) % 3 !== 0 || strlen($palette) > 6) {
            throw new InvalidArgumentException('the label must have exactly two colours');
        }
        if ($maxHeight > 0 && $height > $maxHeight) {
            throw new InvalidArgumentException("the label is {$height} px high, the tape takes {$maxHeight} px");
        }
        return ['width' => $width, 'height' => $height];
    }

    /**
     * The pixels of a palette PNG that inspect() accepted, one string per row with "1" for a
     * black pixel and "0" for white. Colour 1 is black when it is darker than colour 0, as
     * ptouch-print reads it.
     *
     * @return list<string>
     * @throws InvalidArgumentException when the image data cannot be read
     */
    public static function rows(string $bytes): array
    {
        $size = self::inspect($bytes);
        $ihdr = unpack('Cdepth/Ctype/Ccomp/Cfilter/Cinterlace', substr($bytes, 24, 5));
        if ($ihdr['interlace'] !== 0) {
            throw new InvalidArgumentException('interlaced PNGs are not supported');
        }
        $palette = (string) self::chunk($bytes, 'PLTE');
        $idat = '';
        $pos = 8;
        while ($pos + 8 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $pos, 4))[1];
            if (substr($bytes, $pos + 4, 4) === 'IDAT') {
                $idat .= substr($bytes, $pos + 8, $length);
            }
            $pos += 12 + $length;
        }
        $width = $size['width'];
        $height = $size['height'];
        $depth = (int) $ihdr['depth'];
        $stride = intdiv($width * $depth + 7, 8);
        $raw = @gzuncompress($idat);
        if ($raw === false || strlen($raw) < $height * ($stride + 1)) {
            throw new InvalidArgumentException('the PNG image data is damaged');
        }
        $lum = static function (int $i) use ($palette): int {
            return strlen($palette) >= ($i + 1) * 3
                ? ord($palette[$i * 3]) + ord($palette[$i * 3 + 1]) + ord($palette[$i * 3 + 2])
                : 0;
        };
        $dark = $lum(1) < $lum(0) ? 1 : 0;
        $mask = (1 << $depth) - 1;
        $rows = [];
        $prev = str_repeat("\0", $stride);
        $bpp = max(1, intdiv($depth, 8));
        for ($y = 0; $y < $height; $y++) {
            $offset = $y * ($stride + 1);
            $line = self::unfilter(ord($raw[$offset]), substr($raw, $offset + 1, $stride), $prev, $bpp);
            $prev = $line;
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $bit = $x * $depth;
                $index = (ord($line[$bit >> 3]) >> (8 - $depth - ($bit & 7))) & $mask;
                $row .= $index === $dark ? '1' : '0';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function unfilter(int $filter, string $line, string $prev, int $bpp): string
    {
        $out = $line;
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $x = ord($line[$i]);
            if ($filter === 1) {
                $x += $a;
            } elseif ($filter === 2) {
                $x += $b;
            } elseif ($filter === 3) {
                $x += intdiv($a + $b, 2);
            } elseif ($filter === 4) {
                $p = $a + $b - $c;
                $pa = abs($p - $a);
                $pb = abs($p - $b);
                $pc = abs($p - $c);
                $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
            }
            $out[$i] = chr($x & 0xff);
        }
        return $out;
    }

    private static function chunk(string $bytes, string $type): ?string
    {
        $pos = 8;
        $total = strlen($bytes);
        while ($pos + 8 <= $total) {
            $length = unpack('N', substr($bytes, $pos, 4))[1];
            $name = substr($bytes, $pos + 4, 4);
            if ($name === $type) {
                return substr($bytes, $pos + 8, $length);
            }
            if ($name === 'IDAT' || $name === 'IEND') {
                return null;
            }
            $pos += 12 + $length;
        }
        return null;
    }
}
