<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Api;
use Labeler\Config;
use Labeler\Session;

final class SessionTest extends TestCase
{
    public function testPlainAndHashedPasswords(): void
    {
        $plain = new Session('secret');
        $this->assertTrue($plain->enabled());
        $this->assertTrue($plain->verify('secret'));
        $this->assertFalse($plain->verify('Secret'));
        $this->assertFalse($plain->verify(''));
        $hashed = new Session(password_hash('secret', PASSWORD_DEFAULT));
        $this->assertTrue($hashed->verify('secret'));
        $this->assertFalse($hashed->verify('nope'));
        $off = new Session('');
        $this->assertFalse($off->enabled());
        $this->assertFalse($off->verify('anything'));
    }

    public function testSignedCookie(): void
    {
        $session = new Session('secret');
        $cookie = $session->issue(1000);
        $this->assertTrue($session->valid($cookie, 1001));
        $this->assertFalse($session->valid($cookie, 1000 + Session::LIFETIME), 'expired');
        $this->assertFalse($session->valid(null, 1001));
        $this->assertFalse($session->valid('garbage', 1001));
        [$expires, $mac] = explode('.', $cookie);
        $this->assertFalse($session->valid(($expires + 999) . '.' . $mac, 1001), 'changed expiry');
        $this->assertFalse((new Session('other'))->valid($cookie, 1001), 'another password, another key');
        $this->assertFalse((new Session(''))->valid($cookie, 1001));
        $this->assertSame('labeler_session=v; Path=/; Max-Age=5; HttpOnly; SameSite=Strict; Secure', Session::cookie('v', true, 5));
        $this->assertStringNotContainsString('Secure', Session::cookie('v', false, 5));
    }

    private function api(array $env): Api
    {
        Api::$loginDelayMs = 0;
        return new Api(Config::load($this->tmp, null, $env + [
            'LABELER_PTOUCH_BIN' => self::simBin(),
            'LABELER_DATA_DIR' => $this->tmp . '/data',
        ]));
    }

    public function testLoginFlow(): void
    {
        $this->simulate(['version' => '1.9']);
        $api = $this->api(['LABELER_PASSWORD' => 'secret']);
        $locked = $api->handle('GET', ['action' => 'status'], '', '');
        $this->assertSame(401, $locked->status);
        $this->assertTrue(json_decode($locked->body, true)['login']);
        $this->assertSame(200, $api->handle('GET', ['action' => 'health'], '', '')->status);

        $wrong = $api->handle('POST', ['action' => 'login'], '{"password": "nope"}', 'application/json');
        $this->assertSame([401, 'wrong password'], [$wrong->status, json_decode($wrong->body, true)['error']]);
        $this->assertSame(401, $api->handle('POST', ['action' => 'login'], '[]', 'application/json')->status);

        $ok = $api->handle('POST', ['action' => 'login'], '{"password": "secret"}', 'application/json', '', [], true);
        $this->assertSame(200, $ok->status);
        $this->assertMatchesRegularExpression('/^labeler_session=(\d+\.[0-9a-f]{64}); Path=\/; Max-Age=2592000; HttpOnly; SameSite=Strict; Secure$/', $ok->headers['Set-Cookie']);
        preg_match('/=([^;]+);/', $ok->headers['Set-Cookie'], $m);
        $status = $api->handle('GET', ['action' => 'status'], '', '', '', [Session::COOKIE => $m[1]]);
        $this->assertSame(200, $status->status);
        $this->assertTrue(json_decode($status->body, true)['password']);

        $out = $api->handle('POST', ['action' => 'logout'], '', '');
        $this->assertStringContainsString('Max-Age=0', $out->headers['Set-Cookie']);
    }

    public function testTokenAndPasswordTogether(): void
    {
        $this->simulate(['version' => '1.9']);
        $api = $this->api(['LABELER_PASSWORD' => 'secret', 'LABELER_TOKEN' => 'tok']);
        $this->assertSame(200, $api->handle('GET', ['action' => 'status'], '', '', 'Bearer tok')->status, 'agents keep using the token');
        $this->assertSame(401, $api->handle('GET', ['action' => 'status'], '', '', 'Bearer bad')->status);
        $open = $this->api([]);
        $this->assertSame(400, $open->handle('POST', ['action' => 'login'], '{"password": "x"}', 'application/json')->status, 'no password set');
        $this->assertFalse(json_decode($open->handle('GET', ['action' => 'status'], '', '')->body, true)['password']);
    }

    public function testWrongPasswordsAreSlow(): void
    {
        $api = $this->api(['LABELER_PASSWORD' => 'secret']);
        Api::$loginDelayMs = 200;
        $started = microtime(true);
        $api->handle('POST', ['action' => 'login'], '{"password": "x"}', 'application/json');
        $this->assertGreaterThan(0.19, microtime(true) - $started);
        Api::$loginDelayMs = 0;
    }
}
