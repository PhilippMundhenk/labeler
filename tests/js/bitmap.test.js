import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Bitmap, luminance, threshold, dither } from '../../public/js/bitmap.js';

const rows = (bm) => Array.from({ length: bm.height }, (_, y) =>
  Array.from({ length: bm.width }, (_, x) => (bm.get(x, y) ? '#' : '.')).join(''));

test('get and set ignore pixels outside', () => {
  const bm = new Bitmap(3, 2);
  bm.set(1, 1);
  bm.set(5, 5);
  bm.set(-1, 0);
  bm.set(2, 0, 0);
  assert.equal(bm.get(1, 1), 1);
  assert.equal(bm.get(9, 9), 0);
  assert.equal(bm.get(-1, 0), 0);
  assert.equal(bm.countBlack(), 1);
  assert.equal(new Bitmap(-3, 2.6).width, 0);
  assert.equal(new Bitmap(-3, 2.6).height, 3);
});

test('fillRect clips and can clear', () => {
  const bm = new Bitmap(4, 3);
  bm.fillRect(-1, 1, 3, 9);
  assert.deepEqual(rows(bm), ['....', '##..', '##..']);
  bm.fillRect(0, 2, 4, 1, 0);
  assert.deepEqual(rows(bm), ['....', '##..', '....']);
  bm.fillRect(3, 0, -2, 1);
  assert.equal(bm.countBlack(), 2);
});

test('blit copies black pixels only', () => {
  const dst = new Bitmap(4, 2);
  dst.set(0, 0);
  const src = new Bitmap(2, 2);
  src.set(1, 1);
  dst.blit(src, 2.4, 0);
  assert.deepEqual(rows(dst), ['#...', '...#']);
});

test('invert, frame and ink columns', () => {
  const bm = new Bitmap(5, 4).frame(1);
  assert.deepEqual(rows(bm), ['#####', '#...#', '#...#', '#####']);
  bm.invert();
  assert.deepEqual(rows(bm), ['.....', '.###.', '.###.', '.....']);
  assert.deepEqual(bm.inkColumns(), [1, 3]);
  assert.equal(new Bitmap(3, 3).inkColumns(), null);
  assert.deepEqual(rows(bm.cropColumns(1, 2)), ['..', '##', '##', '..']);
  assert.deepEqual(rows(new Bitmap(6, 5).frame(1, 1)), ['......', '.####.', '.#..#.', '.####.', '......']);
});

test('dashed cut mark column', () => {
  const bm = new Bitmap(3, 10).dashedColumn(2, 2);
  assert.deepEqual(rows(bm).map((r) => r[2]).join(''), '##..##..##');
  assert.equal(new Bitmap(2, 8).dashedColumn(0).countBlack(), 4);
});

test('mirror flips every row', () => {
  const bm = new Bitmap(3, 2);
  bm.set(0, 0);
  bm.set(1, 1);
  assert.deepEqual(rows(bm.mirror()), ['..#', '.#.']);
  assert.deepEqual(rows(bm.mirror()), ['#..', '.#.']);
});

test('luminance treats transparency as white paper', () => {
  const rgba = new Uint8ClampedArray([0, 0, 0, 255, 0, 0, 0, 0, 255, 255, 255, 255, 0, 0, 0, 128]);
  const g = luminance(rgba, 4, 1);
  assert.equal(g[0], 0);
  assert.equal(g[1], 255);
  assert.equal(g[2], 255);
  assert.ok(g[3] > 120 && g[3] < 130);
});

test('threshold and dither', () => {
  const gray = Float32Array.from({ length: 64 * 4 }, () => 128);
  assert.equal(threshold(gray, 64, 4, 129).countBlack(), 256);
  assert.equal(threshold(gray, 64, 4).countBlack(), 0);
  const d = dither(Float32Array.from({ length: 64 * 4 }, () => 127), 64, 4);
  const share = d.countBlack() / 256;
  assert.ok(share > 0.4 && share < 0.6, `mid grey dithers to about half black, got ${share}`);
  assert.equal(dither(new Float32Array(4).fill(255), 2, 2).countBlack(), 0);
  assert.equal(dither(new Float32Array(4).fill(0), 2, 2).countBlack(), 4);
});
