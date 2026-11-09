<?php

declare(strict_types=1);

namespace Labeler\Tests;

use InvalidArgumentException;
use Labeler\Api;
use Labeler\Config;
use Labeler\Labels;

final class LabelsTest extends TestCase
{
    private const LABEL = ['blocks' => [['type' => 'text', 'text' => 'Hi']]];

    public function testSaveListDelete(): void
    {
        $labels = new Labels($this->tmp . '/data');
        $this->assertTrue($labels->enabled());
        $this->assertSame([], $labels->all());
        $labels->save(' b ', self::LABEL);
        $labels->save('A', self::LABEL);
        $labels->save('b', ['blocks' => []]);
        $all = $labels->all();
        $this->assertSame(['A', 'b'], array_keys($all), 'sorted, names trimmed, saved again replaces');
        $this->assertSame([], $all['b']['label']['blocks']);
        $labels->delete('A');
        $labels->delete('missing');
        $this->assertSame(['b'], array_keys($labels->all()));
        file_put_contents($this->tmp . '/data/labels.json', 'broken');
        $this->assertSame([], $labels->all());
        $labels->save('again', self::LABEL);
        $this->assertSame(['again'], array_keys($labels->all()));
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    public static function invalid(): array
    {
        return [
            'empty name' => ['  ', self::LABEL, '1 to 80'],
            'long name' => [str_repeat('x', 81), self::LABEL, '1 to 80'],
            'not a label' => ['x', 'text', 'not a label'],
            'no blocks' => ['x', ['margin' => 1], 'not a label'],
            'too large' => ['x', ['blocks' => [['src' => str_repeat('A', Labels::MAX_BYTES)]]], 'too large'],
        ];
    }

    /**
     * @dataProvider invalid
     * @param mixed $label
     */
    public function testRejects(string $name, $label, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new Labels($this->tmp))->save($name, $label);
    }

    public function testLimitAndUnavailableFolder(): void
    {
        $labels = new Labels($this->tmp);
        $data = [];
        for ($i = 0; $i < Labels::MAX_LABELS; $i++) {
            $data["n$i"] = ['label' => self::LABEL, 'updated' => ''];
        }
        file_put_contents($this->tmp . '/labels.json', json_encode($data));
        $labels->save('n5', self::LABEL); // replacing is fine
        try {
            $labels->save('one more', self::LABEL);
            $this->fail('limit not enforced');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('too many', $e->getMessage());
        }
        $off = new Labels('');
        $this->assertFalse($off->enabled());
        $this->assertSame([], $off->all());
        $this->expectExceptionMessage('not available');
        $off->delete('x');
    }

    public function testTotalSizeAndSafeWriting(): void
    {
        $labels = new Labels($this->tmp);
        $big = ['blocks' => [['src' => str_repeat('A', Labels::MAX_BYTES - 100)]]];
        $saved = 0;
        try {
            for ($i = 0; $i < 30; $i++) {
                $labels->save("big $i", $big);
                $saved++;
            }
            $this->fail('total size not limited');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('too large together', $e->getMessage());
        }
        $this->assertCount($saved, $labels->all(), 'the refused label changed nothing');
        $this->assertLessThanOrEqual(Labels::MAX_TOTAL_BYTES, filesize($this->tmp . '/labels.json'));
        $this->assertFileDoesNotExist($this->tmp . '/labels.json.tmp');

        // a file that cannot be replaced: the old contents stay
        mkdir($this->tmp . '/ro');
        $ro = new Labels($this->tmp . '/ro');
        $ro->save('kept', self::LABEL);
        mkdir($this->tmp . '/ro/labels.json.tmp');
        try {
            $ro->save('new', self::LABEL);
            $this->fail('write error not reported');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot write', $e->getMessage());
        }
        $this->assertSame(['kept'], array_keys($ro->all()));
    }

    public function testApi(): void
    {
        $api = new Api(Config::load($this->tmp, null, ['LABELER_DATA_DIR' => $this->tmp . '/data', 'LABELER_PTOUCH_BIN' => self::simBin()]));
        $call = static function (string $method, string $action, string $body = '', string $type = 'application/json') use ($api): array {
            $r = $api->handle($method, ['action' => $action], $body, $type);
            return [$r->status, json_decode($r->body, true)];
        };
        $this->assertSame([200, ['enabled' => true, 'labels' => []]], $call('GET', 'labels'));
        $this->assertSame(200, $call('POST', 'label-save', (string) json_encode(['name' => 'Box', 'label' => self::LABEL]))[0]);
        $this->assertSame(['Box'], array_keys($call('GET', 'labels')[1]['labels']));
        $this->assertSame(415, $call('POST', 'label-save', '{}', 'text/plain')[0]);
        $this->assertSame(400, $call('POST', 'label-delete', '{"name": 5}')[0]);
        $this->assertSame([422, 'not a label'], [$call('POST', 'label-save', '{"name": "x"}')[0], $call('POST', 'label-save', '{"name": "x"}')[1]['error']]);
        $this->assertSame(200, $call('POST', 'label-delete', '{"name": "Box"}')[0]);
        $this->assertSame([], $call('GET', 'labels')[1]['labels']);
        $this->assertTrue($call('GET', 'status')[1]['shared_labels']);
    }
}
