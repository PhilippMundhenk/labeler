<?php

declare(strict_types=1);

namespace Labeler\Tests;

use Labeler\StaticFiles;

final class StaticFilesTest extends TestCase
{
    private function files(): StaticFiles
    {
        @mkdir($this->tmp . '/js');
        file_put_contents($this->tmp . '/index.html', '<!doctype html><title>x</title>');
        file_put_contents($this->tmp . '/js/big.js', str_repeat('const a = 1;\n', 400));
        file_put_contents($this->tmp . '/logo.png', "\x89PNG" . str_repeat("\0", 3000));
        file_put_contents($this->tmp . '/api.php', '<?php');
        return new StaticFiles($this->tmp . '/');
    }

    public function testServesWithValidators(): void
    {
        $r = $this->files()->serve('/?x=1');
        $this->assertSame([200, 'text/html; charset=utf-8', 'no-cache'], [$r->status, $r->headers['Content-Type'], $r->headers['Cache-Control']]);
        $this->assertSame('<!doctype html><title>x</title>', $r->body);
        $this->assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', $r->headers['ETag']);
        $this->assertStringEndsWith(' GMT', $r->headers['Last-Modified']);
        $again = $this->files()->serve('/index.html', ['if-none-match' => $r->headers['ETag']]);
        $this->assertSame([304, ''], [$again->status, $again->body]);
    }

    public function testGzipForLargerTextOnly(): void
    {
        $files = $this->files();
        $js = $files->serve('/js/big.js', ['accept-encoding' => 'gzip, deflate, br']);
        $this->assertSame('gzip', $js->headers['Content-Encoding']);
        $this->assertSame(str_repeat('const a = 1;\n', 400), gzdecode($js->body));
        $this->assertArrayNotHasKey('Content-Encoding', $files->serve('/js/big.js')->headers, 'client without gzip');
        $this->assertArrayNotHasKey('Content-Encoding', $files->serve('/index.html', ['accept-encoding' => 'gzip'])->headers, 'small file');
        $this->assertArrayNotHasKey('Content-Encoding', $files->serve('/logo.png', ['accept-encoding' => 'gzip'])->headers, 'binary file');
    }

    public function testLeavesEverythingElseToTheServer(): void
    {
        $files = $this->files();
        $this->assertNull($files->serve('/api.php?action=status'));
        $this->assertNull($files->serve('/missing.css'));
        $this->assertNull($files->serve('/../' . basename($this->tmp) . '/index.html'));
        $this->assertNull($files->serve('/%2e%2e/etc/passwd.js'));
        $this->assertNull($files->serve("/index.html\0.js"));
    }
}
