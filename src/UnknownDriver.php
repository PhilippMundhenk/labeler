<?php

declare(strict_types=1);

namespace Labeler;

/**
 * Stands in for a `printer_driver` that does not exist: the status says what is wrong,
 * printing is refused.
 */
final class UnknownDriver implements Driver
{
    private string $name;
    /** @var list<string> */
    private array $known;
    private string $problem;

    /**
     * @param list<string> $known
     * @param string $problem what is wrong, instead of the unknown driver name
     */
    public function __construct(string $name, array $known, string $problem = '')
    {
        $this->name = $name;
        $this->known = $known;
        $this->problem = $problem;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): ?string
    {
        return null;
    }

    public function supports(string $feature): bool
    {
        return false;
    }

    public function features(): array
    {
        return [];
    }

    public function offlineFeatures(): array
    {
        return [];
    }

    public function withSerial(string $serial): Driver
    {
        return $this;
    }

    public function connected(): ?array
    {
        return null;
    }

    public function info(): array
    {
        return $this->offline($this->message());
    }

    public function offline(string $message): array
    {
        return [
            'connected' => false,
            'ready' => false,
            'model' => null,
            'serial' => null,
            'dpi' => null,
            'max_px' => null,
            'tape_px' => null,
            'media_type' => null,
            'media_width_mm' => null,
            'tape_color' => null,
            'text_color' => null,
            'error_code' => null,
            'errors' => [],
            'message' => $message,
        ];
    }

    public function printImage(string $png, int $copies = 1, bool $chain = false, bool $precut = false, array $extra = []): array
    {
        return ['ok' => false, 'output' => $this->message(), 'notes' => []];
    }

    private function message(): string
    {
        if ($this->problem !== '') {
            return $this->problem;
        }
        return "Unknown printer driver \"{$this->name}\" in printer_driver: use " . implode(' or ', $this->known) . '.';
    }
}
