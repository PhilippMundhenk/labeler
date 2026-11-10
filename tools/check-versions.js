// Checks that the version numbers in the repository agree (runs in lint, from the repo root):
// package.json, package-lock.json, Config::VERSION and the examples in docs/API.md; and that the
// Playwright image in tools/dev/Dockerfile matches @playwright/test (Dependabot updates both
// separately). With --tag vX.Y.Z (the release workflow), the tag and the newest changelog section
// must be that version too.
//   node tools/check-versions.js [--tag vX.Y.Z]
import { readFileSync } from 'node:fs';

const errors = [];
const read = (file) => readFileSync(file, 'utf8');
const lock = JSON.parse(read('package-lock.json'));
const pkg = JSON.parse(read('package.json')).version;

const npm = lock.packages['node_modules/@playwright/test'].version;
const image = /playwright:v([\d.]+)-/.exec(read('tools/dev/Dockerfile'))[1];
if (npm !== image) errors.push(`@playwright/test is ${npm}, tools/dev/Dockerfile uses ${image}: update both together`);

const found = [
  ['package-lock.json', lock.version],
  ['package-lock.json (packages[""])', lock.packages[''].version],
  ['src/Config.php (Config::VERSION)', /VERSION = '([^']+)'/.exec(read('src/Config.php'))[1]],
  ...[...read('docs/API.md').matchAll(/"(?:app_)?version": "([^"]+)"/g)].map((m) => ['docs/API.md', m[1]]),
];
for (const [where, version] of found) {
  if (version !== pkg) errors.push(`${where} has ${version}, package.json has ${pkg}`);
}

const i = process.argv.indexOf('--tag');
if (i > 0) {
  const tag = process.argv[i + 1] ?? '';
  const m = /^v(\d+\.\d+\.\d+)$/.exec(tag);
  if (!m) {
    errors.push(`tag "${tag}" is not vX.Y.Z`);
  } else {
    if (m[1] !== pkg) errors.push(`tag ${tag} does not match package.json ${pkg}`);
    const newest = /^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m.exec(read('CHANGELOG.md'));
    if (newest?.[1] !== m[1]) errors.push(`the newest dated section in CHANGELOG.md is ${newest?.[1] ?? 'missing'}, not ${m[1]}`);
  }
}

for (const e of errors) console.error(e);
if (errors.length) process.exit(1);
console.log(`versions agree: ${pkg}`);
