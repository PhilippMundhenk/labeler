<?php

declare(strict_types=1);

namespace Labeler;

use InvalidArgumentException;

/**
 * JSON API behind public/api.php. Independent of PHP's request globals so it can be tested.
 */
final class Api
{
    private const FONT_TYPES = [
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    private Config $config;
    private PtouchPrint $printer;
    private Log $log;

    public function __construct(Config $config, ?PtouchPrint $printer = null, ?Log $log = null)
    {
        $this->config = $config;
        $this->log = $log ?? new Log((string) $config->get('data_dir'), (int) $config->get('log_entries'));
        $this->printer = $printer ?? PtouchPrint::fromConfig($config, $this->log);
    }

    /**
     * @param array<string, string> $query
     * @return Response
     */
    public function handle(string $method, array $query, string $body, string $contentType): Response
    {
        $action = $query['action'] ?? '';
        $routes = [
            'health' => ['GET', 'health'],
            'status' => ['GET', 'status'],
            'print' => ['POST', 'print'],
            'fonts' => ['GET', 'fonts'],
            'font' => ['GET', 'font'],
            'log' => ['GET', 'log'],
        ];
        if (!isset($routes[$action])) {
            return Response::json(404, ['error' => 'unknown action']);
        }
        [$allowed, $handler] = $routes[$action];
        if ($method !== $allowed && !($method === 'HEAD' && $allowed === 'GET')) {
            return Response::json(405, ['error' => "use $allowed"], ['Allow' => $allowed]);
        }
        if ($handler === 'print') {
            return $this->print($body, $contentType);
        }
        if ($handler === 'font') {
            return $this->font($query['file'] ?? '');
        }
        return $this->$handler();
    }

    private function health(): Response
    {
        return Response::json(200, ['ok' => true, 'version' => Config::VERSION]);
    }

    private function status(): Response
    {
        $version = $this->printer->version();
        $info = $version === null
            ? PtouchPrint::parseInfo('', 127)
            : $this->printer->info();
        return Response::json(200, [
            'app_version' => Config::VERSION,
            'ptouch_version' => $version,
            'features' => $this->printer->features(),
            'max_copies' => (int) $this->config->get('max_copies'),
            'icon_api' => (string) $this->config->get('icon_api'),
            'printer' => $info,
        ]);
    }

    private function print(string $body, string $contentType): Response
    {
        // A JSON content type cannot be sent cross-site without a CORS preflight,
        // which this API never answers: other web pages cannot print.
        if (stripos($contentType, 'application/json') !== 0) {
            return Response::json(415, ['error' => 'send application/json']);
        }
        $maxBytes = (int) $this->config->get('max_upload_bytes');
        if (strlen($body) > $maxBytes * 4 / 3 + 1000) {
            return Response::json(413, ['error' => 'the label is too large']);
        }
        $request = json_decode($body, true);
        if (!is_array($request) || !isset($request['png']) || !is_string($request['png'])) {
            return Response::json(400, ['error' => 'expected {"png": "<base64>"}']);
        }
        $png = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $request['png']), true);
        if ($png === false || $png === '') {
            return Response::json(400, ['error' => 'png is not valid base64']);
        }
        if (strlen($png) > $maxBytes) {
            return Response::json(413, ['error' => 'the label is too large']);
        }
        $copies = (int) ($request['copies'] ?? 1);
        $maxCopies = max(1, (int) $this->config->get('max_copies'));
        if ($copies < 1 || $copies > $maxCopies) {
            return Response::json(400, ['error' => "copies must be between 1 and $maxCopies"]);
        }

        $info = $this->printer->info();
        if (!$info['ready']) {
            return Response::json(503, ['error' => $info['message'], 'printer' => $info]);
        }
        try {
            $size = Png::inspect($png, (int) $info['tape_px']);
        } catch (InvalidArgumentException $e) {
            return Response::json(422, ['error' => $e->getMessage()]);
        }

        $base = tempnam(sys_get_temp_dir(), 'labeler');
        $file = $base === false ? false : $base . '.png';
        if ($file === false || !rename($base, $file) || file_put_contents($file, $png) === false) {
            return Response::json(500, ['error' => 'cannot write a temporary file']);
        }
        try {
            $result = $this->printer->printImage(
                $file,
                $copies,
                !empty($request['chain']),
                !empty($request['precut'])
            );
        } finally {
            @unlink($file);
        }
        // ptouch-print does not notice errors during printing (exit code 0 after a cutter jam
        // or an opened cover), so ask the printer once more
        if ($result['ok']) {
            $after = $this->printer->info();
            if (!$after['ready']) {
                $result['ok'] = false;
                $result['output'] = trim($result['output'] . "
" . $after['message']);
                $result['problem'] = 'After printing, the printer reports: ' . $after['message'];
            }
        }
        $lengthMm = round($size['width'] / max(1, (int) $info['dpi']) * 25.4, 1);
        return Response::json($result['ok'] ? 200 : 502, [
            'ok' => $result['ok'],
            'error' => $result['ok'] ? null : ($result['problem'] ?? 'ptouch-print reported a problem'),
            'output' => $result['output'],
            'notes' => $result['notes'],
            'width_px' => $size['width'],
            'height_px' => $size['height'],
            'length_mm' => $lengthMm,
            'copies' => $copies,
        ]);
    }

    /** @return list<array{name: string, file: string}> */
    public function fontList(): array
    {
        $dir = (string) $this->config->get('fonts_dir');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $fonts = [];
        foreach ((array) scandir($dir) as $file) {
            $file = (string) $file;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($file[0] === '.' || !isset(self::FONT_TYPES[$ext])) {
                continue;
            }
            $name = trim((string) preg_replace('/[-_]+/', ' ', pathinfo($file, PATHINFO_FILENAME)));
            $fonts[] = ['name' => $name, 'file' => $file];
        }
        usort($fonts, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        return $fonts;
    }

    private function fonts(): Response
    {
        return Response::json(200, ['fonts' => $this->fontList()]);
    }

    private function font(string $file): Response
    {
        foreach ($this->fontList() as $font) {
            if ($font['file'] === $file) {
                $path = rtrim((string) $this->config->get('fonts_dir'), '/\\') . '/' . $file;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return new Response(200, (string) file_get_contents($path), [
                    'Content-Type' => self::FONT_TYPES[$ext],
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }
        return Response::json(404, ['error' => 'no such font']);
    }

    private function log(): Response
    {
        return Response::json(200, ['enabled' => $this->log->enabled(), 'entries' => $this->log->entries()]);
    }
}
