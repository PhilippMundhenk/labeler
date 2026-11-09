<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;
use Labeler\Remote;

/**
 * The interface talking to a real printer agent: PHP's built-in server with the simulated
 * printer and an access token, started once for this class.
 */
final class AgentTest extends TestCase
{
    private const TOKEN = 'agent-test-token-1234';

    /** @var resource|null */
    private static $server;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/labeler-agent-' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/printed', 0777, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $env = [
            'LABELER_PTOUCH_BIN' => self::simBin(),
            'LABELER_TOKEN' => self::TOKEN,
            'LABELER_DATA_DIR' => self::$dir . '/data',
            'LABELER_MAX_COPIES' => '3',
            'PTSIM_OUT' => self::$dir . '/printed',
            'PTSIM_STATE' => self::$dir . '/state.json',
            'PATH' => (string) getenv('PATH'),
        ];
        file_put_contents(self::$dir . '/state.json', '{}');
        $root = dirname(__DIR__, 2);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root . '/public'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            $env
        );
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents('http://127.0.0.1:' . self::$port . '/api.php?action=health') !== false) {
                return;
            }
            usleep(50000);
        }
        self::fail('agent did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        exec('rm -rf ' . escapeshellarg(self::$dir));
    }

    private function ui(string $token = self::TOKEN, ?int $port = null): Api
    {
        return new Api(Config::load($this->tmp, null, [
            'LABELER_PRINTER_URL' => 'http://127.0.0.1:' . ($port ?? self::$port) . '/',
            'LABELER_PRINTER_TOKEN' => $token,
            'LABELER_MAX_COPIES' => '10',
            'LABELER_PTOUCH_BIN' => $this->tmp . '/must-not-be-used',
        ]));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function json(\Labeler\Response $r): array
    {
        return [$r->status, json_decode($r->body, true)];
    }

    private static function state(array $state): void
    {
        file_put_contents(self::$dir . '/state.json', json_encode((object) $state));
    }

    private static function printedCount(): int
    {
        return count(glob(self::$dir . '/printed/*.json') ?: []);
    }

    public function testStatusComesFromTheAgent(): void
    {
        self::state(['tape_mm' => 24]);
        [$status, $body] = self::json($this->ui()->handle('GET', ['action' => 'status'], '', ''));
        $this->assertSame(200, $status);
        $this->assertTrue($body['printer']['ready']);
        $this->assertSame(128, $body['printer']['tape_px']);
        $this->assertSame('1.9', $body['ptouch_version']);
        $this->assertSame(3, $body['max_copies'], 'the smaller limit of agent and interface');
        $this->assertSame('https://api.iconify.design', $body['icon_api'], 'icons are a matter of the interface');
        $this->assertSame(['url' => 'http://127.0.0.1:' . self::$port, 'version' => Config::VERSION, 'reachable' => true], $body['agent']);
    }

    public function testPrintAndLogGoThroughTheAgent(): void
    {
        self::state([]);
        $before = self::printedCount();
        $png = (string) json_encode(['png' => base64_encode(self::labelPng(40, 76)), 'copies' => 2]);
        [$status, $body] = self::json($this->ui()->handle('POST', ['action' => 'print'], $png, 'application/json'));
        $this->assertSame(200, $status, (string) json_encode($body));
        $this->assertSame(2, $body['copies']);
        $this->assertSame($before + 2, self::printedCount());
        [, $log] = self::json($this->ui()->handle('GET', ['action' => 'log'], '', ''));
        $this->assertSame(['--copies', '2', '--image', 'label.png'], $log['entries'][0]['args']);
    }

    public function testAgentErrorsArePassedOn(): void
    {
        self::state(['tape_mm' => 0]);
        $png = (string) json_encode(['png' => base64_encode(self::labelPng(40, 76))]);
        [$status, $body] = self::json($this->ui()->handle('POST', ['action' => 'print'], $png, 'application/json'));
        $this->assertSame(503, $status);
        $this->assertSame('No tape cassette inserted.', $body['error']);
        self::state([]);
        [$status] = self::json($this->ui()->handle('POST', ['action' => 'print'], '{"png":"AAAA","copies":9}', 'application/json'));
        $this->assertSame(400, $status, 'the agent has the stricter copy limit');
    }

    public function testPrinterChoiceGoesThroughTheAgent(): void
    {
        self::state(['others' => [['printer' => 'PT-P750W', 'serial' => 'B2', 'tape_mm' => 24]]]);
        [, $body] = self::json($this->ui()->handle('GET', ['action' => 'printers'], '', ''));
        $this->assertCount(2, $body['printers']);
        [, $body] = self::json($this->ui()->handle('GET', ['action' => 'status', 'serial' => 'B2'], '', ''));
        $this->assertSame('PT-P750W', $body['printer']['model']);
        self::state([]);
    }

    public function testWrongToken(): void
    {
        [, $body] = self::json($this->ui('wrong')->handle('GET', ['action' => 'status'], '', ''));
        $this->assertFalse($body['printer']['ready']);
        $this->assertStringContainsString('refused the access token', $body['printer']['message']);
        $png = (string) json_encode(['png' => base64_encode(self::labelPng(40, 76))]);
        [$status, $body] = self::json($this->ui('')->handle('POST', ['action' => 'print'], $png, 'application/json'));
        $this->assertSame(502, $status);
        $this->assertStringContainsString('refused the access token', $body['error']);
    }

    public function testAgentDown(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $closed = (int) substr($name, strrpos($name, ':') + 1);
        [, $body] = self::json($this->ui(self::TOKEN, $closed)->handle('GET', ['action' => 'status'], '', ''));
        $this->assertStringContainsString('cannot be reached', $body['printer']['message']);
        $this->assertFalse($body['agent']['reachable']);
        $this->assertFalse($body['features']['copies']);
        [$status, $body] = self::json($this->ui(self::TOKEN, $closed)->handle('GET', ['action' => 'log'], '', ''));
        $this->assertSame(502, $status);
    }

    public function testNotAnAgent(): void
    {
        $agent = new Remote('http://127.0.0.1:' . self::$port, self::TOKEN, 5);
        $this->assertSame(['status' => 404, 'data' => ['error' => 'unknown action'], 'error' => ''], $agent->call('GET', 'nope'));
        $other = new Remote('http://127.0.0.1:' . self::$port . '/nothing-here', self::TOKEN, 5);
        // 404, or the page itself with 200 on PHP 8.4's built-in server: either way not an agent
        $answer = $other->call('GET', 'status');
        $this->assertSame([null, 'not a Labeler agent'], [$answer['data'], $answer['error']]);
        $this->assertContains($answer['status'], [200, 404]);
        $api = $this->ui();
        $ref = new \ReflectionProperty(Api::class, 'remote');
        $ref->setAccessible(true);
        $ref->setValue($api, new Remote('http://127.0.0.1:' . self::$port . '/nothing-here', self::TOKEN, 5));
        [, $body] = self::json($api->handle('GET', ['action' => 'status'], '', ''));
        $this->assertMatchesRegularExpression('/answered HTTP (200|404): not a Labeler agent/', $body['printer']['message']);
    }

    public function testTheAgentChecksItsToken(): void
    {
        $url = 'http://127.0.0.1:' . self::$port . '/api.php?action=';
        $this->assertNotFalse(@file_get_contents($url . 'health'), 'health needs no token');
        $this->assertFalse(@file_get_contents($url . 'status'));
        $ctx = stream_context_create(['http' => ['header' => 'X-Labeler-Token: ' . self::TOKEN]]);
        $this->assertStringContainsString('"printer"', (string) file_get_contents($url . 'status', false, $ctx));
    }

    public function testAgentAddressIsShownWithoutCredentials(): void
    {
        $this->assertSame('http://agent:8631', (new Remote('http://user:secret@agent:8631/', 't'))->url());
        $this->assertSame('https://agent.example', (new Remote('https://user@agent.example', 't'))->url());
        $this->assertSame('http://agent:8631/path@x', (new Remote('http://agent:8631/path@x', 't'))->url());
    }
}
