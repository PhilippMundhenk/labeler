<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * Brother's QL raster protocol: the DK media, the bytes of a print job and the 32-byte status
 * answer. Pure functions, no I/O; QlPrint talks to the printer.
 *
 * Sources: Brother's "Raster Command Reference" for the QL series, and the Python library
 * brother_ql as a second opinion (the contract test in tools/ql-contract compares the bytes).
 */
final class QlRaster
{
    public const DPI = 300;

    public const CONTINUOUS = 0x0A;
    public const DIE_CUT = 0x0B;

    /**
     * DK media, named as in brother_ql: id => [width mm, length mm (0 = continuous), printable
     * dots across, printable dots along (0 = continuous), right margin in dots, feed margin in
     * dots, only for the wide models].
     */
    public const MEDIA = [
        '12' => [12, 0, 106, 0, 29, 35, false],
        '29' => [29, 0, 306, 0, 6, 35, false],
        '38' => [38, 0, 413, 0, 12, 35, false],
        '50' => [50, 0, 554, 0, 12, 35, false],
        '54' => [54, 0, 590, 0, 0, 35, false],
        '62' => [62, 0, 696, 0, 12, 35, false],
        '102' => [102, 0, 1164, 0, 12, 35, true],
        '17x54' => [17, 54, 165, 566, 0, 0, false],
        '17x87' => [17, 87, 165, 956, 0, 0, false],
        '23x23' => [23, 23, 202, 202, 42, 0, false],
        '29x42' => [29, 42, 306, 425, 6, 0, false],
        '29x90' => [29, 90, 306, 991, 6, 0, false],
        '39x90' => [38, 90, 413, 991, 12, 0, false],
        '39x48' => [39, 48, 425, 495, 6, 0, false],
        '52x29' => [52, 29, 578, 271, 0, 0, false],
        '54x29' => [54, 29, 598, 271, 60, 0, false],
        '60x86' => [60, 87, 672, 954, 18, 0, false],
        '62x29' => [62, 29, 696, 271, 12, 0, false],
        '62x100' => [62, 100, 696, 1109, 12, 0, false],
        '102x51' => [102, 51, 1164, 526, 12, 0, true],
        '102x152' => [102, 153, 1164, 1660, 12, 0, true],
        'd12' => [12, 12, 94, 94, 113, 35, false],
        'd24' => [24, 24, 236, 236, 42, 0, false],
        'd58' => [58, 58, 618, 618, 51, 0, false],
    ];

    /**
     * Models: name => [model code in the status answer, bytes per raster line, shortest label
     * in dots, switches to raster mode (ESC i a), cuts, has the expanded mode (ESC i K),
     * invalidate bytes, extra right margin in dots].
     */
    public const MODELS = [
        'QL-500' => [0x4F, 90, 295, false, false, false, 200, 0],
        'QL-550' => [0x4F, 90, 295, false, true, true, 200, 0],
        'QL-560' => [0x31, 90, 295, false, true, true, 200, 0],
        'QL-570' => [0x32, 90, 150, false, true, true, 200, 0],
        'QL-580N' => [0x33, 90, 150, true, true, true, 200, 0],
        'QL-600' => [0x47, 90, 150, true, true, true, 200, 0],
        'QL-650TD' => [0x51, 90, 295, true, true, true, 200, 0],
        'QL-700' => [0x35, 90, 150, false, true, true, 200, 0],
        'QL-710W' => [0x36, 90, 150, true, true, true, 200, 0],
        'QL-720NW' => [0x37, 90, 150, true, true, true, 200, 0],
        'QL-800' => [0x38, 90, 150, true, true, true, 400, 0],
        'QL-810W' => [0x39, 90, 150, true, true, true, 400, 0],
        'QL-820NWB' => [0x41, 90, 150, true, true, true, 400, 0],
        'QL-1050' => [0x50, 162, 295, true, true, true, 200, 44],
        'QL-1060N' => [0x34, 162, 295, true, true, true, 200, 44],
        'QL-1100' => [0x43, 162, 301, true, true, true, 200, 44],
        'QL-1110NWB' => [0x44, 162, 301, true, true, true, 200, 44],
        'QL-1115NWB' => [0x45, 162, 301, true, true, true, 200, 44],
    ];

    /** The model assumed when the printer names none Labeler knows. */
    public const DEFAULT_MODEL = 'QL-570';

    /**
     * Error bits: low byte = "error information 1" (byte 8 of the status), high byte = "error
     * information 2" (byte 9).
     */
    public const ERRORS = [
        0x0001 => 'No DK roll: insert one and close the cover.',
        0x0002 => 'The DK roll is used up: insert a new one.',
        0x0004 => 'Cutter jam: open the cover and remove the jammed label.',
        0x0010 => 'The printer is busy.',
        0x0020 => 'The printer was switched off.',
        0x0040 => 'Wrong AC adapter: the voltage is too high.',
        0x0080 => 'The fan does not work: switch the printer off.',
        0x0100 => 'This DK roll does not fit the label: insert the right roll.',
        0x0200 => 'The printer\'s buffer is full: send the label again.',
        0x0400 => 'The label did not arrive completely: send it again.',
        0x0800 => 'The printer\'s communication buffer is full.',
        0x1000 => 'The cover is open: close it.',
        0x2000 => 'Printing was cancelled on the printer.',
        0x4000 => 'The DK roll cannot be fed: check that it is inserted correctly.',
        0x8000 => 'System error: switch the printer off and on again.',
    ];

    public const STATUS_REPLY = 0x00;
    public const STATUS_PRINTED = 0x01;
    public const STATUS_ERROR = 0x02;
    public const STATUS_NOTIFICATION = 0x05;
    public const STATUS_PHASE = 0x06;

    /** Invalidate, initialize, status request: what is sent to ask for the status. */
    public static function statusRequest(?string $model = null): string
    {
        return str_repeat("\x00", self::model($model)[6]) . "\x1b@" . "\x1biS";
    }

    /** The media id for what the printer reports, null when Labeler does not know it. */
    public static function mediaId(int $type, int $widthMm, int $lengthMm): ?string
    {
        foreach (self::MEDIA as $id => [$w, $l]) {
            if ($w !== $widthMm) {
                continue;
            }
            if ($type === self::CONTINUOUS && $l === 0) {
                return (string) $id;
            }
            if ($type === self::DIE_CUT && $l > 0 && $l === $lengthMm) {
                return (string) $id;
            }
        }
        return null;
    }

    /** Bytes per raster line for a model name, 90 for unknown models. */
    public static function lineBytes(?string $model): int
    {
        return self::model($model)[1];
    }

    /** @return array{0: int, 1: int, 2: int, 3: bool, 4: bool, 5: bool, 6: int, 7: int} */
    public static function model(?string $model): array
    {
        return self::MODELS[(string) $model] ?? self::MODELS[self::DEFAULT_MODEL];
    }

    /** The model name for a model code of the status answer, null when unknown. */
    public static function modelName(int $code): ?string
    {
        foreach (self::MODELS as $name => [$c]) {
            if ($c === $code) {
                return $name;
            }
        }
        return null;
    }

    /**
     * A print job: one page per copy, in the order and form brother_ql writes them.
     *
     * @param list<string> $rows Labeler's label, one string per row of "0"/"1"; x runs along the
     *        label, y across it (the PNG as the editor draws it)
     * @param array{copies?: int, cut?: bool, cut_every?: int, cut_at_end?: bool, quality?: bool,
     *        model?: ?string} $options
     * @throws InvalidArgumentException when the label does not fit the media
     */
    public static function job(array $rows, string $mediaId, array $options = []): string
    {
        if (!isset(self::MEDIA[$mediaId])) {
            throw new InvalidArgumentException("unknown DK media $mediaId");
        }
        [$widthMm, $lengthMm, $across, $along, $rightMargin, $feedMargin, $wideOnly] = self::MEDIA[$mediaId];
        $modelName = $options['model'] ?? null;
        [, $lineBytes, $shortest, $modeSetting, $cutting, $expanded, $invalidate, $offset] = self::model($modelName);
        if ($wideOnly && $lineBytes !== 162) {
            throw new InvalidArgumentException("$mediaId mm media needs a wide QL printer");
        }
        $copies = max(1, (int) ($options['copies'] ?? 1));
        $cut = (bool) ($options['cut'] ?? true);
        $cutEvery = max(1, min(255, (int) ($options['cut_every'] ?? 1)));
        $cutAtEnd = (bool) ($options['cut_at_end'] ?? $cut);
        $quality = (bool) ($options['quality'] ?? true);
        $height = count($rows);
        $length = $height > 0 ? strlen($rows[0]) : 0;
        if ($height === 0 || $length === 0) {
            throw new InvalidArgumentException('the label is empty');
        }
        if ($height > $across) {
            throw new InvalidArgumentException("the label is {$height} px high, the DK media takes {$across} px");
        }
        if ($along > 0 && $length > $along) {
            throw new InvalidArgumentException("the label is {$length} px long, the die-cut label takes {$along} px");
        }
        // die-cut labels have a fixed length, continuous ones a shortest one: the rest stays white
        $wanted = $along > 0 ? $along : max($length, $shortest);
        if ($length < $wanted) {
            foreach ($rows as $y => $row) {
                $rows[$y] = str_pad($row, $wanted, '0');
            }
        }
        $lines = self::lines($rows, $across, $rightMargin + $offset, $lineBytes);
        $type = $along > 0 ? self::DIE_CUT : self::CONTINUOUS;

        $mode = $modeSetting ? "\x1bia\x01" : '';
        $job = $mode . str_repeat("\x00", $invalidate) . "\x1b@" . $mode;
        for ($page = 0; $page < $copies; $page++) {
            $flags = 0x80 | 0x02 | 0x04 | 0x08 | ($quality ? 0x40 : 0);
            $job .= "\x1biS" . "\x1biz" . chr($flags) . chr($type) . chr($widthMm) . chr($along > 0 ? $lengthMm : 0)
                . pack('V', count($lines)) . chr($page === 0 ? 0 : 1) . "\x00";
            if ($cut && $cutting) {
                $job .= "\x1biM\x40" . "\x1biA" . chr($cutEvery);
            }
            if ($expanded) {
                $job .= "\x1biK" . chr($cutAtEnd ? 0x08 : 0x00);
            }
            $job .= "\x1bid" . pack('v', $feedMargin);
            foreach ($lines as $line) {
                $job .= "g\x00" . chr($lineBytes) . $line;
            }
            $job .= $page === $copies - 1 ? "\x1a" : "\x0c";
        }
        return $job;
    }

    /**
     * Raster lines: line k is column k of the label, mirrored as the print head sees it, with
     * the label centred in the printable width of the media.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    public static function lines(array $rows, int $across, int $rightMargin, int $lineBytes): array
    {
        $height = count($rows);
        $length = strlen($rows[0]);
        $dots = $lineBytes * 8;
        // first dot of the printable area, counted from the end the head starts at
        $start = $rightMargin + intdiv($across - $height, 2);
        $lines = [];
        for ($k = 0; $k < $length; $k++) {
            $x = $length - 1 - $k;
            $line = array_fill(0, $lineBytes, 0);
            for ($y = 0; $y < $height; $y++) {
                if ($rows[$y][$x] === '1') {
                    $dot = $start + ($height - 1 - $y);
                    if ($dot < $dots) {
                        $line[$dot >> 3] |= 0x80 >> ($dot & 7);
                    }
                }
            }
            $lines[] = pack('C*', ...$line);
        }
        return $lines;
    }

    /**
     * Reads the 32-byte status answer.
     *
     * @return array{type: int, phase: int, model_code: int, error_code: int, media_type: int,
     *         media_width_mm: int, media_length_mm: int}|null null when it is not a status answer
     */
    public static function parseStatus(string $bytes): ?array
    {
        if (strlen($bytes) < 32 || ord($bytes[0]) !== 0x80 || ord($bytes[1]) !== 0x20 || $bytes[2] !== 'B') {
            return null;
        }
        return [
            'type' => ord($bytes[18]),
            'phase' => ord($bytes[19]),
            'model_code' => ord($bytes[4]),
            'error_code' => ord($bytes[8]) | (ord($bytes[9]) << 8),
            'media_type' => ord($bytes[11]),
            'media_width_mm' => ord($bytes[10]),
            'media_length_mm' => ord($bytes[17]),
        ];
    }

    /**
     * A status answer as the printer sends it (used by the simulator and the tests).
     */
    public static function status(int $modelCode, int $mediaType, int $widthMm, int $lengthMm, int $error = 0, int $type = self::STATUS_REPLY, int $phase = 0): string
    {
        $b = array_fill(0, 32, 0);
        $b[0] = 0x80;
        $b[1] = 0x20;
        $b[2] = 0x42;
        $b[3] = 0x34;
        $b[4] = $modelCode;
        $b[5] = 0x30;
        $b[6] = 0x30;
        $b[8] = $error & 0xff;
        $b[9] = ($error >> 8) & 0xff;
        $b[10] = $widthMm;
        $b[11] = $mediaType;
        $b[17] = $lengthMm;
        $b[18] = $type;
        $b[19] = $phase;
        return pack('C*', ...$b);
    }

    /** @return list<string> one message per error bit, unknown bits named by their value */
    public static function decodeErrors(int $code): array
    {
        $messages = [];
        for ($bit = 1; $bit <= 0x8000; $bit <<= 1) {
            if ($code & $bit) {
                $messages[] = self::ERRORS[$bit] ?? sprintf('The printer reports error 0x%04x.', $bit);
            }
        }
        return $messages;
    }

    /**
     * Labeler's printer info for a status answer.
     *
     * @param array{type: int, phase: int, model_code: int, error_code: int, media_type: int,
     *        media_width_mm: int, media_length_mm: int} $status
     * @return array<string, mixed>
     */
    public static function info(array $status, ?string $model, ?string $serial): array
    {
        $model = $model ?? self::modelName($status['model_code']) ?? sprintf('Brother QL (model 0x%02x)', $status['model_code']);
        $lineBytes = self::lineBytes($model);
        $info = self::blankInfo();
        $info['connected'] = true;
        $info['model'] = $model;
        $info['serial'] = $serial;
        // the widest printable area: 62 mm (or 102 mm on the wide models)
        $info['max_px'] = $lineBytes === 162 ? 1164 : 696;
        $info['min_px'] = self::model($model)[2];
        $info['media_type'] = $status['media_type'];
        $info['media_width_mm'] = $status['media_width_mm'];
        $info['error_code'] = $status['error_code'];
        $id = self::mediaId($status['media_type'], $status['media_width_mm'], $status['media_length_mm']);
        if ($id !== null && self::MEDIA[$id][6] && $lineBytes !== 162) {
            $id = null;
        }
        if ($id !== null) {
            [$w, $l, $across, $along] = self::MEDIA[$id];
            $info['tape_px'] = $across;
            $info['media'] = [
                'id' => $id,
                'kind' => $l === 0 ? 'continuous' : 'die-cut',
                'width_mm' => $w,
                'length_mm' => $l > 0 ? $l : null,
                'length_px' => $along > 0 ? $along : null,
                'round' => $id[0] === 'd',
            ];
        }
        if ($status['error_code'] & 0x0001 || $status['media_type'] === 0 || $status['media_width_mm'] === 0) {
            $info['errors'] = self::decodeErrors($status['error_code'] | 0x0001);
            $info['message'] = 'No DK roll: insert one and close the cover.';
            return $info;
        }
        if ($status['error_code'] !== 0) {
            $info['errors'] = self::decodeErrors($status['error_code']);
            $info['message'] = implode(' ', $info['errors']);
            return $info;
        }
        if ($id === null) {
            $info['message'] = $status['media_type'] === self::DIE_CUT
                ? "Labeler does not know DK labels of {$status['media_width_mm']} × {$status['media_length_mm']} mm."
                : "Labeler does not know {$status['media_width_mm']} mm DK rolls.";
            return $info;
        }
        $info['ready'] = true;
        return $info;
    }

    /**
     * The info array of all drivers, for a QL printer nothing is known of yet.
     *
     * @return array<string, mixed>
     */
    public static function blankInfo(string $message = ''): array
    {
        return [
            'connected' => false,
            'ready' => false,
            'model' => null,
            'serial' => null,
            'dpi' => self::DPI,
            'max_px' => null,
            'min_px' => null,
            'tape_px' => null,
            'media_type' => null,
            'media_width_mm' => null,
            'media' => null,
            'tape_color' => null,
            'text_color' => null,
            'error_code' => null,
            'errors' => [],
            'message' => $message,
        ];
    }
}
