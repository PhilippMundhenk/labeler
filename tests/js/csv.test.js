import { test } from 'node:test';
import assert from 'node:assert/strict';
import { detectSeparator, parseRows, parseBatch } from '../../public/js/csv.js';

test('separator detection', () => {
  assert.equal(detectSeparator('a;b,c;d'), ';');
  assert.equal(detectSeparator('a\tb\tc'), '\t');
  assert.equal(detectSeparator('"x,y";z'), ';', 'separators inside quotes do not count');
  assert.equal(detectSeparator('\n\na,b'), ',');
  assert.equal(detectSeparator('plain list'), null);
  assert.equal(detectSeparator(''), null);
});

test('a plain list gives one column', () => {
  assert.deepEqual(parseRows('Kitchen\r\nBath, 2nd floor\n\n  Cellar  \n'), [['Kitchen'], ['Bath, 2nd floor'], ['Cellar']]);
});

test('CSV with quotes, escaped quotes, line breaks and empty cells', () => {
  const text = 'name,note,id\n"Shelf, top","said ""hi""",1\r\n"two\nlines",,2\n,,\n';
  assert.deepEqual(parseRows(text), [
    ['name', 'note', 'id'],
    ['Shelf, top', 'said "hi"', '1'],
    ['two\nlines', '', '2'],
  ]);
});

test('tab separated values from a spreadsheet', () => {
  assert.deepEqual(parseRows('A1\tB1\nA2\tB2'), [['A1', 'B1'], ['A2', 'B2']]);
  assert.deepEqual(parseRows('a;b', ','), [['a;b']], 'explicit separator');
});

test('header row', () => {
  assert.deepEqual(parseBatch('room;id\nKitchen;1\nBath;2', true), { headers: ['room', 'id'], rows: [['Kitchen', '1'], ['Bath', '2']] });
  assert.deepEqual(parseBatch('Kitchen;1', false), { headers: [], rows: [['Kitchen', '1']] });
  assert.deepEqual(parseBatch('', true), { headers: [], rows: [] });
  assert.deepEqual(parseBatch(undefined), { headers: [], rows: [] });
});
