<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Static files for PHP's built-in server (src/router.php), with what the built-in server leaves
 * out: ETag and Last-Modified so browsers revalidate with a cheap 304, and gzip for text files.
 */
final class StaticFiles
{
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'webmanifest' => 'application/manifest+json',
        'json' => 'application/json',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    /**
     * @param array<string, string> $requestHeaders lower-case names
     * @return Response|null null when the path is not a static file here
     */
    public function serve(string $path, array $requestHeaders = []): ?Response
    {
        $path = '/' . ltrim(rawurldecode((string) parse_url($path, PHP_URL_PATH)), '/');
        if (substr($path, -1) === '/') {
            $path .= 'index.html';
        }
        if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            return null;
        }
        $file = $this->root . $path;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext]) || !is_file($file)) {
            return null;
        }
        $mtime = (int) filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($file)) . '"';
        $headers = [
            'Content-Type' => self::TYPES[$ext],
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            // always ask, but a 304 costs almost nothing; updates show up at once
            'Cache-Control' => 'no-cache',
            'Vary' => 'Accept-Encoding',
        ];
        if (($requestHeaders['if-none-match'] ?? '') === $etag) {
            return new Response(304, '', $headers);
        }
        $body = (string) file_get_contents($file);
        $text = strpos(self::TYPES[$ext], 'text/') === 0 || in_array($ext, ['svg', 'webmanifest', 'json'], true);
        if ($text && strlen($body) > 1024 && stripos($requestHeaders['accept-encoding'] ?? '', 'gzip') !== false && function_exists('gzencode')) {
            $body = (string) gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }
        return new Response(200, $body, $headers);
    }
}
