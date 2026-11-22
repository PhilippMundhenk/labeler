<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Settings: built-in defaults, then an optional config.php that returns an array,
 * then LABELER_<KEY> environment variables (key upper-cased).
 */
final class Config
{
    public const VERSION = '1.0.0';

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
            // Printer driver: "ptouch" (Brother P-Touch printers through ptouch-print) or "ql"
            // (Brother QL printers on DK media); "ptouch,ql" for one printer of each kind.
            'printer_driver' => 'ptouch',
            // Several printers with names and their own settings or printer agents, see
            // config.example.php; empty: printer_url, else printer_driver.
            'printers' => [],
            // ql: the printer's device, e.g. /dev/usb/lp0. Empty: the first Brother QL found.
            'ql_device' => '',
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
            // Access token for this API. When set, every request except health needs
            // "Authorization: Bearer <token>". Always set it on a printer agent.
            'token' => '',
            // Printer agent to use instead of a local ptouch-print, e.g. http://printer-pi:8631,
            // and its token. Empty: print locally.
            'printer_url' => '',
            'printer_token' => '',
            // Password for the interface, plain text or a hash from PHP's password_hash()
            // (php -r 'echo password_hash("secret", PASSWORD_DEFAULT);'). Empty: no login.
            'password' => '',
            // Iconify API for the icon search (MDI, Simple Icons), queried by the browser.
            // Another Iconify server can be used; empty switches icons off.
            'icon_api' => 'https://api.iconify.design',
            // Without password and token, the API only answers for IP addresses, localhost and
            // local names (one word, .local, .lan, .home.arpa, .internal), against DNS
            // rebinding. Further host names, comma-separated, e.g. "labels.example.org"; "*" allows all.
            'allowed_hosts' => '',
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
        if (is_string($values['printers'])) {
            // LABELER_PRINTERS: the same list as JSON
            $decoded = json_decode($values['printers'], true);
            $values['printers'] = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($values['printers'])) {
            $values['printers'] = [];
        }
        foreach (['timeout', 'max_upload_bytes', 'max_copies', 'log_entries'] as $key) {
            $values[$key] = max(0, (int) $values[$key]);
        }
        foreach (['printer_driver', 'ql_device', 'ptouch_bin', 'serial', 'data_dir', 'fonts_dir', 'token', 'printer_url', 'printer_token', 'icon_api', 'allowed_hosts'] as $key) {
            $values[$key] = trim((string) $values[$key]);
        }
        $values['printer_driver'] = strtolower($values['printer_driver']) ?: 'ptouch';
        if (!preg_match('#^https?://[^\s"\'<>?\#]+$#', $values['printer_url'])) {
            $values['printer_url'] = '';
        }
        if (!preg_match('#^https?://[^\s"\'<>]+$#', $values['icon_api'])) {
            $values['icon_api'] = '';
        }
        return new self($values);
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->values[$key];
    }

    /**
     * The same settings with some values replaced (one printer's own settings).
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(array_merge($this->values, $values));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
