<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Runs a program with an argument list (no shell involved), stdout and stderr merged.
 */
final class Process
{
    /**
     * @param list<string> $command program followed by its arguments
     * @return array{code: int, output: string, timed_out: bool, ms: int}
     */
    public static function run(array $command, int $timeoutSeconds): array
    {
        $started = microtime(true);
        $out = tmpfile();
        if ($out === false) {
            return ['code' => 127, 'output' => 'cannot create a temporary file', 'timed_out' => false, 'ms' => 0];
        }
        $descriptors = [0 => ['file', self::nullDevice(), 'r'], 1 => $out, 2 => $out];
        $proc = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            fclose($out);
            return ['code' => 127, 'output' => 'cannot start ' . $command[0], 'timed_out' => false, 'ms' => 0];
        }
        $timedOut = false;
        $code = -1;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if ($timeoutSeconds > 0 && microtime(true) - $started > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(10000);
        }
        $exit = proc_close($proc);
        if ($code === -1) {
            $code = $timedOut ? 124 : $exit;
        }
        rewind($out);
        $output = (string) stream_get_contents($out);
        fclose($out);
        if ($timedOut) {
            $output .= "\nstopped after {$timeoutSeconds} s";
        }
        // A program that cannot be found ends with 127 from the exec in the child.
        return [
            'code' => $code,
            'output' => $output,
            'timed_out' => $timedOut,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
