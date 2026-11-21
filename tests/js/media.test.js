import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  DK_MEDIA, DK_MIN_PX, dkMedia, chosenMedia, mediaChoice, mediaChoices, printerMedia, mediaName, mediaInfo, fitLength, overflowMessage,
} from '../../public/js/media.js';

test('the DK media list is the one of the ql driver', () => {
  const php = readFileSync(new URL('../../src/QlRaster.php', import.meta.url), 'utf8');
  const rows = [...php.matchAll(/^\s+'([\w]+)' => \[(\d+), (\d+), (\d+), (\d+), \d+, \d+, (true|false)\],$/gm)];
  assert.ok(rows.length > 20);
  const narrow = Object.fromEntries(rows.filter((r) => r[6] === 'false').map((r) => [r[1], [1, 2, 3, 4].map((i) => Number(r[i + 1]))]));
  assert.deepEqual(DK_MEDIA, narrow);
});

test('media by id and by saved choice', () => {
  assert.deepEqual(dkMedia('62'), {
    kind: 'continuous', mm: 62, lengthMm: 0, px: 696, dpi: 300, lengthPx: 0, minPx: DK_MIN_PX,
    media: '62', round: false, tapeColor: 'White', inkColor: 'Black', live: false,
  });
  const label = dkMedia('29x90', true);
  assert.equal(label.kind, 'die-cut');
  assert.equal(label.lengthPx, 991);
  assert.equal(label.live, true);
  assert.equal(dkMedia('d24').round, true);
  assert.equal(dkMedia('nope'), null);
  assert.equal(chosenMedia('dk:62x100').lengthPx, 1109);
  assert.equal(chosenMedia('dk:unknown').media, '62');
  assert.equal(chosenMedia(24).px, 128);
  assert.equal(chosenMedia('9').kind, 'tape');
  assert.equal(chosenMedia(99).mm, 12, 'unknown tape: the fallback');
  assert.equal(chosenMedia(99, 'dk:29').media, '29');
  assert.equal(mediaChoice(chosenMedia(18)), '18');
  assert.equal(mediaChoice(dkMedia('d58')), 'dk:d58');
});

test('choices for designing without a printer', () => {
  const [tapes, rolls, labels] = mediaChoices();
  assert.equal(tapes[0], 'P-Touch tape');
  assert.deepEqual(tapes[1][0], ['4', '4 mm']);
  assert.deepEqual(rolls[1].map(([v]) => v), ['dk:12', 'dk:29', 'dk:38', 'dk:50', 'dk:54', 'dk:62']);
  assert.ok(labels[1].some(([v, t]) => v === 'dk:29x90' && t === '29 × 90 mm'));
  assert.ok(labels[1].some(([v, t]) => v === 'dk:d24' && t === 'Ø 24 mm'));
});

test('media from the printer status', () => {
  const ptouch = printerMedia({ media_width_mm: 24, tape_px: 128, dpi: 180, tape_color: { name: 'Yellow' }, text_color: { name: 'Black' } });
  assert.deepEqual([ptouch.kind, ptouch.mm, ptouch.px, ptouch.tapeColor, ptouch.live], ['tape', 24, 128, 'Yellow', true]);
  assert.equal(printerMedia({ media_width_mm: 12, tape_px: 76, dpi: 180 }).inkColor, 'Black');
  const roll = printerMedia({ media_width_mm: 62, tape_px: 696, dpi: 300, min_px: 150, media: { id: '62', kind: 'continuous', width_mm: 62, length_mm: null, length_px: null, round: false } });
  assert.deepEqual([roll.kind, roll.mm, roll.lengthPx, roll.minPx, roll.media, roll.live], ['continuous', 62, 0, 150, '62', true]);
  const cut = printerMedia({ media_width_mm: 29, tape_px: 306, dpi: 300, media: { id: '29x90', kind: 'die-cut', width_mm: 29, length_mm: 90, length_px: 991 } });
  assert.deepEqual([cut.kind, cut.lengthMm, cut.lengthPx, cut.minPx, cut.round], ['die-cut', 90, 991, 0, false]);
});

test('names and the line above the preview', () => {
  assert.equal(mediaName(chosenMedia(12)), '12 mm');
  assert.equal(mediaName(dkMedia('62')), '62 mm DK roll');
  assert.equal(mediaName(dkMedia('62x29')), '62 × 29 mm');
  assert.equal(mediaName(dkMedia('d12')), 'Ø 12 mm');
  assert.equal(mediaInfo(chosenMedia(12)), '12 mm tape · 76 px at 180 dpi');
  assert.equal(mediaInfo(dkMedia('29')), '29 mm DK roll · 306 px at 300 dpi');
  assert.equal(mediaInfo(dkMedia('29x90')), '29 × 90 mm DK labels · 306 × 991 px at 300 dpi');
});

test('label length: shortest on rolls, fixed on die-cut labels', () => {
  assert.deepEqual(fitLength(40, chosenMedia(12)), { width: 40, over: 0 });
  assert.deepEqual(fitLength(40, dkMedia('62')), { width: 150, over: 0 });
  assert.deepEqual(fitLength(400, dkMedia('62')), { width: 400, over: 0 });
  assert.deepEqual(fitLength(500, dkMedia('29x90')), { width: 991, over: 0 });
  assert.deepEqual(fitLength(1000, dkMedia('29x90')), { width: 991, over: 9 });
  assert.equal(overflowMessage(500, dkMedia('29x90')), '');
  assert.equal(overflowMessage(1181, dkMedia('29x90')), 'The content is 100 mm long, the label only 83.9 mm: make it shorter or smaller');
});
