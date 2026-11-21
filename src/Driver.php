<?php

declare(strict_types=1);

namespace Labeler;

/**
 * What the API needs from a printer backend. PtouchPrint (P-Touch printers through ptouch-print)
 * is the first one; Drivers::create() picks one by the `printer_driver` setting.
 */
interface Driver
{
    /** Name as in `printer_driver`, e.g. "ptouch". */
    public function name(): string;

    /** Version of the program or protocol the driver uses, null when it cannot be used. */
    public function version(): ?string;

    public function supports(string $feature): bool;

    /** @return array<string, bool> every option this driver knows, and whether it is available */
    public function features(): array;

    /** @return array<string, bool> the options shown as unavailable when no printer can be asked */
    public function offlineFeatures(): array;

    /** The same driver, talking to the printer with this serial number (empty: the configured one). */
    public function withSerial(string $serial): self;

    /**
     * @return list<array{model: string, serial: string, usable: bool}>|null null when not supported
     */
    public function connected(): ?array;

    /**
     * Printer and media: connected, ready, model, serial, dpi, max_px, tape_px, media_type,
     * media_width_mm, tape_color, text_color, error_code, errors, message.
     *
     * @return array<string, mixed>
     */
    public function info(): array;

    /**
     * The info answer for a printer that cannot be asked at all, with this message.
     *
     * @return array<string, mixed>
     */
    public function offline(string $message): array;

    /**
     * Print a 2-colour palette PNG file.
     *
     * @param array{feed?: ?int, halfcut?: bool, cut_every?: ?int, hires?: bool} $extra
     * @return array{ok: bool, output: string, notes: list<string>}
     */
    public function printImage(string $png, int $copies = 1, bool $chain = false, bool $precut = false, array $extra = []): array;
}
