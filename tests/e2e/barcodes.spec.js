// Barcode types (#22): the printed EAN-13 scans, errors are shown.
import { readFileSync } from 'node:fs';
import { test, expect, ready, settled, printed, setSim } from './fixtures.js';
import { parsePng, decode } from '../js/helpers.js';

test('EAN-13 with check digit, UPC-A and Code 39 errors', async ({ page }) => {
  setSim({ tape_mm: 24 });
  await ready(page);
  await page.locator('[data-act="delete"]').click();
  await page.locator('[data-add="barcode"]').click();
  const bc = page.locator('.block[data-type="barcode"]');
  await bc.getByLabel('Type').selectOption('ean13');
  await expect(bc.locator('.hint')).toHaveText('12 digits (check digit added) or 13 digits.');
  await bc.getByLabel('Content').fill('400638133393');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  const p = parsePng(readFileSync(printed()[0].png));
  expect(decode({ width: p.width, height: p.height, data: Uint8Array.from(p.pixels) }, 'ean13', 2)).toBe('4006381333931');

  await bc.getByLabel('Content').fill('4006381333932');
  await settled(page);
  await expect(bc.locator('.block-error')).toHaveText('wrong check digit, it should be 1');
  await bc.getByLabel('Type').selectOption('upca');
  await bc.getByLabel('Content').fill('03600029145');
  await settled(page);
  await expect(bc.locator('.block-error')).toHaveText('');
  await bc.getByLabel('Type').selectOption('code39');
  await bc.getByLabel('Content').fill('a_b');
  await settled(page);
  await expect(bc.locator('.block-error')).toContainText('cannot be encoded in Code 39');
});
