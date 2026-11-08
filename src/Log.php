<?php

declare(strict_types=1);

namespace Labeler;

/**
 * The last ptouch-print runs (arguments, exit code, output) as a JSON file, newest first.
 * Without a writable folder the log is silently off.
 */
final class Log
{
    private string $file;
    private int $max;

    public function __construct(string $dir, int $max = 50)
    {
        $this->file = $dir === '' ? '' : rtrim($dir, '/\\') . '/print-log.json';
        $this->max = $max;
    }

    public function enabled(): bool
    {
        if ($this->file === '' || $this->max === 0) {
            return false;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** @param list<string> $args */
    public function add(array $args, int $code, string $output, int $ms): void
    {
        if (!$this->enabled()) {
            return;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $entries = json_decode((string) stream_get_contents($handle), true);
        $entries = is_array($entries) ? $entries : [];
        $args = array_map(static function (string $arg): string {
            // temporary file names carry no information
            return preg_match('/\.png$/i', $arg) ? 'label.png' : $arg;
        }, $args);
        array_unshift($entries, [
            'time' => gmdate('c'),
            'args' => $args,
            'code' => $code,
            'ms' => $ms,
            'output' => substr(trim($output), 0, 4000),
        ]);
        $entries = array_slice($entries, 0, $this->max);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        if ($this->file === '' || !is_file($this->file)) {
            return [];
        }
        $entries = json_decode((string) file_get_contents($this->file), true);
        return is_array($entries) ? $entries : [];
    }
}
