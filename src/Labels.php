<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * Saved labels shared by all browsers: one JSON file in the data folder.
 */
final class Labels
{
    public const MAX_LABELS = 200;
    public const MAX_BYTES = 1000000; // one label, images included
    // all labels together: every request reads the whole file, a Raspberry Pi Zero has 512 MB
    public const MAX_TOTAL_BYTES = 20000000;

    private string $file;

    public function __construct(string $dir)
    {
        $this->file = $dir === '' ? '' : rtrim($dir, '/\\') . '/labels.json';
    }

    public function enabled(): bool
    {
        if ($this->file === '') {
            return false;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** @return array<string, array{label: array<string, mixed>, updated: string}> */
    public function all(): array
    {
        if ($this->file === '' || !is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $label
     * @throws InvalidArgumentException
     */
    public function save(string $name, $label): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new InvalidArgumentException('the name must have 1 to 80 characters');
        }
        if (!is_array($label) || !isset($label['blocks']) || !is_array($label['blocks'])) {
            throw new InvalidArgumentException('not a label');
        }
        if (strlen((string) json_encode($label)) > self::MAX_BYTES) {
            throw new InvalidArgumentException('the label is too large (big images?)');
        }
        $this->change(static function (array $labels) use ($name, $label): array {
            if (!isset($labels[$name]) && count($labels) >= self::MAX_LABELS) {
                throw new InvalidArgumentException('too many saved labels; delete some first');
            }
            $labels[$name] = ['label' => $label, 'updated' => gmdate('c')];
            if (strlen((string) json_encode($labels)) > self::MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('the saved labels are too large together (big images?); delete some first');
            }
            return $labels;
        });
    }

    public function delete(string $name): void
    {
        $this->change(static function (array $labels) use ($name): array {
            unset($labels[$name]);
            return $labels;
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function change(callable $change): void
    {
        if (!$this->enabled()) {
            throw new InvalidArgumentException('saved labels on the server are not available (data folder not writable)');
        }
        // a separate lock file, and the new contents written to a temporary file that then
        // replaces labels.json: a crash while writing never leaves a half-written file
        $handle = fopen($this->file . '.lock', 'c');
        if ($handle === false) {
            throw new InvalidArgumentException('cannot open the label file');
        }
        flock($handle, LOCK_EX);
        try {
            $labels = $change($this->all());
            ksort($labels, SORT_NATURAL | SORT_FLAG_CASE);
            $temp = $this->file . '.tmp';
            if (@file_put_contents($temp, (string) json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false
                || !@rename($temp, $this->file)) {
                @unlink($temp);
                throw new InvalidArgumentException('cannot write the label file');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
