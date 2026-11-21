<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * The `ql` driver: Brother QL printers on DK media, spoken to directly in Brother's raster
 * protocol (QlRaster) through the kernel's USB printer device, /dev/usb/lp*. Plain PHP, nothing
 * else is installed. A Unix socket works too, which is how the simulator (tools/ql-sim) is used.
 */
final class QlPrint implements Driver
{
    public const FEATURES = [
        'copies' => true,
        'chain' => true,
        'precut' => false,
        'serial' => true,
        'feed' => false,
        'halfcut' => false,
        'cut_every' => true,
        'hires' => false,
    ];

    private string $device;
    private int $timeout;
    private string $serial;
    private ?Log $log;
    private string $devices;
    private string $sysfs;

    /**
     * @param string $device printer device or Unix socket; empty: the first Brother QL in $devices
     * @param string $devices glob pattern of the USB printer devices
     * @param string $sysfs where the kernel describes them (ieee1284_id, serial)
     */
    public function __construct(string $device = '', int $timeout = 60, string $serial = '', ?Log $log = null, string $devices = '/dev/usb/lp*', string $sysfs = '/sys/class/usbmisc')
    {
        $this->device = $device;
        $this->timeout = max(1, $timeout);
        $this->serial = $serial;
        $this->log = $log;
        $this->devices = $devices;
        $this->sysfs = $sysfs;
    }

    public static function fromConfig(Config $config, ?Log $log = null): self
    {
        return new self((string) $config->get('ql_device'), (int) $config->get('timeout'), (string) $config->get('serial'), $log);
    }

    public function name(): string
    {
        return 'ql';
    }

    /** The driver is part of Labeler. */
    public function version(): ?string
    {
        return Config::VERSION;
    }

    public function supports(string $feature): bool
    {
        return self::FEATURES[$feature] ?? false;
    }

    public function features(): array
    {
        return self::FEATURES;
    }

    public function offlineFeatures(): array
    {
        return array_fill_keys(array_keys(self::FEATURES), false);
    }

    public function withSerial(string $serial): self
    {
        if ($serial === '' || $serial === $this->serial) {
            return $this;
        }
        $copy = clone $this;
        $copy->serial = $serial;
        return $copy;
    }

    public function offline(string $message): array
    {
        return QlRaster::blankInfo($message);
    }

    /**
     * Brother QL printers the kernel knows. With a configured device, only that one.
     *
     * @return list<array{path: string, model: ?string, serial: ?string}>
     */
    public function devices(): array
    {
        if ($this->device !== '') {
            return [['path' => $this->device] + $this->describe($this->device)];
        }
        $found = [];
        foreach (glob($this->devices) ?: [] as $path) {
            $description = $this->describe($path);
            if ($description['model'] !== null) {
                $found[] = ['path' => $path] + $description;
            }
        }
        return $found;
    }

    /** @return array{model: ?string, serial: ?string} model null when it is no Brother QL */
    private function describe(string $path): array
    {
        $dir = $this->sysfs . '/' . basename($path) . '/device';
        $id = (string) @file_get_contents($dir . '/ieee1284_id');
        $model = preg_match('/(?:MFG|MANUFACTURER):Brother;/i', $id) && preg_match('/(?:MDL|MODEL):(QL-[^;]+);/i', $id, $m)
            ? trim($m[1])
            : null;
        $serial = trim((string) @file_get_contents($dir . '/../serial'));
        return ['model' => $model, 'serial' => $serial !== '' ? $serial : null];
    }

    public function connected(): array
    {
        $printers = [];
        foreach ($this->devices() as $device) {
            $model = $device['model'];
            if ($model === null) {
                $status = $this->ask($device['path']);
                $model = $status === null ? null : QlRaster::modelName($status['model_code']);
            }
            $printers[] = ['model' => $model ?? 'Brother QL', 'serial' => (string) $device['serial'], 'usable' => self::writable($device['path'])];
        }
        return $printers;
    }

    public function info(): array
    {
        $device = $this->pick();
        if (is_string($device)) {
            return QlRaster::blankInfo($device);
        }
        $status = $this->ask($device['path'], $error, $device['model']);
        if ($status === null) {
            $info = QlRaster::blankInfo($error);
            // the device is there, the printer just cannot be asked
            $info['connected'] = true;
            $info['model'] = $device['model'];
            $info['serial'] = $device['serial'];
            return $info;
        }
        return QlRaster::info($status, $device['model'], $device['serial']);
    }

    /** @return array{path: string, model: ?string, serial: ?string}|string the device, or why there is none */
    private function pick()
    {
        $devices = $this->devices();
        if ($devices === []) {
            return 'No Brother QL printer found. Check the USB cable and switch the printer on.';
        }
        if ($this->serial === '') {
            return $devices[0];
        }
        foreach ($devices as $device) {
            if ($device['serial'] === $this->serial) {
                return $device;
            }
        }
        return "No Brother QL printer with serial {$this->serial} found.";
    }

    /**
     * Asks the printer for its status.
     *
     * @param string|null $error set to why there is no answer
     * @return array{type: int, phase: int, model_code: int, error_code: int, media_type: int,
     *         media_width_mm: int, media_length_mm: int}|null
     */
    private function ask(string $path, ?string &$error = null, ?string $model = null): ?array
    {
        $error = '';
        $handle = $this->open($path, $error);
        if ($handle === null) {
            return null;
        }
        try {
            $answer = $this->exchange($handle, QlRaster::statusRequest($model), 32, min(5, $this->timeout));
        } finally {
            fclose($handle);
        }
        $status = QlRaster::parseStatus($answer);
        if ($status === null) {
            $error = 'The printer does not answer. Switch it off and on again.';
        }
        return $status;
    }

    /** @return resource|null */
    private function open(string $path, string &$error)
    {
        $message = '';
        set_error_handler(static function (int $no, string $text) use (&$message): bool {
            $message = preg_replace('/^.*?: /', '', $text) ?? $text;
            return true;
        });
        try {
            $handle = @filetype(realpath($path) ?: $path) === 'socket'
                ? stream_socket_client('unix://' . $path, $code, $text, $this->timeout)
                : fopen($path, 'r+b');
        } finally {
            restore_error_handler();
        }
        if ($handle === false) {
            $error = file_exists($path) && !self::writable($path)
                ? self::accessMessage()
                : "The printer cannot be opened: $message.";
            return null;
        }
        stream_set_blocking($handle, false);
        return $handle;
    }

    private static function writable(string $path): bool
    {
        return is_writable($path);
    }

    private static function accessMessage(): string
    {
        return 'A printer is attached, but the web server may not use it. Install the udev rule (see docs/INSTALL.md).';
    }

    /**
     * Writes $bytes, then reads until $want bytes arrived or $seconds passed.
     *
     * @param resource $handle
     */
    private function exchange($handle, string $bytes, int $want, int $seconds): string
    {
        $deadline = microtime(true) + $seconds;
        while ($bytes !== '' && microtime(true) < $deadline) {
            $written = @fwrite($handle, $bytes);
            if ($written === false) {
                return '';
            }
            $bytes = (string) substr($bytes, $written);
            if ($written === 0) {
                usleep(5000);
            }
        }
        $answer = '';
        while (strlen($answer) < $want && microtime(true) < $deadline) {
            $chunk = (string) fread($handle, $want - strlen($answer));
            if ($chunk === '') {
                usleep(5000);
                continue;
            }
            $answer .= $chunk;
        }
        return $answer;
    }

    public function printImage(string $png, int $copies = 1, bool $chain = false, bool $precut = false, array $extra = []): array
    {
        $notes = [];
        foreach (['precut' => $precut, 'halfcut' => !empty($extra['halfcut']), 'hires' => !empty($extra['hires']), 'feed' => isset($extra['feed'])] as $option => $wanted) {
            if ($wanted) {
                $notes[] = "$option is not available on Brother QL printers";
            }
        }
        $started = microtime(true);
        $info = $this->info();
        if (!$info['ready'] || !is_array($info['media'])) {
            return ['ok' => false, 'output' => (string) $info['message'], 'notes' => $notes];
        }
        try {
            $job = QlRaster::job(Png::rows((string) @file_get_contents($png)), (string) $info['media']['id'], [
                'copies' => $copies,
                'cut_every' => isset($extra['cut_every']) && $copies > 1 ? (int) $extra['cut_every'] : 1,
                'cut_at_end' => !$chain,
                'model' => $info['model'],
            ]);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'output' => $e->getMessage(), 'notes' => $notes];
        }
        $device = $this->pick();
        assert(is_array($device));
        $error = '';
        $handle = $this->open($device['path'], $error);
        if ($handle === null) {
            return ['ok' => false, 'output' => $error, 'notes' => $notes];
        }
        try {
            [$ok, $output] = $this->send($handle, $job, $copies);
        } finally {
            fclose($handle);
        }
        $args = ['ql', '--media', (string) $info['media']['id'], '--copies', (string) $copies];
        if ($chain) {
            $args[] = '--chain';
        }
        if ($this->log !== null) {
            $this->log->add($args, $ok ? 0 : 1, $output, (int) round((microtime(true) - $started) * 1000));
        }
        return ['ok' => $ok, 'output' => $output, 'notes' => $notes];
    }

    /**
     * Sends a job and waits until every page is reported printed, or an error.
     *
     * @param resource $handle
     * @return array{0: bool, 1: string}
     */
    private function send($handle, string $job, int $pages): array
    {
        $deadline = microtime(true) + $this->timeout;
        $answer = $this->exchange($handle, $job, 32, $this->timeout);
        $printed = 0;
        while (true) {
            $status = QlRaster::parseStatus($answer);
            if ($status === null) {
                return [false, 'The printer did not confirm the label.'];
            }
            if ($status['type'] === QlRaster::STATUS_ERROR || $status['error_code'] !== 0) {
                return [false, implode(' ', QlRaster::decodeErrors($status['error_code'])) ?: 'The printer reports an error.'];
            }
            if ($status['type'] === QlRaster::STATUS_PRINTED && ++$printed >= $pages) {
                return [true, $pages === 1 ? '1 label printed' : "$pages labels printed"];
            }
            $answer = $this->exchange($handle, '', 32, (int) max(1, ceil($deadline - microtime(true))));
        }
    }
}
