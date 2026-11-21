<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;
use Labeler\Log;
use Labeler\QlPrint;
use Labeler\Sim\Png as SimPng;

/**
 * The `ql` driver against the simulated printer (tools/ql-sim/ql-printer) on a Unix socket,
 * found through a fake /dev/usb and sysfs.
 */
final class QlPrintTest extends TestCase
{
    /** @var resource|null */
    private static $printer;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lq-' . bin2hex(random_bytes(3));
        foreach (['dev', 'out', 'sys/lp0/device', 'sys/lp1/device'] as $sub) {
            mkdir(self::$dir . '/' . $sub, 0777, true);
        }
        file_put_contents(self::$dir . '/sys/lp0/device/ieee1284_id', 'MFG:Brother;CMD:PT-CBP;MDL:QL-570;CLS:PRINTER;');
        file_put_contents(self::$dir . '/sys/lp0/serial', "000G0Z111111\n");
        file_put_contents(self::$dir . '/sys/lp1/device/ieee1284_id', 'MFG:Brother;CMD:PT-CBP;MDL:PT-P700;CLS:PRINTER;');
        touch(self::$dir . '/dev/lp1');
        self::state([]);
        $root = dirname(__DIR__, 2);
        self::$printer = proc_open(
            [PHP_BINARY, $root . '/tools/ql-sim/ql-printer', self::$dir . '/dev/lp0'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', self::$dir . '/sim.log', 'w']],
            $pipes,
            $root,
            ['QLSIM_OUT' => self::$dir . '/out', 'QLSIM_STATE' => self::$dir . '/state.json']
        );
        for ($i = 0; $i < 100 && @filetype(self::$dir . '/dev/lp0') !== 'socket'; $i++) {
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$printer)) {
            proc_terminate(self::$printer);
            proc_close(self::$printer);
        }
        exec('rm -rf ' . escapeshellarg(self::$dir));
    }

    private static function state(array $state): void
    {
        file_put_contents(self::$dir . '/state.json', json_encode((object) $state));
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::state([]);
        exec('rm -f ' . escapeshellarg(self::$dir . '/out') . '/*');
    }

    private function driver(string $serial = '', int $timeout = 10, string $device = '', ?Log $log = null): QlPrint
    {
        return new QlPrint($device, $timeout, $serial, $log, self::$dir . '/dev/lp*', self::$dir . '/sys');
    }

    /** @return list<array<string, mixed>> */
    private static function records(): array
    {
        return array_map(static function (string $f): array {
            return json_decode((string) file_get_contents($f), true);
        }, glob(self::$dir . '/out/label-*.json') ?: []);
    }

    private function png(int $height, int $length): string
    {
        $rows = array_fill(0, $height, str_repeat('1', $length));
        $file = $this->tmp . '/label.png';
        file_put_contents($file, SimPng::fromRows($rows));
        return $file;
    }

    public function testDriverBasics(): void
    {
        $driver = $this->driver();
        $this->assertSame('ql', $driver->name());
        $this->assertSame(Config::VERSION, $driver->version());
        $this->assertTrue($driver->supports('copies'));
        $this->assertFalse($driver->supports('halfcut'));
        $this->assertFalse($driver->supports('nonsense'));
        $this->assertSame(QlPrint::FEATURES, $driver->features());
        $this->assertSame(array_fill_keys(array_keys(QlPrint::FEATURES), false), $driver->offlineFeatures());
        $this->assertSame($driver, $driver->withSerial(''));
        $this->assertNotSame($driver, $driver->withSerial('X'));
        $this->assertSame('gone', $driver->offline('gone')['message']);
        $this->assertSame(300, $driver->offline('')['dpi']);
    }

    public function testFindsThePrinter(): void
    {
        $this->assertSame([['path' => self::$dir . '/dev/lp0', 'model' => 'QL-570', 'serial' => '000G0Z111111']], $this->driver()->devices(), 'the P-Touch on lp1 is skipped');
        $info = $this->driver()->info();
        $this->assertTrue($info['ready'], (string) $info['message']);
        $this->assertSame(['QL-570', '000G0Z111111', 696, '62', 300], [$info['model'], $info['serial'], $info['tape_px'], $info['media']['id'], $info['dpi']]);
        $this->assertSame([['model' => 'QL-570', 'serial' => '000G0Z111111', 'usable' => true]], $this->driver()->connected());
        $this->assertTrue($this->driver('000G0Z111111')->info()['ready']);
        $info = $this->driver()->withSerial('OTHER')->info();
        $this->assertSame('No Brother QL printer with serial OTHER found.', $info['message']);
        $this->assertFalse($info['connected']);
    }

    public function testNoPrinter(): void
    {
        $driver = new QlPrint('', 10, '', null, $this->tmp . '/lp*', $this->tmp);
        $this->assertSame('No Brother QL printer found. Check the USB cable and switch the printer on.', $driver->info()['message']);
        $this->assertSame([], $driver->connected());
        $this->assertFalse($driver->printImage($this->png(10, 10))['ok']);
    }

    public function testConfiguredDevice(): void
    {
        $driver = $this->driver('', 10, self::$dir . '/dev/lp0');
        $info = $driver->info();
        $this->assertTrue($info['ready']);
        $this->assertSame('QL-570', $info['model'], 'from sysfs');
        // a socket elsewhere: no sysfs entry, the model comes from the status answer
        symlink(self::$dir . '/dev/lp0', $this->tmp . '/printer');
        $driver = new QlPrint($this->tmp . '/printer', 10, '', null, '', $this->tmp);
        $this->assertSame([['model' => 'QL-570', 'serial' => '', 'usable' => true]], $driver->connected());
        $this->assertSame('QL-570', $driver->info()['model']);
        self::state(['fail' => 'silent']);
        $this->assertSame([['model' => 'Brother QL', 'serial' => '', 'usable' => true]], (new QlPrint($this->tmp . '/printer', 1, '', null, '', $this->tmp))->connected());
    }

    public function testDevicesThatDoNotWork(): void
    {
        self::state(['fail' => 'silent']);
        $info = $this->driver('', 1)->info();
        $this->assertSame('The printer does not answer. Switch it off and on again.', $info['message']);
        $this->assertTrue($info['connected']);
        $this->assertSame('QL-570', $info['model']);

        file_put_contents($this->tmp . '/file', '');
        $this->assertSame('The printer does not answer. Switch it off and on again.', (new QlPrint($this->tmp . '/file', 1))->info()['message']);
        chmod($this->tmp . '/file', 0444);
        if (!is_writable($this->tmp . '/file')) { // not as root
            $this->assertStringContainsString('Install the udev rule', (new QlPrint($this->tmp . '/file', 1))->info()['message']);
        }
        $this->assertStringStartsWith('The printer cannot be opened: ', (new QlPrint($this->tmp . '/missing', 1))->info()['message']);
        $stale = stream_socket_server('unix://' . $this->tmp . '/stale.sock');
        fclose($stale);
        $this->assertStringStartsWith('The printer cannot be opened: ', (new QlPrint($this->tmp . '/stale.sock', 1))->info()['message']);
    }

    public function testPrinterStates(): void
    {
        self::state(['media' => '29x90']);
        $info = $this->driver()->info();
        $this->assertSame(['29x90', 'die-cut', 991, 306], [$info['media']['id'], $info['media']['kind'], $info['media']['length_px'], $info['tape_px']]);
        self::state(['media' => 'none']);
        $this->assertSame('No DK roll: insert one and close the cover.', $this->driver()->info()['message']);
        self::state(['error' => 4096]);
        $info = $this->driver()->info();
        $this->assertFalse($info['ready']);
        $this->assertSame('The cover is open: close it.', $info['message']);
    }

    public function testPrint(): void
    {
        $log = new Log($this->tmp . '/data', 10);
        $result = $this->driver('', 10, '', $log)->printImage($this->png(696, 120), 3, false, true, ['halfcut' => true, 'hires' => true, 'feed' => 5, 'cut_every' => 2]);
        $this->assertTrue($result['ok'], $result['output']);
        $this->assertSame('3 labels printed', $result['output']);
        $this->assertSame([
            'precut is not available on Brother QL printers', 'halfcut is not available on Brother QL printers',
            'hires is not available on Brother QL printers', 'feed is not available on Brother QL printers',
        ], $result['notes']);
        $records = self::records();
        $this->assertCount(3, $records);
        $this->assertSame([150, 696, 696 * 120, 0, 2, true, 35], [$records[0]['width'], $records[0]['height'], $records[0]['black'], $records[0]['outside'], $records[0]['cut_every'], $records[0]['cut_at_end'], $records[0]['margin']]);
        $entry = $log->entries()[0];
        $this->assertSame(['ql', '--media', '62', '--copies', '3'], $entry['args']);

        $result = $this->driver()->printImage($this->png(100, 50), 1, true);
        $this->assertSame([true, '1 label printed', []], [$result['ok'], $result['output'], $result['notes']]);
        $records = self::records();
        $this->assertFalse($records[3]['cut_at_end'], 'chain: no cut after the label');
        $this->assertSame(1, $records[3]['cut_every']);
        $this->assertSame(100 * 50, $records[3]['black'], 'centred, nothing lost');
    }

    public function testPrintProblems(): void
    {
        $this->assertSame('the label is 697 px high, the DK media takes 696 px', $this->driver()->printImage($this->png(697, 10))['output']);
        $this->assertSame('not a PNG image', $this->driver()->printImage($this->tmp . '/missing.png')['output']);
        self::state(['fail' => 'jam']);
        $result = $this->driver()->printImage($this->png(10, 10));
        $this->assertSame([false, 'Cutter jam: open the cover and remove the jammed label.'], [$result['ok'], $result['output']]);
        self::state(['media' => 'none']);
        $this->assertSame('No DK roll: insert one and close the cover.', $this->driver()->printImage($this->png(10, 10))['output']);
        self::state(['delay_ms' => 3000]);
        $result = $this->driver('', 1)->printImage($this->png(10, 10));
        $this->assertSame([false, 'The printer did not confirm the label.'], [$result['ok'], $result['output']]);
    }

    public function testThroughTheApi(): void
    {
        $api = new Api(Config::load($this->tmp, null, [
            'LABELER_PRINTER_DRIVER' => 'ql',
            'LABELER_QL_DEVICE' => self::$dir . '/dev/lp0',
            'LABELER_DATA_DIR' => $this->tmp . '/data',
        ]));
        [$status, , $raw] = $api->handle('GET', ['action' => 'status'], '', '')->parts();
        $body = json_decode($raw, true);
        $this->assertSame([200, 'ql', Config::VERSION, null, true], [$status, $body['driver'], $body['driver_version'], $body['ptouch_version'], $body['printer']['ready']]);
        $this->assertTrue($body['features']['cut_every']);
        $png = (string) json_encode(['png' => base64_encode(SimPng::fromRows(array_fill(0, 696, str_repeat('1', 300)))), 'copies' => 2]);
        [$status, , $raw] = $api->handle('POST', ['action' => 'print'], $png, 'application/json')->parts();
        $body = json_decode($raw, true);
        $this->assertSame(200, $status, $raw);
        $this->assertSame([300, 696, 25.4, 2], [$body['width_px'], $body['height_px'], $body['length_mm'], $body['copies']]);
        // (the slow page of testPrintProblems may still arrive: count only these)
        $this->assertCount(2, array_filter(self::records(), static function (array $r): bool {
            return $r['width'] === 300;
        }));
        [, , $raw] = $api->handle('GET', ['action' => 'printers'], '', '')->parts();
        $this->assertSame(['supported' => true, 'printers' => [['model' => 'QL-570', 'serial' => '', 'usable' => true]]], json_decode($raw, true), 'a configured device has no sysfs entry here');
    }
}
