import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fill, resolveLabel, usesCounter, usesColumns, FILLABLE } from '../../public/js/placeholders.js';

const now = new Date(2026, 0, 5, 9, 7); // 5 January 2026, 09:07 local time

test('date and time', () => {
  assert.equal(fill('{date}', { now }), '2026-01-05');
  assert.equal(fill('{date:dmy}', { now }), '05.01.2026');
  assert.equal(fill('{date:mdy}', { now }), '01/05/2026');
  assert.equal(fill('{time}', { now }), '09:07');
  assert.equal(fill('Packed {datetime}', { now }), 'Packed 2026-01-05 09:07');
  assert.match(fill('{date}'), /^\d{4}-\d{2}-\d{2}$/, 'defaults to now');
});

test('counter with optional width', () => {
  assert.equal(fill('Box {n}', { n: 7 }), 'Box 7');
  assert.equal(fill('A-{n:4}', { n: 42 }), 'A-0042');
  assert.equal(fill('{n:99}', { n: 1 }), '000000000001', 'width is capped');
  assert.equal(fill('{n}'), '1');
});

test('batch columns by number and by name', () => {
  const ctx = { row: ['Kitchen', '42'], headers: ['room', 'id'] };
  assert.equal(fill('{1} / {2} / {3}', ctx), 'Kitchen / 42 / ');
  assert.equal(fill('{room}-{id}', ctx), 'Kitchen-42');
  assert.equal(fill('{other} {1}', { row: ['x'] }), '{other} x');
  assert.equal(fill('{1} {room}', {}), '{1} {room}', 'no batch: left as is');
  assert.equal(fill('{ x\ny }', {}), '{ x\ny }');
});

test('resolveLabel fills only text fields and leaves the original alone', () => {
  const label = { margin: 2, blocks: [
    { id: 'a', type: 'text', text: 'No {n}', font: '{n}' },
    { id: 'b', type: 'qr', content: '{1}' },
  ] };
  const out = resolveLabel(label, { n: 3, row: ['x'] });
  assert.equal(out.blocks[0].text, 'No 3');
  assert.equal(out.blocks[0].font, '{n}');
  assert.equal(out.blocks[1].content, 'x');
  assert.equal(label.blocks[0].text, 'No {n}');
  assert.equal(out.margin, 2);
  assert.ok(FILLABLE.includes('wifiPassword'));
});

test('detects counter and column use', () => {
  const label = (text) => ({ blocks: [{ type: 'text', text }, { type: 'spacer', width: 3 }] });
  assert.equal(usesCounter(label('A {n:3}')), true);
  assert.equal(usesCounter(label('A {nn}')), false);
  assert.equal(usesColumns(label('{2}')), true);
  assert.equal(usesColumns(label('{room}'), ['room']), true);
  assert.equal(usesColumns(label('{room}')), false);
});
