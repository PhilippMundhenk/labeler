// QR code helpers (#21): the printed code holds the right string.
import { readFileSync } from 'node:fs';
import { test, expect, ready, settled, printed, setSim } from './fixtures.js';
import { parsePng, decode } from '../js/helpers.js';

const scanned = (record) => {
  const p = parsePng(readFileSync(record.png));
  return decode({ width: p.width, height: p.height, data: Uint8Array.from(p.pixels) }, 'qr', 3);
};

test('Wi-Fi guest label', async ({ page }) => {
  setSim({ tape_mm: 24 });
  await ready(page);
  await page.locator('[data-add="qr"]').click();
  const qr = page.locator('.block[data-type="qr"]');
  await qr.getByLabel('Content type').selectOption('wifi');
  await expect(qr.getByLabel('Content', { exact: true })).toHaveCount(0);
  await qr.getByLabel('Network name (SSID)').fill('Guest;5G');
  await qr.getByLabel('Password', { exact: true }).fill('correct horse');
  await qr.getByLabel('Hidden network').check();
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(scanned(printed()[0])).toBe('WIFI:T:WPA;S:Guest\\;5G;P:correct horse;H:true;;');
  await qr.getByLabel('Security').selectOption('nopass');
  await expect(qr.getByLabel('Password', { exact: true })).toHaveCount(0);
});

test('contact, e-mail and phone fields, with errors', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="qr"]').click();
  const qr = page.locator('.block[data-type="qr"]');
  await qr.getByLabel('Content type').selectOption('contact');
  await settled(page);
  await expect(qr.locator('.block-error')).toContainText('enter a name, phone number or e-mail address');
  for (const [label, value] of [['Name', 'Max Mustermann'], ['Organisation', 'Example'], ['Phone', '+49 30 1'], ['E-mail', 'max@example.com'], ['Website', 'https://example.com']]) {
    await qr.getByLabel(label, { exact: true }).fill(value);
  }
  await settled(page);
  await expect(qr.locator('.block-error')).toHaveText('');
  await qr.getByLabel('Content type').selectOption('email');
  await expect(qr.getByLabel('E-mail', { exact: true })).toHaveValue('max@example.com');
  await qr.getByLabel('Subject').fill('Box 4');
  await qr.getByLabel('Content type').selectOption('phone');
  await expect(qr.getByLabel('Phone', { exact: true })).toHaveValue('+49 30 1');
  await settled(page);
  await expect(qr.locator('.block-error')).toHaveText('');
});
