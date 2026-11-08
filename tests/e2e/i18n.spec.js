// German interface (#29): every screen is translated, nothing is left over.
import { test, expect, settled, setSim, printed } from './fixtures.js';

test.use({ locale: 'de-DE' });

async function visitEverything(page) {
  for (const type of ['icon', 'qr', 'barcode', 'image', 'spacer']) await page.locator(`[data-add="${type}"]`).click();
  const qr = page.locator('.block[data-type="qr"]');
  for (const kind of ['wifi', 'contact', 'email', 'phone', 'text']) await qr.locator('select[data-k="kind"]').selectOption(kind);
  await page.locator('.block[data-type="barcode"] input[data-k="content"]').fill('Grüße');
  await page.locator('.block[data-type="icon"] [data-icon-query]').fill('wifi');
  await expect(page.locator('[data-icon-pick]').first()).toBeVisible();
  await page.locator('summary').click();
  await page.locator('#batch-data').fill('a;b\nc;d');
  await page.locator('#batch-on').check();
  await page.locator('#frame').check();
  await settled(page);
  await page.locator('#templates').click();
  await page.locator('#template-close').click();
  await page.locator('#status').click();
  await page.locator('#details-close').click();
}

test('the whole interface in German', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#print')).toContainText('Drucken');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect(page.locator('#tape-info')).toHaveText('12 mm Band · 76 px bei 180 dpi');
  await expect(page.locator('html')).toHaveAttribute('lang', 'de');
  await visitEverything(page);
  await page.locator('.block[data-type="barcode"] [data-act="delete"]').click();
  await page.locator('.block[data-type="icon"] [data-act="delete"]').click();
  await page.locator('.block[data-type="image"] [data-act="delete"]').click();
  await page.locator('#batch-on').uncheck();
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('gedruckt');
  expect(printed()).toHaveLength(1);
  await page.locator('#save-name').fill('Kiste');
  await page.locator('#save-form button').click();
  await expect(page.locator('.toast.ok').last()).toContainText('„Kiste“ gespeichert');
  setSim({ printer: 'none' });
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await expect(page.locator('#printer-message')).toContainText('Drucker');
  const left = await page.evaluate(() => [...window.labeler.i18nMisses]);
  expect(left, 'texts without a German translation').toEqual([]);
});

test('switching the language', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#print')).toContainText('Drucken');
  await page.locator('#lang').selectOption('en');
  await expect(page.locator('#print')).toContainText('Print');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await page.locator('#lang').selectOption('de');
  await expect(page.locator('#print')).toContainText('Drucken');
});
