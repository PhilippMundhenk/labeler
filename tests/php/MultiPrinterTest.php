<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;
use Labeler\PtouchPrint;
use Labeler\QlPrint;
use Labeler\Targets;
use Labeler\UnknownDriver;
use Labeler\Sim\Png as SimPng;

/**
 * Several printers in one interface (#147): a P-touch (simulated ptouch-print) and a Brother QL
 * (simulated printer on a socket) side by side.
 */
final class MultiPrinterTest extends TestCase
{
    /** @var resource|null */
    private static $ql;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/lm-' . bin2hex(random_bytes(3));
        mkdir(self::$dir . '/ql-out', 0777, true);
        $root = dirname(__DIR__, 2);
        self::$ql = proc_open(
            [PHP_BINARY, $root . '/tools/ql-sim/ql-printer', self::$dir . '/ql.sock'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            ['QLSIM_OUT' => self::$dir . '/ql-out']
        );
        for ($i = 0; $i < 100 && @filetype(self::$dir . '/ql.sock') !== 'socket'; $i++) {
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$ql)) {
            proc_terminate(self::$ql);
            proc_close(self::$ql);
        }
        exec('rm -rf ' . escapeshellarg(self::$dir));
    }

    private function config(array $env = []): Config
    {
        return Config::load($this->tmp, null, $env + [
            'LABELER_PTOUCH_BIN' => self::simBin(),
            'LABELER_QL_DEVICE' => self::$dir . '/ql.sock',
            'LABELER_DATA_DIR' => $this->tmp . '/data',
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function call(Api $api, string $method, string $action, array $query = [], string $body = ''): array
    {
        [$status, , $raw] = $api->handle($method, ['action' => $action] + $query, $body, 'application/json')->parts();
        return [$status, json_decode($raw, true)];
    }

    private static function png(int $height, int $length): string
    {
        return (string) json_encode(['png' => base64_encode(SimPng::fromRows(array_fill(0, $height, str_repeat('1', $length))))]);
    }

    public function testTargetsFromTheSettings(): void
    {
        $one = Targets::fromConfig($this->config());
        $this->assertSame([['ptouch', 'P-touch', 'ptouch']], array_map(static function ($t): array {
            return [$t->id, $t->name, $t->kind()];
        }, $one));
        $two = Targets::fromConfig($this->config(['LABELER_PRINTER_DRIVER' => 'ptouch, ql,ql']));
        $this->assertSame(['ptouch', 'ql'], [$two[0]->id, $two[1]->id]);
        $this->assertInstanceOf(PtouchPrint::class, $two[0]->driver);
        $this->assertInstanceOf(QlPrint::class, $two[1]->driver);
        $agent = Targets::fromConfig($this->config(['LABELER_PRINTER_URL' => 'http://pi.lan:8631']));
        $this->assertSame(['agent', 'Printer agent', 'agent'], [$agent[0]->id, $agent[0]->name, $agent[0]->kind()]);

        $list = Targets::fromConfig($this->config(['LABELER_PRINTERS' => json_encode([
            ['id' => 'Desk', 'name' => 'Desk tape', 'driver' => 'ptouch', 'serial' => 'A1'],
            ['id' => 'desk', 'driver' => 'ql', 'ql_device' => '/dev/usb/lp3'],
            ['name' => 'Garage', 'url' => 'http://garage.lan:8631', 'token' => 't', 'printer' => 'ql'],
            ['id' => 'broken', 'url' => 'ftp://nope'],
            ['id' => 'odd', 'driver' => 'laser'],
            'not an entry',
        ])]));
        $this->assertSame(
            [['desk', 'Desk tape', 'ptouch'], ['desk-2', 'desk-2', 'ql'], ['printer-3', 'Garage', 'agent'], ['broken', 'broken', 'agent'], ['odd', 'odd', 'laser']],
            array_map(static function ($t): array {
                return [$t->id, $t->name, $t->kind()];
            }, $list)
        );
        $this->assertSame('ql', $list[2]->remotePrinter);
        $this->assertInstanceOf(UnknownDriver::class, $list[3]->driver);
        $this->assertStringContainsString('has no valid url', $list[3]->driver->info()['message']);
        $this->assertSame([], Config::load($this->tmp, null, ['LABELER_PRINTERS' => 'not json'])->get('printers'));
        file_put_contents($this->tmp . '/config.php', "<?php return ['printers' => 'nonsense'];");
        $this->assertSame([], Config::load($this->tmp)->get('printers'));
    }

    public function testBothPrintersInOneInterface(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12]);
        $api = new Api($this->config(['LABELER_PRINTER_DRIVER' => 'ptouch,ql']));
        [$status, $body] = self::call($api, 'GET', 'status');
        $this->assertSame(200, $status);
        $this->assertSame(['ptouch', 'P-touch', 'ptouch'], [$body['printer_id'], $body['printer_name'], $body['driver']]);
        $this->assertSame([['id' => 'ptouch', 'name' => 'P-touch', 'kind' => 'ptouch'], ['id' => 'ql', 'name' => 'Brother QL', 'kind' => 'ql']], $body['printers']);
        [, $body] = self::call($api, 'GET', 'status', ['printer' => 'ql']);
        $this->assertSame(['ql', 'ql', 696, true], [$body['printer_id'], $body['driver'], $body['printer']['tape_px'], $body['printer']['ready']]);

        [$status, $body] = self::call($api, 'GET', 'overview');
        $this->assertSame(200, $status);
        $this->assertSame(['ptouch', 'ql'], array_column($body['printers'], 'id'));
        $this->assertSame([76, 696], [$body['printers'][0]['printer']['tape_px'], $body['printers'][1]['printer']['tape_px']]);
        $this->assertSame([false, false], array_column($body['printers'], 'agent'));

        [$status] = self::call($api, 'POST', 'print', ['printer' => 'ptouch'], self::png(76, 40));
        $this->assertSame(200, $status);
        [$status, $body] = self::call($api, 'POST', 'print', ['printer' => 'ql'], self::png(696, 200));
        $this->assertSame(200, $status, (string) json_encode($body));
        $this->assertCount(1, $this->printed(), 'one label on the P-touch');
        $this->assertCount(1, glob(self::$dir . '/ql-out/label-*.json') ?: [], 'one on the QL');

        [, $body] = self::call($api, 'GET', 'log');
        $this->assertSame(['ql', 'ptouch'], array_column($body['entries'], 'printer'), 'the log names the printer');

        [$status, $body] = self::call($api, 'GET', 'status', ['printer' => 'laser']);
        $this->assertSame([400, 'there is no printer "laser"'], [$status, $body['error']]);
    }

    public function testOnePrinterLogsWithoutAName(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12]);
        $api = new Api($this->config());
        self::call($api, 'POST', 'print', [], self::png(76, 40));
        [, $body] = self::call($api, 'GET', 'log');
        $this->assertArrayNotHasKey('printer', $body['entries'][0]);
        [, $body] = self::call($api, 'GET', 'status');
        $this->assertCount(1, $body['printers']);
    }
}
