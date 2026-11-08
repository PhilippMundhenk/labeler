// Documentation screenshots from a demo instance on the simulated printer.
//   tools/dev.sh npm run screenshots
// Writes docs/screenshots/*.png. Demo content only: no real names, places or files.
import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const run = resolve(root, 'test-results/screenshots');
const out = resolve(root, process.env.SHOTS_OUT || 'docs/screenshots');
const PORT = 8098;

rmSync(run, { recursive: true, force: true });
mkdirSync(resolve(run, 'printed'), { recursive: true });
mkdirSync(out, { recursive: true });
writeFileSync(resolve(run, 'state.json'), JSON.stringify({ tape_mm: 24 }));

const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', 'public'], {
  cwd: root,
  stdio: 'ignore',
  env: {
    ...process.env,
    LABELER_PTOUCH_BIN: resolve(root, 'tools/ptouch-sim/ptouch-print'),
    LABELER_DATA_DIR: resolve(run, 'data'),
    LABELER_FONTS_DIR: resolve(run, 'fonts'),
    PTSIM_STATE: resolve(run, 'state.json'),
    PTSIM_OUT: resolve(run, 'printed'),
  },
});

const LOGO = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="46" fill="#000"/>
<path d="M28 52l15 15 30-34" stroke="#fff" stroke-width="12" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

const DEMO = {
  margin: 2, gap: 3, length: 0, align: 'center', frame: false, frameWidth: 2,
  blocks: [
    { id: 'logo', type: 'image', src: `data:image/svg+xml;base64,${Buffer.from(LOGO).toString('base64')}`, name: 'logo.svg', mode: 'threshold', level: 128, invert: false, scale: 80 },
    { id: 'name', type: 'text', text: 'Network switch\nRack 2 · Port 1-24', font: 'sans-serif', size: 0, bold: true, italic: false, align: 'left', invert: false },
    { id: 'qr', type: 'qr', content: 'https://example.com/inventory/0042', level: 'M', scale: 100, quiet: 1 },
  ],
};

async function waitForServer() {
  for (let i = 0; i < 50; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${PORT}/api.php?action=health`)).ok) return;
    } catch {
      // not yet
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('server did not start');
}

async function shot(browser, name, { width, height, scheme = 'light', label = DEMO, state = { tape_mm: 24 }, before }) {
  writeFileSync(resolve(run, 'state.json'), JSON.stringify(state));
  const page = await browser.newPage({ viewport: { width, height }, colorScheme: scheme, deviceScaleFactor: 1 });
  await page.addInitScript((l) => localStorage.setItem('labeler.label', JSON.stringify(l)), label);
  await page.goto(`http://127.0.0.1:${PORT}/`);
  await page.waitForFunction(() => document.querySelector('#status').dataset.state !== 'busy');
  await page.waitForTimeout(500);
  if (before) await before(page);
  await page.waitForTimeout(300);
  await page.screenshot({ path: resolve(out, `${name}.png`), fullPage: false });
  await page.close();
}

await waitForServer();
const browser = await chromium.launch();
try {
  await shot(browser, 'editor', { width: 1100, height: 1000 });
  await shot(browser, 'editor-dark', { width: 1100, height: 1000, scheme: 'dark' });
  await shot(browser, 'phone', { width: 390, height: 844 });
  await shot(browser, 'no-printer', { width: 390, height: 844, state: { printer: 'none' } });
  await shot(browser, 'details', {
    width: 1100,
    height: 800,
    before: async (page) => {
      await page.locator('#print').click();
      await page.waitForTimeout(800);
      await page.evaluate(() => document.querySelectorAll('.toast').forEach((t) => t.remove()));
      await page.locator('#status').click();
      await page.waitForTimeout(400);
    },
  });
} finally {
  await browser.close();
  server.kill();
}
console.log(`screenshots written to ${out}`);
