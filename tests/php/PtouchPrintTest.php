<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Log;
use Labeler\PtouchPrint;
use Labeler\Sim\Simulator;

final class PtouchPrintTest extends TestCase
{
    private function printer(string $serial = '', ?Log $log = null, string $bin = ''): PtouchPrint
    {
        return new PtouchPrint($bin ?: self::simBin(), 20, $serial, $log);
    }

    /** --info output of the simulator for a version, as the real tool prints it (stderr first). */
    private static function infoOutput(array $state): array
    {
        $r = (new Simulator($state))->run(['--info']);
        return [$r['stderr'] . $r['stdout'], $r['code']];
    }

    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ['1.5' => ['1.5'], '1.6' => ['1.6'], '1.7' => ['1.7'], '1.8' => ['1.8'], '1.9' => ['1.9']];
    }

    /** @dataProvider versions */
    public function testParsesInfoOfEveryVersion(string $version): void
    {
        $info = PtouchPrint::parseInfo(...self::infoOutput(['version' => $version, 'tape_mm' => 24, 'tape_color' => 6, 'text_color' => 8]));
        $this->assertTrue($info['ready'], $info['message']);
        $this->assertSame('PT-P700', $info['model']);
        $this->assertSame(24, $info['media_width_mm']);
        $this->assertSame(128, $info['tape_px']);
        $this->assertSame(180, $info['dpi']);
        $this->assertSame(['code' => 6, 'name' => 'Yellow'], $info['tape_color']);
        $this->assertSame(['code' => 8, 'name' => 'Black'], $info['text_color']);
        $this->assertSame(0, $info['error_code']);
        $this->assertSame(version_compare($version, '1.9', '>=') ? '000F1Z401234' : null, $info['serial']);
        $this->assertSame(version_compare($version, '1.7', '>=') ? 128 : null, $info['max_px']);
    }

    public function testParsesA360DpiPrinter(): void
    {
        $info = PtouchPrint::parseInfo(...self::infoOutput(['printer' => 'PT-P900W', 'tape_mm' => 36]));
        $this->assertSame(360, $info['dpi']);
        $this->assertSame(454, $info['tape_px']);
        $this->assertSame(454, $info['max_px']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}> */
    public static function problems(): array
    {
        return [
            'no printer' => [['printer' => 'none'], 'No printer found', false],
            'p-lite' => [['mode' => 'plite'], 'P-Lite mode', true],
            'unsupported' => [['mode' => 'unsupported'], 'not supported', true],
            'no access' => [['mode' => 'access'], 'udev rule', false],
            'no tape' => [['tape_mm' => 0], 'No tape cassette', true],
            'cover open' => [['error' => 16], 'error 0x0010', true],
            'old no tape' => [['tape_mm' => 0, 'version' => '1.5'], 'No tape cassette', true],
        ];
    }

    /** @dataProvider problems */
    public function testReportsProblems(array $state, string $message, bool $connected): void
    {
        $info = PtouchPrint::parseInfo(...self::infoOutput($state));
        $this->assertFalse($info['ready']);
        $this->assertSame($connected, $info['connected']);
        $this->assertStringContainsString($message, $info['message']);
    }

    public function testUnexpectedOutput(): void
    {
        $this->assertSame('Unexpected answer from ptouch-print.', PtouchPrint::parseInfo("hello\n", 0)['message']);
        $this->assertSame('ptouch-print failed: second', PtouchPrint::parseInfo("first\nsecond\n\n", 1)['message']);
        $this->assertSame('ptouch-print failed: no output', PtouchPrint::parseInfo('', 1)['message']);
        $this->assertStringContainsString('not found', PtouchPrint::parseInfo('', 127)['message']);
        $info = PtouchPrint::parseInfo("tape color = 0x77 (x)\ntext color = 0x66\nmedia width = 12 mm\n", 0);
        $this->assertSame('Unknown', $info['tape_color']['name']);
        $this->assertSame('Unknown', $info['text_color']['name']);
    }

    public function testVersionAndFeatures(): void
    {
        $this->simulate(['version' => '1.5']);
        $old = $this->printer();
        $this->assertSame('1.5', $old->version());
        $this->assertSame(['copies' => false, 'chain' => false, 'precut' => false, 'serial' => false], $old->features());
        $this->assertFalse($old->supports('teleport'));

        $this->simulate(['version' => '1.8']);
        $this->assertSame(['copies' => true, 'chain' => true, 'precut' => true, 'serial' => false], $this->printer()->features());

        $missing = $this->printer('', null, $this->tmp . '/missing');
        $this->assertNull($missing->version());
        $this->assertFalse($missing->supports('copies'));
    }

    public function testInfoThroughTheProcess(): void
    {
        $this->simulate(['version' => '1.9', 'tape_mm' => 9]);
        $info = $this->printer()->info();
        $this->assertTrue($info['ready']);
        $this->assertSame(52, $info['tape_px']);
    }

    public function testSerialIsPassedOnlyWhenSupported(): void
    {
        $this->simulate(['version' => '1.9']);
        $this->assertTrue($this->printer('000F1Z401234')->info()['ready']);
        $wrong = $this->printer('OTHER')->info();
        $this->assertFalse($wrong['ready']);
        $this->assertStringContainsString('No printer found', $wrong['message']);
        $this->simulate(['version' => '1.8']);
        $this->assertTrue($this->printer('OTHER')->info()['ready'], 'older versions ignore the serial');
    }

    public function testPrintsWithCopiesChainAndPrecut(): void
    {
        $this->simulate(['version' => '1.9']);
        file_put_contents($this->tmp . '/l.png', self::labelPng(40, 76));
        $log = new Log($this->tmp . '/data', 10);
        $result = $this->printer('', $log)->printImage($this->tmp . '/l.png', 2, true, true);
        $this->assertTrue($result['ok'], $result['output']);
        $this->assertSame([], $result['notes']);
        $printed = $this->printed();
        $this->assertCount(2, $printed);
        $this->assertSame([true, true, 2], [$printed[0]['chain'], $printed[0]['precut'], $printed[0]['copies']]);
        $this->assertSame(40 * 76 / 2, $printed[0]['black_px']);
        $this->assertSame(['--chain', '--precut', '--copies', '2', '--image', 'label.png'], $log->entries()[0]['args']);
    }

    public function testOldVersionsRepeatTheRunAndExplainMissingOptions(): void
    {
        $this->simulate(['version' => '1.5']);
        file_put_contents($this->tmp . '/l.png', self::labelPng(40, 76));
        $result = $this->printer()->printImage($this->tmp . '/l.png', 3, true, true);
        $this->assertTrue($result['ok'], $result['output']);
        $this->assertCount(3, $this->printed());
        $this->assertSame(['chain printing needs ptouch-print 1.6', 'precut needs ptouch-print 1.8'], $result['notes']);
    }

    public function testPrintFailures(): void
    {
        $this->simulate(['version' => '1.9']);
        file_put_contents($this->tmp . '/tall.png', self::labelPng(40, 128));
        $tall = $this->printer()->printImage($this->tmp . '/tall.png');
        $this->assertFalse($tall['ok']);
        $this->assertStringContainsString('image is too large', $tall['output']);

        $this->simulate(['fail' => 'print']);
        file_put_contents($this->tmp . '/l.png', self::labelPng(40, 76));
        $this->assertFalse($this->printer()->printImage($this->tmp . '/l.png')['ok']);

        $this->simulate(['fail' => '', 'printer' => 'none']);
        $this->assertFalse($this->printer()->printImage($this->tmp . '/l.png')['ok']);
        $this->assertCount(0, $this->printed());
    }

    public function testFromConfig(): void
    {
        $config = \Labeler\Config::load($this->tmp, null, ['LABELER_PTOUCH_BIN' => self::simBin()]);
        $this->simulate(['version' => '1.7']);
        $this->assertSame('1.7', PtouchPrint::fromConfig($config)->version());
    }
}
