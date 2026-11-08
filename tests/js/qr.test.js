import { test } from 'node:test';
import assert from 'node:assert/strict';
import { qrMatrix, LEVELS } from '../../public/js/qr.js';
import { Bitmap } from '../../public/js/bitmap.js';
import { decode } from './helpers.js';

function render(text, level, module = 3) {
  const qr = qrMatrix(text, level);
  const bm = new Bitmap((qr.size + 8) * module, (qr.size + 8) * module);
  for (let r = 0; r < qr.size; r++) {
    for (let c = 0; c < qr.size; c++) if (qr.dark(r, c)) bm.fillRect((c + 4) * module, (r + 4) * module, module, module);
  }
  return bm;
}

test('QR codes decode to the original text, including UTF-8', () => {
  const cases = [['https://example.com', 'M'], ['Grüße aus Köln ✓', 'L'], ['WIFI:S:Guest;T:WPA;P:secret;;', 'H']];
  for (const [text, level] of cases) assert.equal(decode(render(text, level), 'qr'), text);
});

test('higher error correction makes a bigger symbol', () => {
  const sizes = LEVELS.map((l) => qrMatrix('https://example.com/some/longer/path', l).size);
  assert.ok(sizes[3] > sizes[0]);
  assert.equal(qrMatrix('x', 'nonsense').size, qrMatrix('x', 'M').size);
});

test('empty and oversized content are reported', () => {
  assert.throws(() => qrMatrix(''), /enter/);
  assert.throws(() => qrMatrix('x'.repeat(4000), 'H'), /too much content/);
});
