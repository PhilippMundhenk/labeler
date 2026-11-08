// The Playwright image in tools/dev/Dockerfile must match @playwright/test, or the browser
// binaries do not fit. Dependabot updates both separately; this check catches a mismatch.
import { readFileSync } from 'node:fs';

const lock = JSON.parse(readFileSync('package-lock.json', 'utf8'));
const npm = lock.packages['node_modules/@playwright/test'].version;
const image = /playwright:v([\d.]+)-/.exec(readFileSync('tools/dev/Dockerfile', 'utf8'))[1];
if (npm !== image) {
  console.error(`@playwright/test is ${npm}, tools/dev/Dockerfile uses ${image}: update both together`);
  process.exit(1);
}
const pkg = JSON.parse(readFileSync('package.json', 'utf8')).version;
const php = /VERSION = '([^']+)'/.exec(readFileSync('src/Config.php', 'utf8'))[1];
if (pkg !== php) {
  console.error(`package.json version ${pkg} differs from Config::VERSION ${php}`);
  process.exit(1);
}
