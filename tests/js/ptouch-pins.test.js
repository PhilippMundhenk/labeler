import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// install.sh cannot use tools/ptouch-fetch.sh when it is piped into sh, so it has its own copy of
// the commits pinned for the release tags of ptouch-print: both lists must be the same
const pins = (file) => [...readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8')
  .matchAll(/^\s+(v[\d.]+)\) echo ([0-9a-f]{40}) ;;$/gm)].map((m) => `${m[1]} ${m[2]}`);

test('install.sh and ptouch-fetch.sh pin the same ptouch-print commits', () => {
  const fetch = pins('tools/ptouch-fetch.sh');
  assert.ok(fetch.length >= 5);
  assert.ok(fetch.some((p) => p.startsWith('v1.9 ')), 'the version install.sh uses by default');
  assert.deepEqual(pins('deploy/install.sh'), fetch);
});
