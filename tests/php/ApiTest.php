<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;

final class ApiTest extends TestCase
{
    private function api(array $env = []): Api
    {
        $env += [
            'LABELER_PTOUCH_BIN' => self::simBin(),
            'LABELER_DATA_DIR' => $this->tmp . '/data',
            'LABELER_FONTS_DIR' => $this->tmp . '/fonts',
            'LABELER_MAX_COPIES' => '5',
            'LABELER_MAX_UPLOAD_BYTES' => '50000',
        ];
        return new Api(Config::load($this->tmp, null, $env));
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: array<string, string>} */
    private function call(string $method, string $action, string $body = '', string $type = 'application/json', array $env = [], array $query = []): array
    {
        $response = $this->api($env)->handle($method, ['action' => $action] + $query, $body, $type);
        [$status, $headers, $raw] = $response->parts();
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $json = json_decode($raw, true);
        return [$status, is_array($json) ? $json : ['raw' => $raw], $headers];
    }

    private function printBody(int $width = 60, int $height = 76, array $extra = []): string
    {
        return (string) json_encode(['png' => base64_encode(self::labelPng($width, $height))] + $extra);
    }

    public function testRouting(): void
    {
        $this->assertSame(404, $this->call('GET', 'nope')[0]);
        [$status, $body, $headers] = $this->call('GET', 'print');
        $this->assertSame([405, 'use POST', 'POST'], [$status, $body['error'], $headers['Allow']]);
        $this->assertSame(405, $this->call('POST', 'status')[0]);
        [$status, $body, $headers] = $this->call('HEAD', 'health');
        $this->assertSame([200, true], [$status, $body['ok']]);
        $this->assertSame('no-store', $headers['Cache-Control']);
    }

    public function testStatus(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12]);
        [$status, $body] = $this->call('GET', 'status');
        $this->assertSame(200, $status);
        $this->assertSame('1.9', $body['ptouch_version']);
        $this->assertTrue($body['printer']['ready']);
        $this->assertSame(76, $body['printer']['tape_px']);
        $this->assertSame(5, $body['max_copies']);
        $this->assertSame('https://api.iconify.design', $body['icon_api']);
        $this->assertTrue($body['features']['precut']);
    }

    public function testStatusWithoutPtouchPrint(): void
    {
        [$status, $body] = $this->call('GET', 'status', '', '', ['LABELER_PTOUCH_BIN' => $this->tmp . '/missing']);
        $this->assertSame(200, $status);
        $this->assertNull($body['ptouch_version']);
        $this->assertFalse($body['printer']['ready']);
        $this->assertStringContainsString('not found', $body['printer']['message']);
    }

    public function testPrint(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12]);
        [$status, $body] = $this->call('POST', 'print', $this->printBody(180, 76, ['copies' => 2, 'chain' => true]));
        $this->assertSame(200, $status, (string) json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame([180, 76, 25.4, 2], [$body['width_px'], $body['height_px'], $body['length_mm'], $body['copies']]);
        $printed = $this->printed();
        $this->assertCount(2, $printed);
        $this->assertTrue($printed[0]['chain']);
        $this->assertSame([], array_filter(glob(sys_get_temp_dir() . '/labeler*') ?: [], 'is_file'), 'the temporary file is removed');
        [, $log] = $this->call('GET', 'log');
        $this->assertTrue($log['enabled']);
        $this->assertSame(0, $log['entries'][0]['code']);
    }

    public function testPrintWithExtensions(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 12, 'extensions' => 1]);
        [, $status] = $this->call('GET', 'status');
        $this->assertTrue($status['features']['halfcut']);
        [$code, $body] = $this->call('POST', 'print', $this->printBody(360, 76, ['copies' => 2, 'feed' => 4, 'halfcut' => true, 'cut_every' => 2, 'hires' => true]));
        $this->assertSame(200, $code, (string) json_encode($body));
        $this->assertSame(25.4, $body['length_mm'], 'high resolution: 360 columns are 25.4 mm at 180 dpi');
        $printed = $this->printed();
        $this->assertSame([4, true, 2, true], [$printed[0]['feed'], $printed[0]['halfcut'], $printed[0]['cut_every'], $printed[0]['hires']]);

        foreach ([['feed' => -1], ['feed' => 1001], ['feed' => '4'], ['cut_every' => 0], ['cut_every' => 100]] as $bad) {
            [$code, $body] = $this->call('POST', 'print', $this->printBody(60, 76, $bad));
            $this->assertSame(400, $code, (string) json_encode($bad));
            $this->assertStringContainsString('must be a whole number', $body['error']);
        }
        [$code] = $this->call('POST', 'print', $this->printBody(60, 76, ['feed' => '', 'cut_every' => null]));
        $this->assertSame(200, $code, 'empty values mean the printer default');
    }

    public function testHostNamesAgainstDnsRebinding(): void
    {
        $this->simulate(['version' => '1.9']);
        $api = $this->api();
        foreach (['', '192.168.1.20', '192.168.1.20:8080', '[::1]:8080', 'localhost:8080', 'labeler', 'labeler:55543',
            'pi.local', 'printer.lan', 'pi.home.arpa', 'box.internal'] as $host) {
            $this->assertTrue($api->hostAllowed($host), $host);
        }
        foreach (['evil.example.com', 'labels.example.org:443', '1.2.3.4.nip.io'] as $host) {
            $this->assertFalse($api->hostAllowed($host), $host);
        }
        $denied = $api->handle('GET', ['action' => 'status'], '', '', '', [], false, 'evil.example.com');
        $this->assertSame(403, $denied->status);
        $this->assertStringContainsString('allowed_hosts', $denied->body);
        $this->assertSame(200, $api->handle('GET', ['action' => 'health'], '', '', '', [], false, 'evil.example.com')->status);

        $listed = $this->api(['LABELER_ALLOWED_HOSTS' => 'Labels.Example.org, other.example']);
        $this->assertTrue($listed->hostAllowed('labels.example.org:443'));
        $this->assertFalse($listed->hostAllowed('evil.example.com'));
        $this->assertTrue($this->api(['LABELER_ALLOWED_HOSTS' => '*'])->hostAllowed('evil.example.com'));

        // with a token or a password the foreign page cannot use the API anyway
        $token = $this->api(['LABELER_TOKEN' => 'secret']);
        $this->assertSame(200, $token->handle('GET', ['action' => 'status'], '', '', 'Bearer secret', [], false, 'evil.example.com')->status);
        $this->assertSame(401, $this->api(['LABELER_PASSWORD' => 'pw'])->handle('GET', ['action' => 'status'], '', '', '', [], false, 'evil.example.com')->status);
    }

    public function testPrintAcceptsADataUrl(): void
    {
        $this->simulate(['version' => '1.9']);
        $body = (string) json_encode(['png' => 'data:image/png;base64,' . base64_encode(self::labelPng(20, 76))]);
        $this->assertSame(200, $this->call('POST', 'print', $body)[0]);
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> */
    public static function badRequests(): array
    {
        return [
            'form post' => ['x=1', 'application/x-www-form-urlencoded', 415, 'application/json'],
            'not json' => ['{', 'application/json', 400, 'expected'],
            'no png' => ['{"copies": 1}', 'application/json', 400, 'expected'],
            'png not a string' => ['{"png": 5}', 'application/json', 400, 'expected'],
            'bad base64' => ['{"png": "%%%"}', 'application/json', 400, 'base64'],
            'empty' => ['{"png": ""}', 'application/json', 400, 'base64'],
            'huge body' => ['{"png": "' . str_repeat('A', 80000) . '"}', 'application/json', 413, 'too large'],
            'huge png' => ['{"png": "' . str_repeat('A', 67000) . '"}', 'application/json', 413, 'too large'],
            'no copies' => ['{"png": "AAAA", "copies": 0}', 'application/json; charset=utf-8', 400, 'between 1 and 5'],
            'many copies' => ['{"png": "AAAA", "copies": 6}', 'application/json', 400, 'between 1 and 5'],
        ];
    }

    /** @dataProvider badRequests */
    public function testRejectsBadRequests(string $body, string $type, int $expected, string $message): void
    {
        $this->simulate(['version' => '1.9']);
        [$status, $json] = $this->call('POST', 'print', $body, $type);
        $this->assertSame($expected, $status);
        $this->assertStringContainsString($message, $json['error']);
        $this->assertCount(0, $this->printed());
    }

    public function testPrintNeedsAReadyPrinter(): void
    {
        $this->simulate(['tape_mm' => 0]);
        [$status, $body] = $this->call('POST', 'print', $this->printBody());
        $this->assertSame(503, $status);
        $this->assertSame('No tape cassette inserted.', $body['error']);
    }

    public function testPrintValidatesTheImage(): void
    {
        $this->simulate(['tape_mm' => 12]);
        [$status, $body] = $this->call('POST', 'print', $this->printBody(60, 128));
        $this->assertSame(422, $status);
        $this->assertStringContainsString('the tape takes 76 px', $body['error']);
        $gif = (string) json_encode(['png' => base64_encode('GIF89a' . str_repeat("\0", 50))]);
        $this->assertSame(422, $this->call('POST', 'print', $gif)[0]);
    }

    public function testErrorsDuringPrintingAreNoticed(): void
    {
        $state = $this->tmp . '/state.json';
        file_put_contents($state, '{"fail": "jam"}');
        $this->simulate(['state' => $state]);
        [$status, $body] = $this->call('POST', 'print', $this->printBody());
        $this->assertSame(502, $status);
        $this->assertFalse($body['ok']);
        $this->assertSame('After printing, the printer reports: Cutter jam: open the cover and remove the jammed tape.', $body['error']);
        $this->assertCount(1, $this->printed(), 'the label went out, the jam came after');
        [, $after] = $this->call('GET', 'status');
        $this->assertSame([0x0004], [$after['printer']['error_code']]);
    }

    public function testPrintFailureIsReported(): void
    {
        $this->simulate(['fail' => 'print']);
        [$status, $body] = $this->call('POST', 'print', $this->printBody());
        $this->assertSame(502, $status);
        $this->assertFalse($body['ok']);
        $this->assertStringContainsString('LIBUSB_ERROR_PIPE', $body['output']);
    }

    public function testFonts(): void
    {
        $this->assertSame(['fonts' => []], $this->call('GET', 'fonts')[1], 'no folder, no fonts');
        mkdir($this->tmp . '/fonts');
        foreach (['Roboto-Condensed_Bold.ttf', 'a.woff2', '.hidden.ttf', 'readme.txt', 'Zilla.OTF'] as $file) {
            file_put_contents($this->tmp . '/fonts/' . $file, 'font:' . $file);
        }
        [$status, $body] = $this->call('GET', 'fonts');
        $this->assertSame(200, $status);
        $this->assertSame([
            ['name' => 'a', 'file' => 'a.woff2'],
            ['name' => 'Roboto Condensed Bold', 'file' => 'Roboto-Condensed_Bold.ttf'],
            ['name' => 'Zilla', 'file' => 'Zilla.OTF'],
        ], $body['fonts']);
        [$status, $body, $headers] = $this->call('GET', 'font', '', '', [], ['file' => 'Zilla.OTF']);
        $this->assertSame([200, 'font:Zilla.OTF', 'font/otf'], [$status, $body['raw'], $headers['Content-Type']]);
        $this->assertSame(404, $this->call('GET', 'font', '', '', [], ['file' => '../config.php'])[0]);
        $this->assertSame(404, $this->call('GET', 'font', '', '', [], ['file' => 'readme.txt'])[0]);
        $this->assertSame(404, $this->call('GET', 'font')[0]);
    }

    public function testPrinterChoice(): void
    {
        $this->simulate(['version' => '1.9', 'others' => '[{"printer": "PT-P750W", "serial": "B2", "tape_mm": 24}]']);
        [$status, $body] = $this->call('GET', 'printers');
        $this->assertSame([200, true, 2], [$status, $body['supported'], count($body['printers'])]);
        [, $body] = $this->call('GET', 'status', '', '', [], ['serial' => 'B2']);
        $this->assertSame([128, 'PT-P750W'], [$body['printer']['tape_px'], $body['printer']['model']]);
        $this->assertSame(400, $this->call('GET', 'status', '', '', [], ['serial' => '--info'])[0]);
        [$status] = $this->call('POST', 'print', $this->printBody(60, 128), 'application/json', [], ['serial' => 'B2']);
        $this->assertSame(200, $status, '128 px fit the 24 mm tape of the second printer');
        $this->simulate(['version' => '1.7']);
        $this->assertSame(['supported' => false, 'printers' => []], $this->call('GET', 'printers')[1]);
    }

    public function testAccessToken(): void
    {
        $this->simulate(['version' => '1.9']);
        $api = $this->api(['LABELER_TOKEN' => 's3cret']);
        $this->assertSame(200, $api->handle('GET', ['action' => 'health'], '', '')->status, 'health stays open');
        $denied = $api->handle('GET', ['action' => 'status'], '', '');
        $this->assertSame([401, 'Bearer'], [$denied->status, $denied->headers['WWW-Authenticate']]);
        $this->assertSame(401, $api->handle('GET', ['action' => 'status'], '', '', 'Bearer wrong')->status);
        $this->assertSame(401, $api->handle('GET', ['action' => 'status'], '', '', 's3cret')->status, 'scheme required');
        $this->assertSame(200, $api->handle('GET', ['action' => 'status'], '', '', 'bearer  s3cret ')->status);
        $this->assertSame(401, $api->handle('POST', ['action' => 'print'], $this->printBody(), 'application/json')->status);
        $this->assertCount(0, $this->printed());
    }

    public function testLogWithoutDataFolder(): void
    {
        [$status, $body] = $this->call('GET', 'log', '', '', ['LABELER_DATA_DIR' => '']);
        $this->assertSame([200, false, []], [$status, $body['enabled'], $body['entries']]);
    }
}
