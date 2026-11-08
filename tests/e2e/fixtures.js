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
        const all = ['mdi:wifi', 'mdi:wifi-off', 'simple-icons:wifi'];
        const sets = url.searchParams.get('prefixes').split(',');
        return route.fulfill({ json: { icons: q === 'nothing' ? [] : all.filter((id) => sets.includes(id.split(':')[0])) } });
      }
      if (url.pathname.endsWith('/wifi-off.svg')) return route.fulfill({ status: 404, body: 'Not found' });
      return route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="480" viewBox="0 0 24 24"><path fill="#000" d="M12 3L2 21h20z"/></svg>',
      });
    });
    const coverage = browserName === 'chromium';
    if (coverage) await page.coverage.startJSCoverage({ resetOnNavigation: false });
    await use(page);
    expect(errors, 'no script errors on the page').toEqual([]);
    if (!coverage) return;
    const entries = await page.coverage.stopJSCoverage();
    const result = entries
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
