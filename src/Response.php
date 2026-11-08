<?php

declare(strict_types=1);

namespace Labeler;

final class Response
{
    public int $status;
    public string $body;
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(int $status, string $body, array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers
        );
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} */
    public function parts(): array
    {
        $headers = $this->headers + ['X-Content-Type-Options' => 'nosniff'];
        return [$this->status, $headers, $this->body];
    }
}
