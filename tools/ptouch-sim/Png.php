<?php

declare(strict_types=1);

namespace Labeler\Sim;

/**
 * Minimal PNG reader/writer for the simulator, without the gd extension.
 */
final class Png
{
    /**
     * Reads a PNG and counts the pixels ptouch-print would print black.
     * ptouch-print takes palette colour 1 as black when it is darker than colour 0.
     *
     * @return array{file: string, width: int, height: int, palette: bool, black: int, rows: list<string>}|null
     */
    public static function read(string $file): ?array
    {
        $bytes = @file_get_contents($file);
        if ($bytes === false || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $pos = 8;
        $idat = '';
        $plte = '';
        $ihdr = null;
        while ($pos + 8 <= strlen($bytes)) {
            $len = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $data = substr($bytes, $pos + 8, $len);
            if ($type === 'IHDR') {
                $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccomp/Cfilter/Cinterlace', $data);
            } elseif ($type === 'PLTE') {
                $plte = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $len;
        }
        if ($ihdr === null) {
            return null;
        }
        $w = (int) $ihdr['width'];
        $h = (int) $ihdr['height'];
        $result = ['file' => $file, 'width' => $w, 'height' => $h, 'palette' => false, 'black' => 0, 'rows' => []];
        if ($ihdr['type'] !== 3 || $ihdr['interlace'] !== 0 || $plte === '') {
            return $result; // gd loads it, but the printout would be wrong
        }
        $raw = @gzuncompress($idat);
        $depth = (int) $ihdr['depth'];
        $stride = (int) ceil($w * $depth / 8);
        if ($raw === false || strlen($raw) < $h * ($stride + 1)) {
            return null;
        }
        $result['palette'] = true;
        $lum = static function (int $i) use ($plte): int {
            return strlen($plte) >= ($i + 1) * 3
                ? ord($plte[$i * 3]) + ord($plte[$i * 3 + 1]) + ord($plte[$i * 3 + 2])
                : 0;
        };
        $dark = $lum(1) < $lum(0) ? 1 : 0;
        $prev = str_repeat("\0", $stride);
        $bpp = max(1, intdiv($depth, 8));
        for ($y = 0; $y < $h; $y++) {
            $filter = ord($raw[$y * ($stride + 1)]);
            $line = substr($raw, $y * ($stride + 1) + 1, $stride);
            $line = self::unfilter($filter, $line, $prev, $bpp);
            $prev = $line;
            $row = '';
            for ($x = 0; $x < $w; $x++) {
                $bit = $x * $depth;
                $byte = ord($line[$bit >> 3]);
                $index = ($byte >> (8 - $depth - ($bit & 7))) & ((1 << $depth) - 1);
                $black = $index === $dark;
                $row .= $black ? '#' : '.';
                $result['black'] += $black ? 1 : 0;
            }
            $result['rows'][] = $row;
        }
        return $result;
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
            switch ($filter) {
                case 1:
                    $x += $a;
                    break;
                case 2:
                    $x += $b;
                    break;
                case 3:
                    $x += intdiv($a + $b, 2);
                    break;
                case 4:
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                    break;
            }
            $out[$i] = chr($x & 0xff);
        }
        return $out;
    }

    /** A white 1-bit palette PNG. */
    public static function blank(int $width, int $height): string
    {
        $stride = (int) ceil($width / 8);
        $raw = str_repeat("\0" . str_repeat("\0", $stride), $height);
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 3, 0, 0, 0))
            . $chunk('PLTE', "\xff\xff\xff\x00\x00\x00")
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
    }
}
