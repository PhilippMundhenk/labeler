// Printing through the real API and the simulated printer.
import { readFileSync } from 'node:fs';
import { test, expect, ready, settled, previewSize, printed, setSim } from './fixtures.js';
import { parsePng, decode } from '../js/helpers.js';

function printedBitmap(record) {
  const p = parsePng(readFileSync(record.png));
  return { width: p.width, height: p.height, data: Uint8Array.from(p.pixels), chunks: p.chunks };
}

async function setText(page, text) {
  await page.locator('.block[data-type="text"] textarea').first().fill(text);
  await settled(page);
}

test('the printer status is shown and the default label prints', async ({ page }) => {
  await ready(page);
  await expect(page.locator('#status-text')).toHaveText('PT-P700 · 12 mm');
  await expect(page.locator('#tape-info')).toHaveText('12 mm tape · 76 px at 180 dpi');
  await expect(page.locator('#printer-notice')).toBeHidden();
  const size = await previewSize(page);
  expect(size.height).toBe(76);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('Label printed');
  const [record] = printed();
  expect(record.width_px).toBe(size.width);
  expect(record.height_px).toBe(76);
  expect(record.black_px).toBe(size.black);
  const bm = printedBitmap(record);
  expect([...bm.chunks.IHDR.subarray(8, 10)]).toEqual([1, 3]);
});

test('two lines, the case that broke with ptouch-print 1.7 (#1)', async ({ page }) => {
  await ready(page);
  await setText(page, 'Line one\nLine two');
  const two = await previewSize(page);
  await setText(page, 'Line one');
  const one = await previewSize(page);
  expect(two.width).toBeLessThan(one.width * 1.2);
  await setText(page, 'Line one\nLine two');
  await page.keyboard.press('Control+Enter');
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(printed()).toHaveLength(1);
});

test('label text never reaches a shell (#2)', async ({ page }) => {
  await ready(page);
  await setText(page, '$(touch /tmp/labeler-pwned) "; rm -rf / #');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(printed()[0].parts).toEqual([{ image: expect.stringMatching(/x76$/) }]);
});

test('a printed QR code and barcode can be scanned', async ({ page }) => {
  setSim({ tape_mm: 24 });
  await ready(page);
  await page.locator('[data-act="delete"]').click();
  await page.locator('[data-add="qr"]').click();
  await page.locator('.block[data-type="qr"] textarea').fill('https://example.com/asset/42');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(decode(printedBitmap(printed()[0]), 'qr', 3)).toBe('https://example.com/asset/42');

  await page.locator('[data-act="delete"]').click();
  await page.locator('[data-add="barcode"]').click();
  await page.locator('.block[data-type="barcode"] input[data-k="content"]').fill('INV-2026-0042');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toHaveCount(2);
  expect(decode(printedBitmap(printed()[1]), 'code128', 2)).toBe('INV-2026-0042');
});

test('copies, chain and precut with ptouch-print 1.9', async ({ page }) => {
  await ready(page);
  await page.locator('#copies').fill('3');
  await page.locator('summary').click();
  await page.locator('#chain').check();
  await expect(page.locator('#precut').locator('..')).toHaveAttribute('title', /only printers with a cutter/);
  await expect(page.locator('#precut').locator('..')).toContainText('Auto cut');
  await page.locator('#precut').check();
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('3 labels printed');
  const records = printed();
  expect(records).toHaveLength(3);
  expect(records[0]).toMatchObject({ copies: 3, chain: true, precut: true, version: '1.9' });
});

test('ptouch-print 1.5: options it lacks are switched off, copies still work', async ({ page }) => {
  setSim({ version: '1.5' });
  await ready(page);
  await expect(page.locator('#chain')).toBeDisabled();
  await expect(page.locator('#precut')).toBeDisabled();
  await expect(page.locator('#precut').locator('..')).toHaveAttribute('title', 'needs ptouch-print 1.8 or newer');
  await page.locator('#copies').fill('2');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('2 labels printed');
  expect(printed().map((r) => r.copies)).toEqual([1, 1]);
});

test('a failing print shows the output of ptouch-print', async ({ page }) => {
  await ready(page);
  setSim({ fail: 'print' });
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('ptouch-print reported a problem');
  await expect(page.locator('.toast.bad pre')).toContainText('LIBUSB_ERROR_PIPE');
  await page.locator('.toast.bad').click();
  await expect(page.locator('.toast.bad')).toHaveCount(0);
});

test('an error during printing is not reported as printed (#47)', async ({ page }) => {
  await ready(page);
  setSim({ fail: 'jam' });
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('After printing, the printer reports: Cutter jam');
  await expect(page.locator('.toast.ok')).toHaveCount(0);
});

test('tape removed after the page loaded', async ({ page }) => {
  await ready(page);
  setSim({ tape_mm: 0 });
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('No tape cassette inserted');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'warn');
  await expect(page.locator('#print')).toBeDisabled();
});

test('a 360 dpi printer gets a label at its resolution', async ({ page }) => {
  setSim({ printer: 'PT-P900W', tape_mm: 36 });
  await ready(page);
  await expect(page.locator('#tape-info')).toHaveText('36 mm tape · 454 px at 360 dpi');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(printed()[0]).toMatchObject({ height_px: 454, dpi: 360 });
  const phys = printedBitmap(printed()[0]).chunks.pHYs;
  expect(phys.readUInt32BE(0)).toBe(Math.round(360 / 0.0254));
});

test('the details dialog shows the printer and the print log', async ({ page }) => {
  await ready(page);
  await page.locator('#status').click();
  await expect(page.locator('#details-info')).toContainText('PT-P700');
  await expect(page.locator('#details-log')).toContainText('Nothing printed yet');
  await page.locator('#details-close').click();
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  await page.locator('#status').click();
  await expect(page.locator('#details-log li').first()).toContainText('ptouch-print --image label.png');
  await page.locator('#details-refresh').click();
  await expect(page.locator('#details')).toBeVisible();
  await expect(page.locator('#details-info')).toContainText('ready');
  await page.keyboard.press('Escape');
  await expect(page.locator('#details')).toBeHidden();
});

test('download the label as PNG', async ({ page }) => {
  await ready(page);
  await page.locator('summary').click();
  const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#download').click()]);
  expect(download.suggestedFilename()).toBe('label.png');
  const p = parsePng(readFileSync(await download.path()));
  expect(p.height).toBe(76);
});
