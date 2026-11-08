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
