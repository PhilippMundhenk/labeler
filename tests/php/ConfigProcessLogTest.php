<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\Config;
use Labeler\Log;
use Labeler\Process;

final class ConfigProcessLogTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::load($this->tmp, $this->tmp . '/missing.php', []);
        $this->assertSame('ptouch-print', $config->get('ptouch_bin'));
        $this->assertSame($this->tmp . '/data', $config->get('data_dir'));
        $this->assertSame(60, $config->get('timeout'));
        $this->assertSame(Config::defaults($this->tmp), $config->all());
    }

    public function testFileThenEnvironment(): void
    {
        file_put_contents($this->tmp . '/config.php', "<?php return ['timeout' => '5', 'serial' => ' A1 ', 'unknown' => 1, 'max_copies' => -3];");
        $config = Config::load($this->tmp, null, ['LABELER_TIMEOUT' => '7', 'LABELER_PTOUCH_BIN' => '/opt/pt', 'LABELER_SERIAL' => false]);
        $this->assertSame(7, $config->get('timeout'));
        $this->assertSame('/opt/pt', $config->get('ptouch_bin'));
        $this->assertSame('A1', $config->get('serial'));
        $this->assertSame(0, $config->get('max_copies'));
        $this->assertArrayNotHasKey('unknown', $config->all());
    }

    public function testConfigFileNotReturningAnArrayIsIgnored(): void
    {
        file_put_contents($this->tmp . '/config.php', '<?php // nothing');
        $this->assertSame(60, Config::load($this->tmp, null, [])->get('timeout'));
    }

    public function testProcessMergesOutputAndReturnsTheExitCode(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'echo "out\n"; fwrite(STDERR, "err\n"); exit(3);'], 10);
        $this->assertSame(3, $result['code']);
        $this->assertSame("out\nerr\n", $result['output']);
        $this->assertFalse($result['timed_out']);
    }

    public function testProcessPassesArgumentsWithoutAShell(): void
    {
        $evil = '$(touch ' . $this->tmp . '/pwned) "; touch ' . $this->tmp . '/pwned2';
        $result = Process::run([PHP_BINARY, '-r', 'echo $argv[1];', $evil], 10);
        $this->assertSame($evil, $result['output']);
        $this->assertFileDoesNotExist($this->tmp . '/pwned');
        $this->assertFileDoesNotExist($this->tmp . '/pwned2');
    }

    public function testProcessTimeout(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'sleep(5);'], 1);
        $this->assertTrue($result['timed_out']);
        $this->assertStringContainsString('stopped after 1 s', $result['output']);
        $this->assertLessThan(4000, $result['ms']);
    }

    public function testMissingProgram(): void
    {
        $result = Process::run([$this->tmp . '/no-such-program'], 5);
        $this->assertSame(127, $result['code']);
    }

    public function testLogKeepsTheNewestEntries(): void
    {
        $log = new Log($this->tmp . '/data', 2);
        $this->assertTrue($log->enabled());
        $this->assertSame([], $log->entries());
        $log->add(['--image', '/tmp/labelerAbC.png'], 0, "ok\n", 12);
        $log->add(['--info'], 1, 'two', 3);
        $log->add(['--version'], 0, 'three', 1);
        $entries = $log->entries();
        $this->assertCount(2, $entries);
        $this->assertSame(['--version'], $entries[0]['args']);
        $this->assertSame('two', $entries[1]['output']);
        $first = new Log($this->tmp . '/other', 5);
        $first->add(['--image', '/tmp/x.PNG'], 0, 'ok', 1);
        $this->assertSame(['--image', 'label.png'], $first->entries()[0]['args'], 'temporary file names are not logged');
    }

    public function testLogOffWhenDisabledOrBroken(): void
    {
        $this->assertFalse((new Log('', 5))->enabled());
        $this->assertFalse((new Log($this->tmp, 0))->enabled());
        $off = new Log('', 5);
        $off->add(['--info'], 0, '', 0);
        $this->assertSame([], $off->entries());
        file_put_contents($this->tmp . '/print-log.json', 'not json');
        $this->assertSame([], (new Log($this->tmp, 5))->entries());
        $broken = new Log($this->tmp, 5);
        $broken->add(['--info'], 0, 'after garbage', 0);
        $this->assertSame('after garbage', $broken->entries()[0]['output']);
        mkdir($this->tmp . '/dir-as-file/print-log.json', 0777, true);
        $dirLog = new Log($this->tmp . '/dir-as-file', 5);
        $dirLog->add(['--info'], 0, '', 0);
        $this->assertSame([], $dirLog->entries());
        file_put_contents($this->tmp . '/blocker', 'x');
        $this->assertFalse((new Log($this->tmp . '/blocker/sub', 5))->enabled());
    }
}
