<?php

declare(strict_types=1);

namespace Labeler\Sim;

/**
 * A stand-in for the ptouch-print command line tool and a connected P-Touch printer.
 *
 * It accepts the same arguments as the selected ptouch-print version (1.5 to 1.9),
 * prints the same messages, and returns the same exit codes. Printed labels are not
 * sent anywhere: each one is stored as PNG plus a JSON record in the output folder.
 *
 * State (see defaults()) comes from PTSIM_* environment variables and, when
 * PTSIM_STATE names a JSON file, from that file, so a running test can change it.
 */
final class Simulator
{
    /** tape mm => [px at 180 dpi, px at 360 dpi], from libptouch.c */
    public const TAPES = [
        4 => [24, 48], 6 => [32, 64], 9 => [52, 106], 12 => [76, 150],
        18 => [120, 234], 21 => [124, 248], 24 => [128, 320], 36 => [192, 454],
    ];

    /** model => [max px, dpi, max tape mm, has precut] */
    public const PRINTERS = [
        'PT-P700' => [128, 180, 24, true],
        'PT-P750W' => [128, 180, 24, true],
        'PT-H500' => [128, 180, 24, true],
        'PT-D600' => [128, 180, 24, true],
        'PT-P300BT' => [64, 180, 12, false],
        'PT-P900W' => [454, 360, 36, true],
    ];

    private const MEDIA_TYPES = [0x00 => 'No media', 0x01 => 'Laminated tape', 0x03 => 'Non-laminated tape',
        0x04 => 'Fabric tape', 0x11 => 'Heat-shrink tube', 0x13 => 'Flexi tape', 0x14 => 'Flexible ID tape',
        0x15 => 'Satin tape', 0x17 => 'Heat-shrink tube', 0xff => 'Incompatible tape'];
    private const TAPE_COLORS = [0x01 => 'White', 0x03 => 'Clear', 0x04 => 'Red', 0x05 => 'Blue',
        0x06 => 'Yellow', 0x07 => 'Green', 0x08 => 'Black', 0x09 => 'Clear', 0x23 => 'Satin Gold'];
    private const TEXT_COLORS = [0x01 => 'White', 0x04 => 'Red', 0x05 => 'Blue', 0x08 => 'Black', 0x0a => 'Gold'];

    /** @var array<string, mixed> */
    private array $state;
    /** @var list<string> */
    private array $out = [];
    /** @var list<string> */
    private array $err = [];

    /** @param array<string, mixed> $state */
    private string $stateFile;

    /** @param string $stateFile JSON state file that a "jam" writes the error into */
    public function __construct(array $state, string $stateFile = '')
    {
        $this->state = $state + self::defaults();
        $this->stateFile = $stateFile;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => '1.9',      // ptouch-print version to imitate
            'printer' => 'PT-P700',  // model, or "none" for no printer on USB
            'serial' => '000F1Z401234',
            'mode' => 'normal',      // normal | plite | unsupported | access
            'tape_mm' => 12,         // 0 = no cassette
            'media_type' => 1,       // 1 laminated
            'tape_color' => 1,       // 1 white
            'text_color' => 8,       // 8 black
            'error' => 0,            // status error bits, e.g. 0x1000 cover open, 0x0004 cutter jam
            'out' => '',             // folder for printed labels, empty = discard
            'delay_ms' => 0,         // time a print takes
            'others' => [],          // more printers: list of {printer, serial, tape_mm}
            'fail' => '',            // "print": USB write error; "jam": the print looks fine (exit 0, like
                                     // the real tool) but the printer then reports a cutter jam
        ];
    }

    /**
     * @param array<string, string|false> $env
     * @return array<string, mixed>
     */
    public static function stateFromEnv(array $env): array
    {
        $state = [];
        foreach (array_keys(self::defaults()) as $key) {
            $name = 'PTSIM_' . strtoupper($key);
            if (isset($env[$name]) && $env[$name] !== false) {
                $state[$key] = $env[$name];
            }
        }
        $file = $env['PTSIM_STATE'] ?? false;
        if (is_string($file) && is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json)) {
                $state = $json + $state;
            }
        }
        foreach (['tape_mm', 'media_type', 'tape_color', 'text_color', 'error', 'delay_ms'] as $key) {
            if (isset($state[$key])) {
                $state[$key] = (int) $state[$key];
            }
        }
        return $state;
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    public function run(array $argv): array
    {
        $code = $this->dispatch($argv);
        return [
            'code' => $code,
            'stdout' => implode('', $this->out),
            'stderr' => implode('', $this->err),
        ];
    }

    private function v(string $min): bool
    {
        return version_compare((string) $this->state['version'], $min, '>=');
    }

    private function say(string $text): void
    {
        $this->out[] = $text . "\n";
    }

    private function warn(string $text): void
    {
        $this->err[] = $text . "\n";
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        $args = $this->v('1.7') ? $this->parseArgp($argv) : $this->parseLegacy($argv);
        if (is_int($args)) {
            return $args;
        }
        if ($args['version']) {
            $this->say($this->v('1.7')
                ? 'ptouch-print v' . $this->state['version'] . '.r0.g0000000'
                : 'ptouch-print version v' . $this->state['version'] . ' by Dominic Radermacher');
            return 0;
        }
        if ($args['list_supported']) {
            foreach (array_keys(self::PRINTERS) as $model) {
                $this->say($model);
            }
            return 0;
        }
        if ($args['list_connected']) {
            if ($this->state['printer'] === 'none') {
                $this->warn('No P-Touch printer found on USB (remember to put switch to position E)');
                return 5;
            }
            // format of ptouch_list_connected() in libptouch.c
            $device = 4;
            foreach (array_merge([$this->state], $this->others()) as $p) {
                $this->say(sprintf("%s\tserial %s\t(USB bus 1, device %d)%s", $p['printer'], $p['serial'], $device++,
                    ($p['mode'] ?? 'normal') === 'plite' ? ' - in P-Lite mode, unsupported' : ''));
            }
            return 0;
        }

        $tapePx = 0;
        $dpi = 180;
        if ($args['force_tape_width'] > 0) {
            $tapePx = $args['force_tape_width'];
        } else {
            $open = $this->open($args['serial']);
            if ($open !== 0) {
                return $open;
            }
            [$maxPx, $dpi] = self::PRINTERS[$this->state['printer']];
            $tapePx = $this->tapePx();
            if ($args['info']) {
                $this->info($maxPx, $dpi, $tapePx);
                return 0;
            }
        }

        if (!$args['jobs']) {
            return 0;
        }
        $label = ['width' => 0, 'height' => 0, 'black' => 0, 'parts' => []];
        foreach ($args['jobs'] as $job) {
            if ($job[0] === 'image') {
                $png = Png::read($job[1]);
                if ($png === null) {
                    $this->say('failed to load image file');
                    return 1;
                }
                $label['width'] += $png['width'];
                $label['height'] = max($label['height'], $png['height']);
                $label['black'] += $png['black'];
                $label['parts'][] = ['image' => $png['width'] . 'x' . $png['height']];
                $label['png'] = $png;
            } elseif ($job[0] === 'text') {
                $label['parts'][] = ['text' => $job[1]];
                $label['width'] += 20 * max(array_map('strlen', $job[1]));
                $label['height'] = max($label['height'], $tapePx);
            } elseif ($job[0] === 'pad') {
                $label['width'] += (int) $job[1];
                $label['parts'][] = ['pad' => (int) $job[1]];
            } else {
                $label['width'] += 9;
                $label['parts'][] = ['cutmark' => true];
            }
        }

        if ($args['writepng'] !== null) {
            $data = isset($label['png']) && count($label['parts']) === 1
                ? (string) file_get_contents($label['png']['file'])
                : Png::blank(max(1, $label['width']), max(1, $tapePx));
            if (@file_put_contents($args['writepng'], $data) === false) {
                $this->say("writing image '{$args['writepng']}' failed");
                return 1;
            }
            $this->say(sprintf("printing '%s' requires %.1f mm of tape", $args['writepng'], $label['width'] / $dpi * 25.4));
            return 0;
        }

        if ($label['height'] > $tapePx) {
            $this->say(sprintf('image is too large (%dpx x %dpx)', $label['width'], $label['height']));
            $this->say(sprintf('maximum printing width for this tape is %dpx', $tapePx));
            return 0; // the real tool ignores print_img()'s result here
        }
        if ($this->state['fail'] === 'print') {
            $this->warn('write error: LIBUSB_ERROR_PIPE');
            $this->say('ptouch_finalize(0) failed');
            return 2;
        }
        if ($this->state['delay_ms'] > 0) {
            usleep($this->state['delay_ms'] * 1000);
        }
        for ($i = 0; $i < $args['copies']; $i++) {
            $this->say(sprintf('image size (%dpx x %dpx)', $label['width'], $label['height']));
            $this->store($label, $args, $i, $tapePx, $dpi);
        }
        if ($this->state['fail'] === 'jam' && $this->stateFile !== '') {
            $saved = json_decode((string) @file_get_contents($this->stateFile), true);
            $saved = is_array($saved) ? $saved : [];
            file_put_contents($this->stateFile, (string) json_encode(['error' => 0x0004, 'fail' => ''] + $saved));
        }
        return 0;
    }

    /** @return list<array<string, mixed>> */
    private function others(): array
    {
        $others = $this->state['others'];
        if (is_string($others)) {
            $others = json_decode($others, true);
        }
        return array_values(array_filter(is_array($others) ? $others : [], static function ($p): bool {
            return is_array($p) && isset($p['printer'], $p['serial']);
        }));
    }

    private function tapePx(): int
    {
        $mm = (int) $this->state['tape_mm'];
        if (!isset(self::TAPES[$mm])) {
            return 0;
        }
        [$maxPx, $dpi] = self::PRINTERS[$this->state['printer']];
        return min($maxPx, self::TAPES[$mm][$dpi === 360 ? 1 : 0]);
    }

    private function open(?string $serial): int
    {
        $printer = (string) $this->state['printer'];
        if ($printer === 'none' || !isset(self::PRINTERS[$printer])) {
            $this->warn('No P-Touch printer found on USB (remember to put switch to position E)');
            return 5;
        }
        if ($serial !== null && $serial !== $this->state['serial']) {
            $other = null;
            foreach ($this->others() as $candidate) {
                if ($candidate['serial'] === $serial) {
                    $other = $candidate;
                }
            }
            if ($other === null) {
                $this->warn("No P-Touch printer with serial $serial found");
                return 5;
            }
            $this->state = $other + $this->state;
            $printer = (string) $this->state['printer'];
        }
        if ($this->state['mode'] === 'access') {
            $this->warn('libusb_open error :LIBUSB_ERROR_ACCESS');
            $this->warn('No P-Touch printer found on USB (remember to put switch to position E)');
            return 5;
        }
        $this->warn(sprintf(
            '%s found on USB bus 1, device 4%s',
            $printer,
            $this->v('1.9') ? ', serial ' . $this->state['serial'] : ''
        ));
        if ($this->state['mode'] === 'plite') {
            $this->say('Printer is in P-Lite Mode, which is unsupported');
            $this->say('');
            $this->say('Turn off P-Lite mode by changing switch from position EL to position E');
            return 5;
        }
        if ($this->state['mode'] === 'unsupported') {
            $this->say('Unfortunately, that printer currently is unsupported (it has a different raster data transfer)');
            return 5;
        }
        return 0;
    }

    private function info(int $maxPx, int $dpi, int $tapePx): void
    {
        $s = $this->state;
        $mediaType = (int) ($s['tape_mm'] === 0 ? 0 : $s['media_type']);
        if ($this->v('1.9')) {
            $this->say("printer has $dpi dpi, maximum printing width is $maxPx px");
        } elseif ($this->v('1.7')) {
            $this->say("maximum printing width for this printer is {$maxPx}px");
        }
        $this->say("maximum printing width for this tape is {$tapePx}px");
        // 1.5: "01", 1.6/1.7: "01 (name)", 1.8+: "0x01 (name)"
        $hex = function (int $value, int $digits, string $name): string {
            $text = sprintf($this->v('1.8') ? "0x%0{$digits}x" : "%0{$digits}x", $value);
            return $name !== '' && $this->v('1.6') ? "$text ($name)" : $text;
        };
        $this->say('media type = ' . $hex($mediaType, 2, self::MEDIA_TYPES[$mediaType] ?? 'unknown'));
        $this->say(sprintf('media width = %d mm', $s['tape_mm']));
        $this->say('tape color = ' . $hex((int) $s['tape_color'], 2, self::TAPE_COLORS[$s['tape_color']] ?? 'unknown'));
        $this->say('text color = ' . $hex((int) $s['text_color'], 2, self::TEXT_COLORS[$s['text_color']] ?? 'unknown'));
        $this->say('error = ' . $hex((int) $s['error'], 4, ''));
    }

    /** @param array<string, mixed> $label */
    private function store(array $label, array $args, int $copy, int $tapePx, int $dpi): void
    {
        $dir = (string) $this->state['out'];
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $n = count(glob($dir . '/label-*.json') ?: []) + 1;
        $base = sprintf('%s/label-%04d', $dir, $n);
        if (isset($label['png'])) {
            copy($label['png']['file'], $base . '.png');
        }
        file_put_contents($base . '.json', json_encode([
            'n' => $n,
            'copy' => $copy + 1,
            'copies' => $args['copies'],
            'chain' => $args['chain'],
            'precut' => $args['precut'],
            'width_px' => $label['width'],
            'height_px' => $label['height'],
            'tape_px' => $tapePx,
            'dpi' => $dpi,
            'black_px' => $label['black'],
            'parts' => $label['parts'],
            'version' => $this->state['version'],
        ], JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private static function emptyArgs(): array
    {
        return [
            'version' => false, 'info' => false, 'list_supported' => false, 'list_connected' => false,
            'writepng' => null, 'force_tape_width' => 0, 'copies' => 1, 'chain' => false,
            'precut' => false, 'serial' => null, 'jobs' => [],
        ];
    }

    /**
     * ptouch-print 1.7+: GNU argp, options in any order, no positional arguments.
     *
     * @param list<string> $argv
     * @return array<string, mixed>|int
     */
    private function parseArgp(array $argv)
    {
        $a = self::emptyArgs();
        $withArg = ['font', 'fontsize', 'font-size', 'writepng', 'write-png', 'force-tape-width', 'copies',
            'image', 'text', 'pad', 'newline', 'align', 'timeout', 'serial', 'font-margin', 'fontmargin'];
        $flags = ['debug', 'cutmark', 'chain', 'precut', 'info', 'list-supported', 'list-connected', 'version'];
        $since = ['precut' => '1.8', 'align' => '1.8', 'timeout' => '1.8', 'serial' => '1.9',
            'font-margin' => '1.9', 'fontmargin' => '1.9', 'list-connected' => '1.9',
            'write-png' => '1.9', 'font-size' => '1.9'];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (strncmp($arg, '--', 2) !== 0) {
                $this->warn('ptouch-print: No arguments supported: Argument list too long');
                return 64;
            }
            $name = substr($arg, 2);
            $value = null;
            if (strpos($name, '=') !== false) {
                [$name, $value] = explode('=', $name, 2);
            }
            $known = in_array($name, $withArg, true) || in_array($name, $flags, true);
            if (!$known || (isset($since[$name]) && !$this->v($since[$name]))) {
                $this->warn("ptouch-print: unrecognized option '--$name'");
                $this->warn("Try `ptouch-print --help' or `ptouch-print --usage' for more information.");
                return 64;
            }
            if (in_array($name, $withArg, true) && $value === null) {
                if (!isset($argv[$i + 1])) {
                    $this->warn("ptouch-print: option '--$name' requires an argument");
                    return 64;
                }
                $value = $argv[++$i];
            }
            $this->apply($a, $name, $value);
        }
        if ($a['force_tape_width'] && $a['writepng'] === null) {
            $this->warn('ptouch-print: Option --writepng missing');
            return 1;
        }
        return $a;
    }

    /**
     * ptouch-print 1.5/1.6: hand-written parser; --text takes the following
     * non-option arguments as lines.
     *
     * @param list<string> $argv
     * @return array<string, mixed>|int
     */
    private function parseLegacy(array $argv)
    {
        $a = self::emptyArgs();
        $withArg = ['font', 'fontsize', 'writepng', 'image', 'pad'];
        if ($this->v('1.6')) {
            array_push($withArg, 'force-tape-width', 'copies');
        }
        $flags = ['debug', 'cutmark', 'info', 'list-supported', 'version'];
        if ($this->v('1.6')) {
            $flags[] = 'chain';
        }
        for ($i = 0; $i < count($argv); $i++) {
            $name = substr($argv[$i], 2);
            if (strncmp($argv[$i], '--', 2) === 0 && $name === 'text') {
                $lines = [];
                while (count($lines) < 4 && isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
                    $lines[] = $argv[++$i];
                }
                if ($lines) {
                    $a['jobs'][] = ['text', $lines];
                }
                continue;
            }
            if (strncmp($argv[$i], '--', 2) !== 0 || (!in_array($name, $withArg, true) && !in_array($name, $flags, true))) {
                $this->usage();
                return 1;
            }
            $value = null;
            if (in_array($name, $withArg, true)) {
                if (!isset($argv[$i + 1])) {
                    $this->usage();
                    return 1;
                }
                $value = $argv[++$i];
            }
            $this->apply($a, $name, $value);
        }
        return $a;
    }

    /** @param array<string, mixed> $a */
    private function apply(array &$a, string $name, ?string $value): void
    {
        switch ($name) {
            case 'version':
                $a['version'] = true;
                break;
            case 'info':
                $a['info'] = true;
                break;
            case 'list-supported':
                $a['list_supported'] = true;
                break;
            case 'list-connected':
                $a['list_connected'] = true;
                break;
            case 'writepng':
            case 'write-png':
                $a['writepng'] = $value;
                break;
            case 'force-tape-width':
                $a['force_tape_width'] = (int) $value;
                break;
            case 'copies':
                $a['copies'] = max(1, (int) $value);
                break;
            case 'chain':
                $a['chain'] = true;
                break;
            case 'precut':
                $a['precut'] = true;
                break;
            case 'serial':
                $a['serial'] = $value;
                break;
            case 'image':
                $a['jobs'][] = ['image', (string) $value];
                break;
            case 'text':
                $a['jobs'][] = ['text', [(string) $value]];
                break;
            case 'newline':
                $last = count($a['jobs']) - 1;
                if ($last >= 0 && $a['jobs'][$last][0] === 'text' && count($a['jobs'][$last][1]) < 4) {
                    $a['jobs'][$last][1][] = (string) $value;
                } else {
                    $a['jobs'][] = ['text', [(string) $value]];
                }
                break;
            case 'pad':
                $a['jobs'][] = ['pad', (string) $value];
                break;
            case 'cutmark':
                $a['jobs'][] = ['cutmark', null];
                break;
            default:
                // font, fontsize, align, timeout, debug: accepted, no effect in the simulator
                break;
        }
    }

    private function usage(): void
    {
        $this->say('usage: ptouch-print [options] <print-command(s)>');
    }
}
