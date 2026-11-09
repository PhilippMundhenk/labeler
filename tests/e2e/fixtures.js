// Shared helpers for the browser tests.
//
// Coverage: V8 coverage of every page is written to coverage/v8 in the format c8 reads
// (the same as NODE_V8_COVERAGE), with the served URLs mapped back to the files in public/.
// `npm run coverage` then merges it with the node unit tests and applies one gate.
import { test as base, expect } from '@playwright/test';
import { mkdirSync, writeFileSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { RUN } from '../../playwright.config.js';

const ROOT = resolve(import.meta.dirname, '../..');
const COVERAGE = resolve(ROOT, 'coverage/v8');
let n = 0;

export function setSim(state) {
  writeFileSync(resolve(RUN, 'sim-state.json'), JSON.stringify(state));
}

export function printed() {
  const dir = resolve(RUN, 'printed');
  return readdirSync(dir).filter((f) => f.endsWith('.json')).sort()
    .map((f) => ({ ...JSON.parse(readFileSync(resolve(dir, f), 'utf8')), png: resolve(dir, f.replace('.json', '.png')) }));
}

export function clearPrinted() {
  rmSync(resolve(RUN, 'printed'), { recursive: true, force: true });
  mkdirSync(resolve(RUN, 'printed'), { recursive: true });
}

export const test = base.extend({
  // fresh simulated printer for every test (an auto fixture, so it applies in every spec file)
  // eslint-disable-next-line no-empty-pattern
  reset: [async ({}, use) => {
    setSim({});
    clearPrinted();
    rmSync(resolve(RUN, 'data/print-log.json'), { force: true });
    rmSync(resolve(RUN, 'data/labels.json'), { force: true });
    await use();
  }, { auto: true }],
  page: async ({ page, browserName }, use) => {
    const errors = [];
    page.on('pageerror', (err) => errors.push(err));
    // a fake Iconify API: the tests never use the internet
    await page.route('https://api.iconify.design/**', (route) => {
      const url = new URL(route.request().url());
      if (url.pathname === '/search') {
        const q = url.searchParams.get('query');
        if (q === 'offline') return route.abort();
        if (q === 'busy') return route.fulfill({ status: 503, body: 'busy' });
        if (q === 'locked') return route.fulfill({ json: { icons: ['mdi:lock'] } });
        const all = ['mdi:wifi', 'mdi:wifi-off', 'simple-icons:wifi'];
        const sets = url.searchParams.get('prefixes').split(',');
        return route.fulfill({ json: { icons: q === 'nothing' ? [] : all.filter((id) => sets.includes(id.split(':')[0])) } });
      }
      if (url.pathname.endsWith('/wifi-off.svg')) return route.fulfill({ status: 404, body: 'Not found' });
      if (url.pathname.endsWith('.json')) {
        // previews of a page of results in one request; "lock" stands for a refusing service
        const names = url.searchParams.get('icons').split(',');
        if (names.includes('lock')) return route.fulfill({ status: 429, body: 'Too many requests' });
        const icons = Object.fromEntries(names.filter((n) => n === 'wifi').map((n) => [n, { body: '<path fill="currentColor" d="M12 3L2 21h20z"/>' }]));
        const aliases = names.includes('wlan') ? { wlan: { parent: 'wifi' } } : {};
        return route.fulfill({ json: { prefix: url.pathname.slice(1, -5), width: 24, height: 24, icons: names.includes('wlan') ? { ...icons, wifi: icons.wifi || { body: '<path d="M0 0h1v1z"/>' } } : icons, aliases } });
      }
      return route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="480" viewBox="0 0 24 24"><path fill="#000" d="M12 3L2 21h20z"/></svg>',
      });
    });
    const coverage = browserName === 'chromium';
    // V8 drops a page's counters when it navigates, so take a snapshot before every
    // goto() and reload() and write them all at the end
    const snapshots = [];
    const snapshot = async () => {
      if (!coverage) return;
      snapshots.push(...await page.coverage.stopJSCoverage());
      await page.coverage.startJSCoverage({ resetOnNavigation: false });
    };
    for (const name of ['goto', 'reload']) {
      const original = page[name].bind(page);
      page[name] = async (...args) => {
        await snapshot();
        return original(...args);
      };
    }
    if (coverage) await page.coverage.startJSCoverage({ resetOnNavigation: false });
    await use(page);
    expect(errors, 'no script errors on the page').toEqual([]);
    if (!coverage) return;
    await snapshot();
    const result = snapshots
      .filter((e) => /\/js\/[\w-]+\.js$/.test(new URL(e.url).pathname))
      .map((e) => ({
        scriptId: e.scriptId,
        url: pathToFileURL(resolve(ROOT, 'public', new URL(e.url).pathname.slice(1))).href,
        functions: e.functions,
      }));
    mkdirSync(COVERAGE, { recursive: true });
    n += 1;
    writeFileSync(resolve(COVERAGE, `coverage-e2e-${process.pid}-${n}.json`), JSON.stringify({ result }));
  },
});


/** Waits until the preview shows the current state of the label. */
export async function settled(page) {
  await page.waitForFunction(() => !window.labeler.state.rendering);
  await page.waitForTimeout(80);
  await page.waitForFunction(() => !window.labeler.state.rendering);
}

/** Pretends the server cannot keep saved labels, so the browser storage is used. */
export async function browserLabels(page) {
  await page.route('**/api.php?action=status*', async (route) => {
    const json = await (await route.fetch()).json();
    await route.fulfill({ json: { ...json, shared_labels: false } });
  });
}

export async function ready(page) {
  await page.goto('/');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await settled(page);
}

export async function previewSize(page) {
  await settled(page);
  return page.evaluate(() => ({ width: window.labeler.state.bitmap.width, height: window.labeler.state.bitmap.height, black: window.labeler.state.bitmap.countBlack() }));
}

export { expect };
