import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  TAPES, BLOCK_DEFAULTS, mmToPx, pxToMm, tapePx, newBlock, defaultLabel, normalizeBlock, normalizeLabel,
  layout, fitFontSize, ptToPx, cssFont, newId,
} from '../../public/js/label.js';

test('unit conversions', () => {
  assert.equal(mmToPx(25.4, 180), 180);
  assert.equal(pxToMm(180, 180), 25.4);
  assert.equal(ptToPx(72, 180), 180);
  assert.equal(tapePx(12), 76);
  assert.equal(tapePx(24, 360), 320);
  assert.equal(tapePx(36, 180, 128), 128, 'limited by the printhead');
  assert.equal(tapePx(13), 0);
  assert.equal(Object.keys(TAPES).length, 8);
});

test('new blocks get defaults and unique ids', () => {
  const a = newBlock('qr');
  const b = newBlock('qr');
  assert.notEqual(a.id, b.id);
  assert.equal(a.level, 'M');
  assert.throws(() => newBlock('nope'), /unknown block/);
  assert.match(newId(), /^b/);
  assert.equal(defaultLabel().blocks[0].type, 'text');
});

test('normalizeBlock cleans every block type', () => {
  assert.equal(normalizeBlock(null), null);
  assert.equal(normalizeBlock({ type: 'video' }), null);
  const t = normalizeBlock({ type: 'text', text: 42, size: 999, align: 'justify', bold: 1, font: '' });
  assert.deepEqual([t.text, t.size, t.align, t.bold, t.font], ['Label', 200, 'center', true, 'sans-serif']);
  assert.ok(t.id);
  const q = normalizeBlock({ type: 'qr', content: 'x', level: 'Z', scale: 5, quiet: 'a' });
  assert.deepEqual([q.content, q.level, q.scale, q.quiet], ['x', 'M', 20, 1]);
  const bc = normalizeBlock({ type: 'barcode', bar: 0 });
  assert.deepEqual([bc.content, bc.text, bc.bar], [BLOCK_DEFAULTS.barcode.content, true, 1]);
  assert.equal(normalizeBlock({ type: 'barcode', text: false }).text, false);
  const img = normalizeBlock({ type: 'image', src: 'javascript:alert(1)', mode: 'dither', level: 300 });
  assert.deepEqual([img.src, img.mode, img.level], ['', 'dither', 254]);
  assert.equal(normalizeBlock({ type: 'image', src: 'data:image/png;base64,AA' }).src, 'data:image/png;base64,AA');
  assert.equal(normalizeBlock({ type: 'spacer', width: -4 }).width, 0);
});

test('normalizeLabel survives garbage and limits the block count', () => {
  const l = normalizeLabel('nonsense');
  assert.deepEqual(l.blocks, []);
  assert.equal(l.margin, 2);
  const blocks = Array.from({ length: 40 }, () => ({ type: 'spacer' }));
  const many = normalizeLabel({ blocks, align: 'x', frame: 1, frameWidth: 50 });
  assert.equal(many.blocks.length, 30);
  assert.equal(many.align, 'center');
  assert.equal(many.frame, true);
  assert.equal(many.frameWidth, 10);
});

test('layout places blocks with margins, gaps, minimum length and alignment', () => {
  const base = { margin: 0, gap: 0, length: 0, align: 'left', frame: false, frameWidth: 2 };
  assert.deepEqual(layout([10, 20], base, 180), { width: 30, xs: [0, 10] });
  const spaced = layout([10, 20], { ...base, margin: 25.4 / 18, gap: 25.4 / 36 }, 180); // 10 px, 5 px
  assert.deepEqual(spaced, { width: 55, xs: [10, 25] });
  const long = { ...base, length: 25.4 }; // 180 px
  assert.deepEqual(layout([20], { ...long, align: 'left' }, 180).xs, [0]);
  assert.deepEqual(layout([20], { ...long, align: 'center' }, 180).xs, [80]);
  assert.deepEqual(layout([20], { ...long, align: 'right' }, 180).xs, [160]);
  assert.deepEqual(layout([20], { ...base, frame: true }, 180), { width: 24, xs: [2] });
  assert.equal(layout([], base, 180).width, 1);
});

test('fitFontSize fills the height', () => {
  assert.equal(fitFontSize(1, 76, 1), 76);
  assert.equal(fitFontSize(2, 76, 1), Math.floor(76 / 2.15));
  assert.equal(fitFontSize(0, 76, 1), 76);
  assert.equal(fitFontSize(9, 10, 1), 4, 'never below 4 px');
});

test('cssFont quotes family names', () => {
  assert.equal(cssFont({ font: 'sans-serif', bold: true, italic: true }, 20), 'italic bold 20px sans-serif');
  assert.equal(cssFont({ font: 'Open "Sans"', bold: false, italic: false }, 9), '9px "Open Sans", sans-serif');
});
