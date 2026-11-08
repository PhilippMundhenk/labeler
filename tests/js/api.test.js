import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { getStatus, getFonts, getLog, fontUrl, printLabel } from '../../public/js/api.js';

let calls;
beforeEach(() => {
  calls = [];
});

function fake(handler) {
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return handler(url, options);
  };
}

test('GET calls return the JSON body', async () => {
  fake(() => new Response(JSON.stringify({ printer: { ready: true } }), { status: 200 }));
  const res = await getStatus();
  assert.deepEqual(res, { ok: true, status: 200, data: { printer: { ready: true } } });
  assert.equal(calls[0].options.cache, 'no-store');
  await getFonts();
  await getLog();
  assert.deepEqual(calls.map((c) => c.url), ['api.php?action=status', 'api.php?action=fonts', 'api.php?action=log']);
});

test('non-JSON answers and network errors become an error message', async () => {
  fake(() => new Response('<h1>502 Bad Gateway</h1>', { status: 502 }));
  assert.deepEqual(await getStatus(), { ok: false, status: 502, data: { error: 'unexpected answer from the server (HTTP 502)' } });
  fake(() => {
    throw new TypeError('Failed to fetch');
  });
  assert.deepEqual(await getStatus(), { ok: false, status: 0, data: { error: 'the server cannot be reached' } });
});

test('printLabel posts JSON', async () => {
  fake(() => new Response('{"ok":true}', { status: 200 }));
  await printLabel('AAAA', { copies: 2, chain: true });
  const { options } = calls[0];
  assert.equal(calls[0].url, 'api.php?action=print');
  assert.equal(options.method, 'POST');
  assert.equal(options.headers['Content-Type'], 'application/json');
  assert.deepEqual(JSON.parse(options.body), { png: 'AAAA', copies: 2, chain: true });
});

test('fontUrl encodes the file name', () => {
  assert.equal(fontUrl('My Font&1.ttf'), 'api.php?action=font&file=My%20Font%261.ttf');
});
