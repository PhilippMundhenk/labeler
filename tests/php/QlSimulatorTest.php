<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\QlRaster;
use Labeler\Sim\Png as SimPng;
use Labeler\Sim\QlSimulator;

final class QlSimulatorTest extends TestCase
{
    private function sim(array $env = [], string $stateFile = ''): QlSimulator
    {
        return new QlSimulator(QlSimulator::stateFromEnv($env + ['QLSIM_OUT' => $this->tmp . '/out']), $stateFile);
    }

    /** @return list<array{type: int, error_code: int}> */
    private static function replies(string $bytes): array
    {
        return array_map(static function (string $s): array {
            $status = (array) QlRaster::parseStatus($s);
            return ['type' => $status['type'], 'error_code' => $status['error_code']];
        }, $bytes === '' ? [] : str_split($bytes, 32));
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        return array_map(static function (string $f): array {
            return json_decode((string) file_get_contents($f), true);
        }, glob($this->tmp . '/out/label-*.json') ?: []);
    }

    public function testDefaults(): void
    {
        $state = QlSimulator::stateFromEnv([]);
        $this->assertSame(['QL-570', '62', 0, ''], [$state['printer'], $state['media'], $state['error'], $state['fail']]);
        $status = QlRaster::parseStatus($this->sim()->feed(QlRaster::statusRequest()));
        $this->assertSame([0x32, 0x0a, 62, 0, 0], [$status['model_code'], $status['media_type'], $status['media_width_mm'], $status['media_length_mm'], $status['error_code']]);
        $status = QlRaster::parseStatus($this->sim(['QLSIM_MEDIA' => 'none'])->feed("\x1biS"));
        $this->assertSame([0, 1], [$status['media_type'], $status['error_code']]);
    }

    public function testPrintsWhatItGets(): void
    {
        $rows = array_fill(0, 306, str_repeat('0', 50));
        $rows[0] = str_repeat('1', 50);
        $rows[305][0] = '1';
        $sim = $this->sim(['QLSIM_MEDIA' => '29x90']);
        $reply = '';
        // byte by byte: every command must survive being split
        foreach (str_split(QlRaster::job($rows, '29x90', ['copies' => 2, 'cut_every' => 2]), 1) as $byte) {
            $reply .= $sim->feed($byte);
        }
        $types = array_column(self::replies($reply), 'type');
        $this->assertSame([0, 6, 1, 0, 6, 1, 6], $types, 'a status request before every page');
        $records = $this->records();
        $this->assertCount(2, $records);
        $this->assertSame(['29x90', 991, 306, 51, 0, true, 2, true, 0, true], [
            $records[0]['media'], $records[0]['width'], $records[0]['height'], $records[0]['black'], $records[0]['outside'],
            $records[0]['autocut'], $records[0]['cut_every'], $records[0]['cut_at_end'], $records[0]['margin'], $records[0]['starting_page'],
        ]);
        $this->assertFalse($records[1]['starting_page']);
        $this->assertTrue($records[1]['last']);
        $png = \Labeler\Png::rows((string) file_get_contents($this->tmp . '/out/label-0001.png'));
        $this->assertSame(str_repeat('1', 50) . str_repeat('0', 941), $png[0]);
        $this->assertSame('1' . str_repeat('0', 990), $png[305]);
    }

    public function testOtherCommands(): void
    {
        $sim = $this->sim(['QLSIM_OUT' => '']);
        $job = "\x1b@M\x02\x1biz\x86\x0a\x3e\x00\x02\x00\x00\x00\x00\x00Zg\x00\x5a" . str_repeat("\x00", 90) . "\x1a";
        $this->assertSame([6, 1, 6], array_column(self::replies($sim->feed($job)), 'type'));
        $this->assertSame([], $this->records(), 'no output folder: nothing written');
        $this->assertSame('', $sim->feed("\x1b"), 'incomplete');
        $this->assertSame('', $sim->feed("i"));
        $this->assertSame('', $sim->feed("z\x00"));
        $this->assertSame('', $sim->feed("g"));
        $this->assertSame('', $this->sim()->feed('M'));
        $this->assertSame('', $this->sim()->feed("g\x00\x5a\x00"));
    }

    /** @return array<string, array{0: string, 1: int, 2?: array<string, string>}> */
    public static function brokenJobs(): array
    {
        $line = "g\x00\x5a" . str_repeat("\x00", 90);
        $info = "\x1biz\x8e\x0a\x3e\x00\x01\x00\x00\x00\x00\x00";
        return [
            'unknown byte' => ['x', 0x0400],
            'unknown escape' => ["\x1bx", 0x0400],
            'unknown ESC i' => ["\x1biQ", 0x0400],
            'raster before raster mode' => [$info . $line, 0x0400, ['QLSIM_PRINTER' => 'QL-580N']],
            'short raster line' => ["\x1bia\x01g\x00\x02\x00\x00", 0x0400],
            'fewer lines than announced' => ["\x1bia\x01" . $info . "\x1a", 0x0400],
            'print without print information' => ["\x1bia\x01" . $line . "\x1a", 0x0400],
            'die-cut job on a roll' => ["\x1bia\x01\x1biz\x8e\x0b\x3e\x64\x01\x00\x00\x00\x00\x00", 0x0100],
            'wrong width' => ["\x1bia\x01\x1biz\x8e\x0a\x1d\x00\x01\x00\x00\x00\x00\x00", 0x0100],
            'wrong die-cut length' => ["\x1bia\x01\x1biz\x8e\x0b\x1d\x2a\x01\x00\x00\x00\x00\x00", 0x0100, ['QLSIM_MEDIA' => '29x90']],
            'no roll' => ["\x1bia\x01" . $info, 0x0001, ['QLSIM_MEDIA' => 'none']],
            'cover open' => ["\x1bia\x01" . $info . $line . "\x1a", 0x1000, ['QLSIM_ERROR' => '4096']],
            'cutter jam' => ["\x1bia\x01" . $info . $line . "\x1a", 0x0004, ['QLSIM_FAIL' => 'jam']],
        ];
    }

    /**
     * @dataProvider brokenJobs
     * @param array<string, string> $env
     */
    public function testBrokenJobsEndWithAnError(string $job, int $error, array $env = []): void
    {
        $sim = $this->sim($env);
        $replies = self::replies($sim->feed($job));
        $last = $replies[count($replies) - 1];
        $this->assertSame(QlRaster::STATUS_ERROR, $last['type']);
        $this->assertSame($error, $last['error_code'] & ~0x0001 ?: $last['error_code']);
        $this->assertSame('', $sim->feed("\x1biS"), 'the rest of the job is ignored');
        $this->assertSame([], $this->records());
    }

    public function testStateFile(): void
    {
        $file = $this->tmp . '/state.json';
        file_put_contents($file, '{"media": "d58", "delay_ms": 1}');
        $sim = $this->sim([], $file);
        $this->assertSame('d58', $sim->state()['media']);
        $status = QlRaster::parseStatus($sim->feed("\x1biS"));
        $this->assertSame([0x0b, 58, 58], [$status['media_type'], $status['media_width_mm'], $status['media_length_mm']]);
        file_put_contents($file, 'not json');
        $this->assertSame('62', $sim->state()['media']);
        $this->assertSame('62', $this->sim([], $this->tmp . '/missing.json')->state()['media']);
    }

    public function testPngFromRows(): void
    {
        $rows = ['101', '010'];
        $this->assertSame($rows, \Labeler\Png::rows(SimPng::fromRows($rows)));
        $this->assertSame(1, \Labeler\Png::inspect(SimPng::fromRows([]))['width']);
    }
}
