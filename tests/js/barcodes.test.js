import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FORMATS, checkDigit, encodeBarcode } from '../../public/js/barcodes.js';
import { Bitmap } from '../../public/js/bitmap.js';
import { normalizeBlock } from '../../public/js/label.js';
import { decode } from './helpers.js';

function draw(mods, bar = 2, height = 50) {
  const bm = new Bitmap(mods.length * bar, height);
  mods.forEach((m, i) => m && bm.fillRect(i * bar, 0, bar, height));
  return bm;
}

test('check digits of known codes', () => {
  assert.equal(checkDigit('400638133393'), '1'); // EAN-13 4006381333931
  assert.equal(checkDigit('03600029145'), '2'); // UPC-A 036000291452
  assert.equal(checkDigit('00000000000'), '0');
});

test('EAN-13: 95 modules, guard bars, decodes', () => {
  const code = encodeBarcode('ean13', '400638133393');
  assert.equal(code.text, '4006381333931');
  assert.equal(code.modules.length, 95 + 22);
  for (const [from, to] of code.guards) assert.ok(code.modules.slice(from, to).includes(true));
  assert.equal(decode(draw(code.modules), 'ean13'), '4006381333931');
  assert.equal(encodeBarcode('ean13', '4006 3813 3393 1').text, '4006381333931', 'spaces are ignored');
  assert.throws(() => encodeBarcode('ean13', '4006381333932'), /wrong check digit, it should be 1/);
  assert.throws(() => encodeBarcode('ean13', '12345'), /12 or 13 digits/);
  assert.throws(() => encodeBarcode('ean13', 'ABCDEFGHIJKL'), /12 or 13 digits/);
});

test('UPC-A decodes', () => {
  const code = encodeBarcode('upca', '03600029145');
  assert.equal(code.text, '036000291452');
  assert.equal(decode(draw(code.modules), 'upca'), '036000291452');
  assert.throws(() => encodeBarcode('upca', '036000291453'), /check digit/);
});

test('Code 39 decodes, lower case is upper-cased, unknown characters rejected', () => {
  for (const text of ['LABEL-01', 'abc 12.$/+%']) {
    const code = encodeBarcode('code39', text);
    assert.equal(decode(draw(code.modules), 'code39'), text.toUpperCase());
  }
  assert.throws(() => encodeBarcode('code39', 'a_b'), /"_" cannot be encoded in Code 39/);
  assert.throws(() => encodeBarcode('code39', ''), /enter/);
});

test('Code 128 stays the default', () => {
  const code = encodeBarcode(undefined, 'Hello');
  assert.equal(decode(draw(code.modules), 'code128'), 'Hello');
  assert.deepEqual(FORMATS.map((f) => f[0]), ['code128', 'ean13', 'upca', 'code39']);
  assert.equal(normalizeBlock({ type: 'barcode', format: 'qr' }).format, 'code128');
  assert.equal(normalizeBlock({ type: 'barcode', format: 'upca' }).format, 'upca');
});
