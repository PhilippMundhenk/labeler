<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Wrapper around the ptouch-print command line tool.
 *
 * Supports ptouch-print 1.5 to 1.9. The command line changed in 1.7 (argp), the
 * --info output gained lines in 1.7 and 1.9, so output is parsed by pattern and
 * options are only passed when the installed version has them.
 */
final class PtouchPrint
{
    /** Option => first ptouch-print version that has it. */
    public const FEATURES = [
        'copies' => '1.6',
        'chain' => '1.6',
        'precut' => '1.8',
        'serial' => '1.9',
    ];

    private const TAPE_COLORS = [
        0x01 => 'White', 0x02 => 'Other', 0x03 => 'Clear', 0x04 => 'Red', 0x05 => 'Blue',
        0x06 => 'Yellow', 0x07 => 'Green', 0x08 => 'Black', 0x09 => 'Clear', 0x20 => 'Matte White',
        0x21 => 'Matte Clear', 0x22 => 'Matte Silver', 0x23 => 'Satin Gold', 0x24 => 'Satin Silver',
        0x30 => 'Blue', 0x31 => 'Red', 0x40 => 'Fluorescent Orange', 0x41 => 'Fluorescent Yellow',
        0x50 => 'Berry Pink', 0x51 => 'Light Gray', 0x52 => 'Lime Green', 0x60 => 'Yellow',
        0x61 => 'Pink', 0x62 => 'Blue', 0x70 => 'Heat-shrink Tube', 0x71 => 'Heat-shrink Tube white',
        0x90 => 'White', 0x91 => 'Yellow', 0xf0 => 'Cleaning', 0xf1 => 'Stencil', 0xff => 'Incompatible',
    ];

    /**
     * Error bits of the status, as ptouch-print prints them: low byte = Brother's "error
     * information 1", high byte = "error information 2" (raster command reference, ESC i S).
     */
    public const ERRORS = [
        0x0001 => 'No tape: insert a tape cassette.',
        0x0004 => 'Cutter jam: open the cover and remove the jammed tape.',
        0x0008 => 'The batteries are weak: connect the AC adapter.',
        0x0040 => 'Wrong AC adapter: the voltage is too high.',
        0x0100 => 'Replace the tape cassette: it is empty or does not fit.',
        0x1000 => 'The cover is open: close it.',
        0x2000 => 'The printer is overheated: let it cool down.',
    ];

    private const TEXT_COLORS = [
        0x01 => 'White', 0x02 => 'Other', 0x04 => 'Red', 0x05 => 'Blue', 0x08 => 'Black',
        0x0a => 'Gold', 0x62 => 'Blue', 0xf0 => 'Cleaning', 0xf1 => 'Stencil', 0xff => 'Incompatible',
    ];

    private string $bin;
    private int $timeout;
    private string $serial;
    private ?Log $log;
    private ?string $version = null;

    public function __construct(string $bin, int $timeout = 60, string $serial = '', ?Log $log = null)
    {
        $this->bin = $bin;
        $this->timeout = $timeout;
        $this->serial = $serial;
        $this->log = $log;
    }

    public static function fromConfig(Config $config, ?Log $log = null): self
    {
        return new self(
            (string) $config->get('ptouch_bin'),
            (int) $config->get('timeout'),
            (string) $config->get('serial'),
            $log
        );
    }

    /**
     * Installed version as "major.minor", or null when ptouch-print cannot be run.
     */
    public function version(): ?string
    {
        if ($this->version === null) {
            $result = $this->run(['--version'], false);
            $this->version = '';
            if ($result['code'] !== 127 && preg_match('/(\d+)\.(\d+)/', $result['output'], $m)) {
                $this->version = $m[1] . '.' . $m[2];
            }
        }
        return $this->version === '' ? null : $this->version;
    }

    public function supports(string $feature): bool
    {
        $version = $this->version();
        if ($version === null || !isset(self::FEATURES[$feature])) {
            return false;
        }
        return version_compare($version, self::FEATURES[$feature], '>=');
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        $features = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $features[$feature] = $this->supports($feature);
        }
        return $features;
    }

    /**
     * Printer and tape status from `ptouch-print --info`.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $result = $this->run(array_merge($this->serialArgs(), ['--info']), false);
        return self::parseInfo($result['output'], $result['code']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseInfo(string $output, int $code): array
    {
        $info = [
            'connected' => false,
            'ready' => false,
            'model' => null,
            'serial' => null,
            'dpi' => 180,
            'max_px' => null,
            'tape_px' => null,
            'media_type' => null,
            'media_width_mm' => null,
            'tape_color' => null,
            'text_color' => null,
            'error_code' => null,
            'errors' => [],
            'message' => '',
        ];
        if ($code === 127) {
            $info['message'] = 'ptouch-print was not found. Install it or set ptouch_bin in config.php.';
            return $info;
        }
        if (preg_match('/^(.+?) found on USB bus \d+, device \d+(?:, serial (\S+))?/m', $output, $m)) {
            $info['connected'] = true;
            $info['model'] = trim($m[1]);
            $info['serial'] = isset($m[2]) && $m[2] !== '(null)' ? $m[2] : null;
        }
        if (stripos($output, 'P-Lite Mode') !== false) {
            $info['message'] = 'The printer is in P-Lite mode. Set the mode switch to E.';
            return $info;
        }
        if (stripos($output, 'currently is unsupported') !== false) {
            $info['message'] = 'This printer model is not supported by ptouch-print.';
            return $info;
        }
        // libptouch prints "No P-Touch printer found" after an open error too, so check this first
        if (stripos($output, 'libusb_open error') !== false || stripos($output, 'LIBUSB_ERROR_ACCESS') !== false) {
            $info['message'] = 'A printer is attached, but the web server may not use it. Install the udev rule (see docs/INSTALL.md).';
            return $info;
        }
        if (preg_match('/No P-Touch printer( with serial \S+)? found/', $output)) {
            $info['connected'] = false;
            $info['message'] = 'No printer found. Check the USB cable, switch the printer on and set the mode switch to E.';
            return $info;
        }
        if (preg_match('/printer has (\d+) dpi/', $output, $m)) {
            $info['dpi'] = (int) $m[1];
        }
        if (preg_match('/maximum printing width (?:for this printer )?is (\d+)\s*px/', $output, $m)) {
            $info['max_px'] = (int) $m[1];
        }
        if (preg_match('/maximum printing width for this tape is (\d+)\s*px/', $output, $m)) {
            $info['tape_px'] = (int) $m[1];
        }
        if (preg_match('/media type = (?:0x)?([0-9a-fA-F]{2})/', $output, $m)) {
            $info['media_type'] = hexdec($m[1]);
        }
        if (preg_match('/media width = (\d+) mm/', $output, $m)) {
            $info['media_width_mm'] = (int) $m[1];
        }
        if (preg_match('/tape color = (?:0x)?([0-9a-fA-F]{2})/', $output, $m)) {
            $info['tape_color'] = self::color(self::TAPE_COLORS, (int) hexdec($m[1]));
        }
        if (preg_match('/text color = (?:0x)?([0-9a-fA-F]{2})/', $output, $m)) {
            $info['text_color'] = self::color(self::TEXT_COLORS, (int) hexdec($m[1]));
        }
        if (preg_match('/error = (?:0x)?([0-9a-fA-F]{4})/', $output, $m)) {
            $info['error_code'] = (int) hexdec($m[1]);
        }
        if ($info['media_width_mm'] === null && $info['tape_px'] === null) {
            $info['message'] = $code === 0
                ? 'Unexpected answer from ptouch-print.'
                : 'ptouch-print failed: ' . self::lastLine($output);
            return $info;
        }
        $info['connected'] = true;
        if ($info['media_type'] === 0 || $info['media_width_mm'] === 0 || $info['tape_px'] === 0) {
            $info['message'] = 'No tape cassette inserted.';
            return $info;
        }
        if ($info['error_code']) {
            $info['errors'] = self::decodeErrors((int) $info['error_code']);
            $info['message'] = implode(' ', $info['errors']);
            return $info;
        }
        $info['ready'] = true;
        return $info;
    }

    /**
     * Print a 2-colour palette PNG.
     *
     * @return array{ok: bool, output: string, notes: list<string>}
     */
    public function printImage(string $png, int $copies = 1, bool $chain = false, bool $precut = false): array
    {
        $notes = [];
        $args = $this->serialArgs();
        $runs = 1;
        if ($chain) {
            if ($this->supports('chain')) {
                $args[] = '--chain';
            } else {
                $notes[] = 'chain printing needs ptouch-print ' . self::FEATURES['chain'];
            }
        }
        if ($precut) {
            if ($this->supports('precut')) {
                $args[] = '--precut';
            } else {
                $notes[] = 'precut needs ptouch-print ' . self::FEATURES['precut'];
            }
        }
        if ($copies > 1) {
            if ($this->supports('copies')) {
                $args[] = '--copies';
                $args[] = (string) $copies;
            } else {
                $runs = $copies;
            }
        }
        $args[] = '--image';
        $args[] = $png;
        $output = '';
        for ($i = 0; $i < $runs; $i++) {
            $result = $this->run($args, true);
            $output .= $result['output'];
            $failed = $result['code'] !== 0
                || preg_match('/image is too large|nothing to print|failed|No P-Touch printer|error/i', $result['output']);
            if ($failed) {
                return ['ok' => false, 'output' => trim($output), 'notes' => $notes];
            }
        }
        return ['ok' => true, 'output' => trim($output), 'notes' => $notes];
    }

    /** @return list<string> */
    private function serialArgs(): array
    {
        if ($this->serial === '' || !$this->supports('serial')) {
            return [];
        }
        return ['--serial', $this->serial];
    }

    /**
     * @param list<string> $args
     * @return array{code: int, output: string, timed_out: bool, ms: int}
     */
    private function run(array $args, bool $log): array
    {
        $result = Process::run(array_merge([$this->bin], $args), $this->timeout);
        if ($log && $this->log !== null) {
            $this->log->add($args, $result['code'], $result['output'], $result['ms']);
        }
        return $result;
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

    /** @param array<int, string> $names */
    private static function color(array $names, int $code): array
    {
        return ['code' => $code, 'name' => $names[$code] ?? 'Unknown'];
    }

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
        return $lines ? $lines[count($lines) - 1] : 'no output';
    }
}
