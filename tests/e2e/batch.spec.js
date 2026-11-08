// Batch printing from a list or CSV (#18).
import { test, expect, ready, settled, previewSize, printed } from './fixtures.js';

test('one label per row with column placeholders and the counter', async ({ page }) => {
  await ready(page);
  await expect(page.locator('#batch-info')).toHaveText('No rows yet.');
  await page.locator('#batch-data').fill('room;shelf\nKitchen;1\nBathroom;22\nCellar;3');
  await page.locator('#batch-header').check();
  await expect(page.locator('#batch-info')).toHaveText('3 rows · {room}, {shelf}');
  await page.locator('#batch-on').check();
  await expect(page.locator('#batch-info')).toContainText('No block uses these placeholders yet');
  await page.locator('.block textarea').fill('{room}\nShelf {shelf} · #{n}');
  await expect(page.locator('#batch-info')).toHaveText('3 rows · {room}, {shelf}');
  await expect(page.locator('#batch-pos')).toHaveText('Row 1 of 3');
  await expect(page.locator('#batch-prev')).toBeDisabled();
  const first = await previewSize(page);
  await page.locator('#batch-next').click();
  await expect(page.locator('#batch-pos')).toHaveText('Row 2 of 3');
  const second = await previewSize(page);
  expect(second.width).toBeGreaterThan(first.width);
  await page.locator('#batch-next').click();
  await expect(page.locator('#batch-next')).toBeDisabled();
  await page.locator('#batch-prev').click();
  await expect(page.locator('#print-hint')).toHaveText('3 labels from the batch');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('3 labels printed');
  const jobs = printed();
  expect(jobs.map((j) => j.chain)).toEqual([true, true, false]);
  expect(jobs[1].width_px).toBe(second.width);
  expect(await page.evaluate(() => window.labeler.state.label.counter)).toBe(4);
  await page.reload();
  await expect(page.locator('#batch-on')).toBeChecked();
  await expect(page.locator('#batch-pos')).toHaveText('Row 1 of 3');
});

test('rows from a file, numbered columns, a row that cannot be printed', async ({ page }) => {
  await ready(page);
  await page.locator('.block textarea').fill('{1}');
  await page.locator('[data-add="barcode"]').click();
  await page.locator('.block[data-type="barcode"] input[data-k="content"]').fill('{2}');
  await page.locator('#batch-file').setInputFiles({ name: 'rows.csv', mimeType: 'text/csv', buffer: Buffer.from('A,100\nB,Grüße\n') });
  await expect(page.locator('#batch-on')).toBeChecked();
  await expect(page.locator('#batch-data')).toHaveValue('A,100\nB,Grüße\n');
  await expect(page.locator('#batch-info')).toHaveText('2 rows · {1}, {2}');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('Label 2 cannot be printed');
  expect(printed()).toHaveLength(1);
  await page.locator('#batch-on').uncheck();
  await expect(page.locator('#batch-nav')).toBeHidden();
  await page.locator('#batch-file').setInputFiles([]);
});
