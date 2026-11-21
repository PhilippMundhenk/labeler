<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;
use Labeler\Driver;
use Labeler\Drivers;
use Labeler\PtouchPrint;
use Labeler\UnknownDriver;

final class DriversTest extends TestCase
{
    private function config(array $env = []): Config
    {
        return Config::load($this->tmp, null, $env + [
            'LABELER_PTOUCH_BIN' => self::simBin(),
            'LABELER_DATA_DIR' => $this->tmp . '/data',
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(Api $api, string $method, string $action, string $body = ''): array
    {
        [$status, , $raw] = $api->handle($method, ['action' => $action], $body, 'application/json')->parts();
        return [$status, json_decode($raw, true)];
    }

    public function testPtouchIsTheDefault(): void
    {
        $this->assertSame('ptouch', $this->config()->get('printer_driver'));
        $this->assertSame('ptouch', $this->config(['LABELER_PRINTER_DRIVER' => ' PTouch '])->get('printer_driver'));
        $this->assertSame('ptouch', $this->config(['LABELER_PRINTER_DRIVER' => ''])->get('printer_driver'));
        $driver = Drivers::create($this->config());
        $this->assertInstanceOf(PtouchPrint::class, $driver);
        $this->assertInstanceOf(Driver::class, $driver);
        $this->assertSame('ptouch', $driver->name());
        $this->assertArrayHasKey('ptouch', Drivers::NAMES);
    }

    public function testStatusNamesTheDriver(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12]);
        [$status, $body] = $this->call(new Api($this->config()), 'GET', 'status');
        $this->assertSame(200, $status);
        $this->assertSame(['ptouch', '1.9', '1.9'], [$body['driver'], $body['driver_version'], $body['ptouch_version']]);
        $this->assertTrue($body['printer']['ready']);
    }

    public function testPtouchOffline(): void
    {
        $driver = new PtouchPrint(self::simBin());
        $info = $driver->offline('gone');
        $this->assertSame('gone', $info['message']);
        $this->assertFalse($info['ready']);
        $this->assertSame(180, $info['dpi']);
        $this->assertSame(array_fill_keys(array_keys(PtouchPrint::FEATURES), false), $driver->offlineFeatures());
    }

    public function testMissingPtouchPrintNeedsNoInfoCall(): void
    {
        $info = (new PtouchPrint($this->tmp . '/missing'))->info();
        $this->assertFalse($info['connected']);
        $this->assertStringContainsString('ptouch-print was not found', $info['message']);
    }

    public function testUnknownDriver(): void
    {
        $api = new Api($this->config(['LABELER_PRINTER_DRIVER' => 'laser']));
        [$status, $body] = $this->call($api, 'GET', 'status');
        $this->assertSame(200, $status);
        $this->assertSame('laser', $body['driver']);
        $this->assertNull($body['driver_version']);
        $this->assertNull($body['ptouch_version']);
        $this->assertSame([], $body['features']);
        $this->assertFalse($body['printer']['ready']);
        $this->assertSame('Unknown printer driver "laser" in printer_driver: use ptouch.', $body['printer']['message']);

        $png = (string) json_encode(['png' => base64_encode(self::labelPng(40, 76))]);
        [$status, $body] = $this->call($api, 'POST', 'print', $png);
        $this->assertSame(503, $status);
        $this->assertStringContainsString('Unknown printer driver', $body['error']);
        [$status, $body] = $this->call($api, 'GET', 'printers');
        $this->assertSame([200, false, []], [$status, $body['supported'], $body['printers']]);
    }

    public function testUnknownDriverDoesNothing(): void
    {
        $driver = new UnknownDriver('x', ['ptouch', 'ql']);
        $this->assertSame($driver, $driver->withSerial('A1'));
        $this->assertFalse($driver->supports('copies'));
        $this->assertSame([], $driver->offlineFeatures());
        $this->assertNull($driver->connected());
        $result = $driver->printImage('/nonexistent.png', 2);
        $this->assertFalse($result['ok']);
        $this->assertSame('Unknown printer driver "x" in printer_driver: use ptouch or ql.', $result['output']);
        $this->assertSame('down', $driver->offline('down')['message']);
    }
}
