import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deflateSync } from 'node:zlib';
import { encodePng, packRows, crc32, toBase64, browserDeflate } from '../../public/js/png.js';
import { Bitmap } from '../../public/js/bitmap.js';
import { parsePng } from './helpers.js';

function sample() {
  const bm = new Bitmap(13, 3);
  bm.set(0, 0);
  bm.set(12, 2);
  bm.set(7, 1);
  bm.set(8, 1);
  return bm;
}

test('crc32 matches the zlib reference value', () => {
  assert.equal(crc32(new TextEncoder().encode('123456789')), 0xcbf43926);
  assert.equal(crc32(new Uint8Array(0)), 0);
});

test('packRows writes a filter byte and MSB-first bits per row', () => {
  const raw = packRows(sample());
  assert.equal(raw.length, 3 * 3);
  assert.deepEqual([...raw], [0, 0x80, 0, 0, 0x01, 0x80, 0, 0, 0x08]);
});

test('encodePng produces a 1-bit palette PNG that ptouch-print accepts', async () => {
  const png = await encodePng(sample(), (b) => deflateSync(b), 180);
  const p = parsePng(png);
  assert.deepEqual([...p.signature], [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  assert.deepEqual(p.order.map((c) => c.type), ['IHDR', 'PLTE', 'pHYs', 'IDAT', 'IEND']);
  assert.equal(p.width, 13);
  assert.equal(p.height, 3);
  assert.equal(p.chunks.IHDR[8], 1, 'bit depth');
  assert.equal(p.chunks.IHDR[9], 3, 'colour type palette');
  assert.deepEqual([...p.chunks.PLTE], [255, 255, 255, 0, 0, 0], 'index 0 white, 1 black');
  assert.equal(p.chunks.pHYs.readUInt32BE(0), 7087, 'pixels per metre at 180 dpi');
  assert.deepEqual(p.pixels, [...sample().data]);
  for (const c of p.order) assert.equal(c.crc, crc32(c.body), `CRC of ${c.type}`);
});

test('the default deflate uses CompressionStream', async () => {
  const png = await encodePng(sample());
  assert.deepEqual(parsePng(png).pixels, [...sample().data]);
  const packed = await browserDeflate(new Uint8Array([1, 2, 3]));
  assert.equal(packed[0], 0x78, 'zlib header');
});

test('toBase64 handles large inputs', () => {
  const bytes = new Uint8Array(100000).map((_, i) => i % 256);
  assert.deepEqual([...Buffer.from(toBase64(bytes), 'base64')], [...bytes]);
});
