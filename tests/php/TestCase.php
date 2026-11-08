<?php

declare(strict_types=1);

namespace Labeler\Tests;

use PHPUnit\Framework\TestCase as Base;

abstract class TestCase extends Base
{
    protected string $tmp = '';
    /** @var list<string> */
    private array $envSet = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/labeler-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->envSet as $name) {
            putenv($name);
        }
        $this->envSet = [];
        self::remove($this->tmp);
    }

    protected static function simBin(): string
    {
        return dirname(__DIR__, 2) . '/tools/ptouch-sim/ptouch-print';
    }

    /** Simulator state for the subprocesses started by the code under test. */
    protected function simulate(array $state): void
    {
        $state += ['out' => $this->tmp . '/printed'];
        foreach ($state as $key => $value) {
            $name = 'PTSIM_' . strtoupper($key);
            putenv($name . '=' . $value);
            $this->envSet[] = $name;
        }
    }

    /** @return list<array<string, mixed>> */
    protected function printed(): array
    {
        $records = [];
        foreach (glob($this->tmp . '/printed/label-*.json') ?: [] as $file) {
            $records[] = json_decode((string) file_get_contents($file), true);
        }
        return $records;
    }

    /** A 1-bit palette PNG like the browser produces. */
    protected static function labelPng(int $width, int $height, bool $ink = true): string
    {
        $stride = (int) ceil($width / 8);
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . str_repeat($ink ? "\xaa" : "\0", $stride);
        }
        return self::pngChunks(pack('NNCCCCC', $width, $height, 1, 3, 0, 0, 0), "\xff\xff\xff\0\0\0", $raw);
    }

    protected static function pngChunks(string $ihdr, ?string $plte, string $raw): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr)
            . ($plte === null ? '' : $chunk('PLTE', $plte))
            . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @chmod($path, 0777);
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
