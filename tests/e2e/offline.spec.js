// Caching (#32): validators and 304 from the router, offline start through the service worker.
import { test, expect } from './fixtures.js';

test('static files are revalidated with 304', async ({ request }) => {
  const first = await request.get('/js/main.js', { headers: { 'Accept-Encoding': 'gzip' } });
  expect(first.status()).toBe(200);
  expect(first.headers()['cache-control']).toBe('no-cache');
  expect(first.headers()['content-encoding']).toBe('gzip');
  const again = await request.get('/js/main.js', { headers: { 'If-None-Match': first.headers().etag } });
  expect(again.status()).toBe(304);
  expect((await request.get('/api.php?action=health')).ok()).toBe(true);
});

test('the app shell starts without the server', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ serviceWorkers: 'allow' });
  const page = await context.newPage();
  await page.goto(baseURL);
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload();
  await expect(page.locator('#status')).not.toHaveAttribute('data-state', 'busy');
  await context.setOffline(true);
  await page.reload();
  await expect(page.locator('.brand')).toHaveText('Labeler');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'bad');
  await expect(page.locator('#printer-message')).toHaveText('the server cannot be reached');
  await context.close();
});
