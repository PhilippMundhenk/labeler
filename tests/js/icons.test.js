import { test } from 'node:test';
import assert from 'node:assert/strict';
import { COLLECTIONS, parseId, iconUrl, searchIcons, svgDataUrl, fetchIcon } from '../../public/js/icons.js';
import { normalizeBlock, newBlock } from '../../public/js/label.js';

const API = 'https://icons.example/';

function fakeFetch(handler) {
  const calls = [];
  const fn = async (url) => {
    calls.push(url);
    return handler(url);
  };
  return { fn, calls };
}

test('icon ids belong to the two collections', () => {
  assert.deepEqual(Object.keys(COLLECTIONS), ['mdi', 'simple-icons']);
  assert.deepEqual(parseId('mdi:router-wireless'), { prefix: 'mdi', name: 'router-wireless' });
  assert.deepEqual(parseId('simple-icons:raspberrypi'), { prefix: 'simple-icons', name: 'raspberrypi' });
  for (const bad of ['fa:home', 'mdi:', 'mdi:../x', 'mdi:Home', '', undefined]) assert.equal(parseId(bad), null, String(bad));
});

test('iconUrl asks for a black SVG of the given height', () => {
  assert.equal(iconUrl(API, 'mdi:home'), 'https://icons.example/mdi/home.svg?height=480&color=%23000000');
  assert.equal(iconUrl('https://a.b', 'simple-icons:github', 32), 'https://a.b/simple-icons/github.svg?height=32&color=%23000000');
  assert.throws(() => iconUrl(API, 'nope'), /unknown icon/);
});

test('searchIcons queries the selected collections and drops foreign ids', async () => {
  const f = fakeFetch(() => new Response(JSON.stringify({ icons: ['mdi:wifi', 'fa:wifi', 'simple-icons:wifi'] })));
  assert.deepEqual(await searchIcons(API, ' wifi ', 'all', 10, f.fn), ['mdi:wifi', 'simple-icons:wifi']);
  assert.equal(f.calls[0], 'https://icons.example/search?query=wifi&prefixes=mdi%2Csimple-icons&limit=10');
  await searchIcons(API, 'git hub', 'simple-icons', 64, f.fn);
  assert.equal(f.calls[1], 'https://icons.example/search?query=git%20hub&prefixes=simple-icons&limit=64');
  assert.deepEqual(await searchIcons(API, 'a', 'all', 64, f.fn), [], 'one letter does not search');
  assert.equal(f.calls.length, 2);
  const empty = fakeFetch(() => new Response('{}'));
  assert.deepEqual(await searchIcons(API, 'xyz', 'all', 64, empty.fn), []);
});

test('searchIcons reports service problems', async () => {
  const down = fakeFetch(() => {
    throw new TypeError('Failed to fetch');
  });
  await assert.rejects(searchIcons(API, 'wifi', 'all', 64, down.fn), /cannot be reached/);
  const error = fakeFetch(() => new Response('busy', { status: 503 }));
  await assert.rejects(searchIcons(API, 'wifi', 'all', 64, error.fn), /HTTP 503/);
});

test('fetchIcon returns the SVG as a data URL', async () => {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><title>Grüße</title><path d="M0 0h24v24z"/></svg>';
  const ok = fakeFetch(() => new Response(`\n${svg}`));
  const url = await fetchIcon(API, 'mdi:square', ok.fn);
  assert.ok(url.startsWith('data:image/svg+xml;base64,'));
  assert.equal(Buffer.from(url.split(',')[1], 'base64').toString('utf8'), `\n${svg}`);
  assert.equal(svgDataUrl('<svg/>'), `data:image/svg+xml;base64,${Buffer.from('<svg/>').toString('base64')}`);
  const missing = fakeFetch(() => new Response('404', { status: 404 }));
  await assert.rejects(fetchIcon(API, 'mdi:nope', missing.fn), /icon mdi:nope not found/);
  const html = fakeFetch(() => new Response('<html>'));
  await assert.rejects(fetchIcon(API, 'mdi:x', html.fn), /not found/);
  const down = fakeFetch(() => {
    throw new TypeError('offline');
  });
  await assert.rejects(fetchIcon(API, 'mdi:x', down.fn), /cannot be reached/);
});

test('icon blocks are normalized', () => {
  assert.deepEqual(Object.keys(newBlock('icon')).sort(), ['icon', 'id', 'invert', 'scale', 'src', 'type']);
  const b = normalizeBlock({ type: 'icon', icon: 'mdi:home', src: 'data:image/svg+xml;base64,AA', invert: 1, scale: 1 });
  assert.deepEqual([b.icon, b.src, b.invert, b.scale], ['mdi:home', 'data:image/svg+xml;base64,AA', true, 10]);
  const bad = normalizeBlock({ type: 'icon', icon: '<script>', src: 'data:text/html,x' });
  assert.deepEqual([bad.icon, bad.src, bad.scale], ['', '', 100]);
});
