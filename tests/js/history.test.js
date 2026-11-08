import { test } from 'node:test';
import assert from 'node:assert/strict';
import { History } from '../../public/js/history.js';

test('undo and redo walk the recorded states', () => {
  const h = new History();
  h.reset('a');
  assert.equal(h.canUndo, false);
  assert.equal(h.undo(), null);
  assert.equal(h.push('a'), false, 'unchanged states are not recorded');
  assert.equal(h.push('b'), true);
  h.push('c');
  assert.equal(h.undo(), 'b');
  assert.equal(h.undo(), 'a');
  assert.equal(h.undo(), null);
  assert.equal(h.redo(), 'b');
  assert.equal(h.canRedo, true);
  h.push('x');
  assert.equal(h.canRedo, false, 'a new change drops the redo states');
  assert.equal(h.redo(), null);
  assert.equal(h.undo(), 'b');
});

test('the history is limited', () => {
  const h = new History(3);
  h.reset('0');
  for (let i = 1; i <= 10; i++) h.push(String(i));
  const back = [];
  for (let s = h.undo(); s !== null; s = h.undo()) back.push(s);
  assert.deepEqual(back, ['9', '8', '7']);
  const fresh = new History();
  fresh.push('first');
  assert.equal(fresh.canUndo, false, 'nothing before the first state');
});
