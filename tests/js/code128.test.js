import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PATTERNS, symbols, modules } from '../../public/js/code128.js';
import { Bitmap } from '../../public/js/bitmap.js';
import { decode } from './helpers.js';

function barcode(text, bar = 2, height = 40) {
  const mods = modules(text);
  const bm = new Bitmap(mods.length * bar, height);
  mods.forEach((m, i) => m && bm.fillRect(i * bar, 0, bar, height));
  return bm;
}

const sum = (p) => [...p].reduce((s, c) => s + Number(c), 0);

test('every symbol pattern is 11 modules wide, the stop pattern 13', () => {
  assert.equal(PATTERNS.length, 107);
  PATTERNS.slice(0, 106).forEach((p, i) => {
    assert.equal(p.length, 6, `symbol ${i}`);
    assert.equal(sum(p), 11, `symbol ${i}`);
  });
  assert.equal(sum(PATTERNS[106]), 13);
  assert.equal(new Set(PATTERNS).size, 107, 'patterns are unique');
});

test('code set B with checksum', () => {
  // start B (104), P J J 1 2 3 C, checksum = weighted sum mod 103
  const values = [104, 48, 42, 42, 17, 18, 19, 35];
  const check = values.reduce((s, v, i) => s + v * (i || 1), 0) % 103;
  assert.deepEqual(symbols('PJJ123C'), [...values, check]);
});

test('runs of four or more digits use code set C', () => {
  assert.deepEqual(symbols('1234').slice(0, 3), [105, 12, 34]);
  assert.deepEqual(symbols('12345').slice(0, 5), [104, 17, 99, 23, 45]);
  assert.deepEqual(symbols('AB123456C').slice(0, 8), [104, 33, 34, 99, 12, 34, 56, 100]);
  assert.deepEqual(symbols('A12').slice(0, 4), [104, 33, 17, 18]);
});

test('rejects empty input and characters outside printable ASCII', () => {
  assert.throws(() => symbols(''), /enter/);
  assert.throws(() => symbols('Grüße'), /cannot be encoded/);
  assert.throws(() => symbols('a\tb'), /cannot be encoded/);
});

test('modules include quiet zones and decode with a real barcode reader', () => {
  const mods = modules('X', 10);
  assert.equal(mods.length, 10 + 11 * 3 + 13 + 10);
  assert.equal(mods[0], false);
  assert.equal(mods[10], true);
  for (const text of ['Labeler', '1234567890', 'AB-12345/x', 'Cable 42', '12345']) {
    assert.equal(decode(barcode(text), 'code128'), text);
  }
});
