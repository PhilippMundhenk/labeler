<?php

declare(strict_types=1);

namespace Labeler\Sim;

use Labeler\QlRaster;

/**
 * A simulated Brother QL printer: reads the raster protocol the way the printer does, answers
 * status requests and records every printed page. Fed by tools/ql-sim/ql-printer (a Unix
 * socket in place of /dev/usb/lp0) and directly by the unit tests.
 */
final class QlSimulator
{
    /** @var array<string, mixed> */
    private array $state;
    private string $stateFile;
    private string $buffer = '';
    /** @var array<string, mixed> settings of the page being received */
    private array $page = [];
    /** @var list<string> */
    private array $lines = [];
    private bool $raster = false;
    private bool $broken = false;
    private int $pageCount = 0;

    /** @param array<string, mixed> $state */
    public function __construct(array $state, string $stateFile = '')
    {
        $this->state = $state;
        $this->stateFile = $stateFile;
        $this->raster = $this->alwaysRaster();
    }

    /** Models without ESC i a are always in raster mode. */
    private function alwaysRaster(): bool
    {
        return !QlRaster::model((string) $this->state()['printer'])[3];
    }

    /**
     * @param array<string, string|false> $env
     * @return array<string, mixed>
     */
    public static function stateFromEnv(array $env): array
    {
        $get = static function (string $key, string $default) use ($env): string {
            $value = $env['QLSIM_' . $key] ?? false;
            return $value === false || $value === '' ? $default : (string) $value;
        };
        return [
            'printer' => $get('PRINTER', 'QL-570'),
            'serial' => $get('SERIAL', '000G0Z123456'),
            'media' => $get('MEDIA', '62'),
            'error' => (int) $get('ERROR', '0'),
            'fail' => $get('FAIL', ''),
            'delay_ms' => (int) $get('DELAY_MS', '0'),
            'out' => $get('OUT', ''),
        ];
    }

    /** @return array<string, mixed> the state, with the state file read again */
    public function state(): array
    {
        if ($this->stateFile !== '' && is_file($this->stateFile)) {
            $override = json_decode((string) file_get_contents($this->stateFile), true);
            if (is_array($override)) {
                return array_merge($this->state, $override);
            }
        }
        return $this->state;
    }

    /** The status answer for the current state. */
    public function status(int $type = QlRaster::STATUS_REPLY, int $phase = 0, int $error = -1): string
    {
        $state = $this->state();
        $code = QlRaster::model((string) $state['printer'])[0];
        $media = QlRaster::MEDIA[(string) $state['media']] ?? null;
        $error = $error >= 0 ? $error : (int) $state['error'];
        if ($media === null) {
            return QlRaster::status($code, 0, 0, 0, $error | 0x0001, $type, $phase);
        }
        $kind = $media[1] > 0 ? QlRaster::DIE_CUT : QlRaster::CONTINUOUS;
        return QlRaster::status($code, $kind, $media[0], $media[1], $error, $type, $phase);
    }

    /**
     * Takes the next bytes from the host and returns what the printer sends back.
     */
    public function feed(string $bytes): string
    {
        $this->buffer .= $bytes;
        $reply = '';
        while ($this->buffer !== '' && !$this->broken) {
            $used = $this->command($this->buffer, $reply);
            if ($used === 0) {
                break; // incomplete, wait for more
            }
            $this->buffer = (string) substr($this->buffer, $used);
        }
        if ($this->broken) {
            $this->buffer = '';
        }
        return $reply;
    }

    /** @return int bytes used, 0 when the command is not complete */
    private function command(string $b, string &$reply): int
    {
        $first = $b[0];
        if ($first === "\x00") {
            return strspn($b, "\x00");
        }
        if ($first === 'g' || $first === 'G') {
            if (strlen($b) < 3) {
                return 0;
            }
            $n = ord($b[2]) | (ord($b[1]) << 8);
            if (strlen($b) < 3 + $n) {
                return 0;
            }
            $this->line(substr($b, 3, $n), $reply);
            return 3 + $n;
        }
        if ($first === 'Z') {
            $this->line(str_repeat("\x00", $this->lineBytes()), $reply);
            return 1;
        }
        if ($first === 'M') {
            if (strlen($b) < 2) {
                return 0;
            }
            $this->page['compression'] = ord($b[1]);
            return 2;
        }
        if ($first === "\x0c" || $first === "\x1a") {
            $this->printPage($first === "\x1a", $reply);
            return 1;
        }
        if ($first !== "\x1b") {
            return $this->fail(0x0400, $reply);
        }
        if (strlen($b) < 2) {
            return 0;
        }
        if ($b[1] === '@') {
            $this->page = [];
            $this->lines = [];
            $this->raster = $this->alwaysRaster();
            return 2;
        }
        if ($b[1] !== 'i' || strlen($b) < 3) {
            return $b[1] === 'i' ? 0 : $this->fail(0x0400, $reply);
        }
        // ESC i <letter> <arguments>
        $sizes = ['S' => 0, 'a' => 1, 'z' => 10, 'M' => 1, 'A' => 1, 'K' => 1, 'd' => 2];
        $letter = $b[2];
        if (!isset($sizes[$letter])) {
            return $this->fail(0x0400, $reply);
        }
        if (strlen($b) < 3 + $sizes[$letter]) {
            return 0;
        }
        $args = array_values(unpack('C*', substr($b, 3, $sizes[$letter]) . "\x00") ?: []);
        if ($letter === 'S') {
            $reply .= $this->status();
        } elseif ($letter === 'a') {
            $this->raster = $args[0] === 1;
        } elseif ($letter === 'z') {
            $this->mediaInfo($args, $reply);
        } elseif ($letter === 'M') {
            $this->page['autocut'] = ($args[0] & 0x40) !== 0;
        } elseif ($letter === 'A') {
            $this->page['cut_every'] = $args[0];
        } elseif ($letter === 'K') {
            $this->page['cut_at_end'] = ($args[0] & 0x08) !== 0;
            $this->page['high_resolution'] = ($args[0] & 0x40) !== 0;
        } else {
            $this->page['margin'] = $args[0] | ($args[1] << 8);
        }
        return 3 + $sizes[$letter];
    }

    /** @param list<int> $a */
    private function mediaInfo(array $a, string &$reply): void
    {
        $this->page['flags'] = $a[0];
        $this->page['media_type'] = $a[1];
        $this->page['width_mm'] = $a[2];
        $this->page['length_mm'] = $a[3];
        $this->page['lines_declared'] = $a[4] | ($a[5] << 8) | ($a[6] << 16) | ($a[7] << 24);
        $this->page['starting_page'] = $a[8] === 0;
        $this->page['quality'] = ($a[0] & 0x40) !== 0;
        $media = QlRaster::MEDIA[(string) $this->state()['media']] ?? null;
        $kind = $media !== null && $media[1] > 0 ? QlRaster::DIE_CUT : QlRaster::CONTINUOUS;
        $wrong = $media === null
            || (($a[0] & 0x02) && $a[1] !== $kind)
            || (($a[0] & 0x04) && $a[2] !== $media[0])
            || (($a[0] & 0x08) && $kind === QlRaster::DIE_CUT && $a[3] !== $media[1]);
        if ($wrong) {
            $this->fail($media === null ? 0x0001 : 0x0100, $reply);
        }
    }

    private function line(string $data, string &$reply): void
    {
        if (strlen($data) !== $this->lineBytes() || !$this->raster) {
            $this->fail(0x0400, $reply);
            return;
        }
        $this->lines[] = $data;
    }

    private function lineBytes(): int
    {
        return QlRaster::lineBytes((string) $this->state()['printer']);
    }

    private function printPage(bool $last, string &$reply): void
    {
        $state = $this->state();
        if (!isset($this->page['lines_declared']) || $this->page['lines_declared'] !== count($this->lines)) {
            $this->fail(0x0400, $reply);
            return;
        }
        if ((int) $state['error'] !== 0) {
            $this->fail((int) $state['error'], $reply);
            return;
        }
        $reply .= $this->status(QlRaster::STATUS_PHASE, 1);
        usleep(max(0, (int) $state['delay_ms']) * 1000);
        if ($state['fail'] === 'jam') {
            $this->fail(0x0004, $reply);
            return;
        }
        $this->record($last);
        $this->lines = [];
        $reply .= $this->status(QlRaster::STATUS_PRINTED, 0);
        if ($last) {
            $reply .= $this->status(QlRaster::STATUS_PHASE, 0);
        }
    }

    private function fail(int $error, string &$reply): int
    {
        $reply .= $this->status(QlRaster::STATUS_ERROR, 0, $error);
        $this->broken = true;
        return 1;
    }

    /**
     * The printed page back in Labeler's orientation: rows across the printable width, columns
     * along the label. Dots outside the printable width are counted, not drawn.
     *
     * @return array{rows: list<string>, outside: int}
     */
    public function image(): array
    {
        $media = QlRaster::MEDIA[(string) $this->state()['media']];
        [, , $across, , $margin] = $media;
        $length = count($this->lines);
        $rows = array_fill(0, $across, str_repeat('0', $length));
        $outside = 0;
        foreach ($this->lines as $k => $line) {
            $x = $length - 1 - $k;
            for ($dot = 0; $dot < strlen($line) * 8; $dot++) {
                if ((ord($line[$dot >> 3]) & (0x80 >> ($dot & 7))) === 0) {
                    continue;
                }
                $y = $across - 1 - ($dot - $margin);
                if ($y < 0 || $y >= $across) {
                    $outside++;
                    continue;
                }
                $rows[$y][$x] = '1';
            }
        }
        return ['rows' => $rows, 'outside' => $outside];
    }

    private function record(bool $last): void
    {
        $state = $this->state();
        $image = $this->image();
        $this->pageCount++;
        $out = (string) $state['out'];
        if ($out === '') {
            return;
        }
        if (!is_dir($out)) {
            mkdir($out, 0777, true);
        }
        $n = count(glob($out . '/label-*.json') ?: []) + 1;
        $base = sprintf('%s/label-%04d', $out, $n);
        file_put_contents($base . '.png', Png::fromRows($image['rows']));
        $black = 0;
        foreach ($image['rows'] as $row) {
            $black += substr_count($row, '1');
        }
        file_put_contents($base . '.json', (string) json_encode([
            'printer' => $state['printer'],
            'media' => $state['media'],
            'width' => count($this->lines),
            'height' => count($image['rows']),
            'black' => $black,
            'outside' => $image['outside'],
            'page' => $this->pageCount,
            'last' => $last,
        ] + $this->page, JSON_PRETTY_PRINT));
    }
}
