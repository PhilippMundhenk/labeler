<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Talks to a Labeler printer agent on the computer the printer is plugged into.
 * Uses PHP streams only, so no curl extension is needed.
 */
final class Remote
{
    private string $url;
    private string $token;
    private int $timeout;

    public function __construct(string $url, string $token, int $timeout = 60)
    {
        $this->url = rtrim($url, '/');
        $this->token = $token;
        $this->timeout = max(1, $timeout);
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array{status: int, data: array<string, mixed>|null, error: string}
     *   status 0 when the agent cannot be reached
     */
    public function call(string $method, string $action, string $body = ''): array
    {
        $headers = ['Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
            $headers[] = 'X-Labeler-Token: ' . $this->token;
        }
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $error = '';
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = preg_replace('/^.*?: /', '', $message) ?? $message;
            return true;
        });
        try {
            $raw = file_get_contents($this->url . '/api.php?action=' . rawurlencode($action), false, $context);
        } finally {
            restore_error_handler();
        }
        // PHP 8.4 has a function for the response headers; the variable is deprecated later
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (array) http_get_last_response_headers()
            : ($http_response_header ?? []);
        $statusLine = (string) ($responseHeaders[0] ?? '');
        if ($raw === false || !preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $m)) {
            return ['status' => 0, 'data' => null, 'error' => $error !== '' ? $error : 'no answer'];
        }
        $data = json_decode((string) $raw, true);
        return ['status' => (int) $m[1], 'data' => is_array($data) ? $data : null, 'error' => is_array($data) ? '' : 'not a Labeler agent'];
    }
}
