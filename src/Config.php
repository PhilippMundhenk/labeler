<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Settings: built-in defaults, then an optional config.php that returns an array,
 * then LABELER_<KEY> environment variables (key upper-cased).
 */
final class Config
{
    public const VERSION = '1.0.0-dev';

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array<string, mixed> */
    public static function defaults(string $root): array
    {
        return [
            // ptouch-print executable: a name on PATH or an absolute path.
            'ptouch_bin' => 'ptouch-print',
            // Seconds a single ptouch-print run may take before it is stopped.
            'timeout' => 60,
            // USB serial of the printer to use (ptouch-print 1.9+), empty = first found.
            'serial' => '',
            // Writable folder for the print log. Empty disables the log.
            'data_dir' => $root . '/data',
            // Folder with .ttf/.otf/.woff/.woff2 files offered in the font list.
            'fonts_dir' => $root . '/fonts',
            // Largest accepted label PNG in bytes.
            'max_upload_bytes' => 2000000,
            // Most copies per print request.
            'max_copies' => 50,
            // Entries kept in the print log.
            'log_entries' => 50,
        ];
    }

    /**
     * @param array<string, string|false>|null $env environment, defaults to getenv()
     */
    public static function load(string $root, ?string $file = null, ?array $env = null): self
    {
        $values = self::defaults($root);
        $file = $file ?? $root . '/config.php';
        if (is_file($file)) {
            $fromFile = include $file;
            if (is_array($fromFile)) {
                foreach ($fromFile as $key => $value) {
                    if (array_key_exists($key, $values)) {
                        $values[$key] = $value;
                    }
                }
            }
        }
        $env = $env ?? getenv();
        foreach (array_keys($values) as $key) {
            $name = 'LABELER_' . strtoupper($key);
            if (isset($env[$name]) && $env[$name] !== false) {
                $values[$key] = $env[$name];
            }
        }
        foreach (['timeout', 'max_upload_bytes', 'max_copies', 'log_entries'] as $key) {
            $values[$key] = max(0, (int) $values[$key]);
        }
        foreach (['ptouch_bin', 'serial', 'data_dir', 'fonts_dir'] as $key) {
            $values[$key] = trim((string) $values[$key]);
        }
        return new self($values);
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
