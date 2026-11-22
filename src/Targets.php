<?php

declare(strict_types=1);

namespace Labeler;

/**
 * The printers this interface prints on, each with an id the API's `printer=` names:
 *
 * - `printers` (config.php array, or JSON in LABELER_PRINTERS): one entry per printer with
 *   `id`, `name` and either `driver` plus its settings (`ptouch_bin`, `serial`, `ql_device`,
 *   `timeout`) or a printer agent (`url`, `token`, `printer` = the agent's printer id);
 * - else `printer_url`: one printer agent;
 * - else `printer_driver`, one driver or a comma-separated list (`ptouch,ql`): one printer per
 *   driver, named by it.
 */
final class Targets
{
    /** Settings an entry of `printers` may set for its driver. */
    private const OVERRIDES = ['ptouch_bin', 'serial', 'ql_device', 'timeout'];

    /** Default names of the printers made from `printer_driver`. */
    private const NAMES = ['ptouch' => 'P-touch', 'ql' => 'Brother QL'];

    /** @return list<Target> at least one */
    public static function fromConfig(Config $config): array
    {
        $dir = (string) $config->get('data_dir');
        $max = (int) $config->get('log_entries');
        $targets = [];
        $entries = $config->get('printers');
        foreach (is_array($entries) ? $entries : [] as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = self::id((string) ($entry['id'] ?? ''), $i + 1, $targets);
            $name = trim((string) ($entry['name'] ?? '')) ?: $id;
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url !== '') {
                $valid = preg_match('#^https?://[^\s"\'<>?\#]+$#', $url) === 1;
                $targets[$id] = new Target($id, $name, null, $valid ? new Remote($url, (string) ($entry['token'] ?? ''), (int) $config->get('timeout')) : null, (string) ($entry['printer'] ?? ''));
                if (!$valid) {
                    $targets[$id]->driver = new UnknownDriver('agent', [], "The printer {$id} has no valid url: set it to the agent's address, e.g. http://printer-pi:8631.");
                }
                continue;
            }
            $overrides = ['printer_driver' => strtolower(trim((string) ($entry['driver'] ?? 'ptouch')))];
            foreach (self::OVERRIDES as $key) {
                if (array_key_exists($key, $entry)) {
                    $overrides[$key] = $entry[$key];
                }
            }
            $targets[$id] = new Target($id, $name, Drivers::create($config->with($overrides), new Log($dir, $max, $id)));
        }
        if ($targets !== []) {
            return array_values($targets);
        }
        $url = (string) $config->get('printer_url');
        if ($url !== '') {
            return [new Target('agent', 'Printer agent', null, new Remote($url, (string) $config->get('printer_token'), (int) $config->get('timeout')))];
        }
        $drivers = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $config->get('printer_driver'))), 'strlen')));
        $single = count($drivers) <= 1;
        foreach ($drivers ?: ['ptouch'] as $driver) {
            // one printer: its log entries stay without a printer, as before
            $log = new Log($dir, $max, $single ? '' : $driver);
            $targets[] = new Target($driver, self::NAMES[$driver] ?? $driver, Drivers::create($config->with(['printer_driver' => $driver]), $log));
        }
        return $targets;
    }

    /** @param array<string, Target> $taken */
    private static function id(string $wanted, int $n, array $taken): string
    {
        $id = strtolower(trim($wanted));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
            $id = 'printer-' . $n;
        }
        $base = $id;
        for ($i = 2; isset($taken[$id]); $i++) {
            $id = $base . '-' . $i;
        }
        return $id;
    }
}
