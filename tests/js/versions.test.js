import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT = fileURLToPath(new URL('../../tools/check-versions.js', import.meta.url));

/** Runs tools/check-versions.js in a repo made of the given versions. */
function check({ pkg = '1.2.3', lock = pkg, lockRoot = lock, php = pkg, api = pkg, playwright = '1.50.0', image = playwright,
  changelog = `## [Unreleased]\n\n## [${pkg}] - 2026-01-15\n\n## [1.0.0] - 2026-01-01\n` } = {}, ...args) {
  const dir = mkdtempSync(join(tmpdir(), 'versions-'));
  try {
    mkdirSync(join(dir, 'src'));
    mkdirSync(join(dir, 'docs'));
    mkdirSync(join(dir, 'tools/dev'), { recursive: true });
    writeFileSync(join(dir, 'package.json'), JSON.stringify({ version: pkg }));
    writeFileSync(join(dir, 'package-lock.json'), JSON.stringify({ version: lock,
      packages: { '': { version: lockRoot }, 'node_modules/@playwright/test': { version: playwright } } }));
    writeFileSync(join(dir, 'tools/dev/Dockerfile'), `FROM mcr.microsoft.com/playwright:v${image}-noble\n`);
    writeFileSync(join(dir, 'src/Config.php'), `    public const VERSION = '${php}';\n`);
    writeFileSync(join(dir, 'docs/API.md'), `{"ok": true, "version": "${api}"}\n  "app_version": "${pkg}",\n`);
    writeFileSync(join(dir, 'CHANGELOG.md'), changelog);
    const r = spawnSync(process.execPath, [SCRIPT, ...args], { cwd: dir, encoding: 'utf8' });
    return { status: r.status, out: r.stdout + r.stderr };
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

test('agreeing versions pass, with and without a tag', () => {
  assert.equal(check().status, 0);
  const r = check({}, '--tag', 'v1.2.3');
  assert.equal(r.status, 0, r.out);
  assert.match(r.out, /versions agree: 1\.2\.3/);
});

test('every file that differs from package.json is named', () => {
  const r = check({ lock: '1.2.2', lockRoot: '1.2.1', php: '1.2.0', api: '1.1.0' });
  assert.equal(r.status, 1);
  assert.match(r.out, /package-lock\.json has 1\.2\.2/);
  assert.match(r.out, /packages\[""\]\) has 1\.2\.1/);
  assert.match(r.out, /Config::VERSION\) has 1\.2\.0/);
  assert.match(r.out, /docs\/API\.md has 1\.1\.0/);
});

test('the Playwright image must match @playwright/test', () => {
  const r = check({ image: '1.49.0' });
  assert.equal(r.status, 1);
  assert.match(r.out, /@playwright\/test is 1\.50\.0, tools\/dev\/Dockerfile uses 1\.49\.0/);
});

test('a tag must be vX.Y.Z, match package.json and the newest changelog section', () => {
  assert.match(check({}, '--tag', 'v1.2.4').out, /tag v1\.2\.4 does not match package\.json 1\.2\.3/);
  assert.match(check({}, '--tag', '1.2.3').out, /tag "1\.2\.3" is not vX\.Y\.Z/);
  assert.match(check({}, '--tag').out, /tag "" is not vX\.Y\.Z/);
  const stale = check({ changelog: '## [Unreleased]\n\n## [1.2.2] - 2026-01-10\n' }, '--tag', 'v1.2.3');
  assert.equal(stale.status, 1);
  assert.match(stale.out, /newest dated section in CHANGELOG\.md is 1\.2\.2, not 1\.2\.3/);
  assert.match(check({ changelog: '## [Unreleased]\n' }, '--tag', 'v1.2.3').out, /is missing, not 1\.2\.3/);
});
