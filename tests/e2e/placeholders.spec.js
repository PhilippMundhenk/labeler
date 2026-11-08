// Placeholders and the counter (#17).
import { test, expect, ready, settled, printed } from './fixtures.js';

test('every copy gets its own number, the counter moves on', async ({ page }) => {
  await ready(page);
  await page.locator('.block textarea').fill('Box {n:3}\n{date}');
  await page.locator('summary').click();
  await page.locator('#counter').fill('41');
  await page.locator('#counter').fill('');
  await settled(page);
  await page.locator('#copies').fill('3');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('3 labels printed');
  const jobs = printed();
  expect(jobs).toHaveLength(3);
  expect(jobs.map((j) => [j.copies, j.chain])).toEqual([[1, true], [1, true], [1, false]]);
  await expect(page.locator('#counter')).toHaveValue('44');
  expect(await page.evaluate(() => window.labeler.state.label.counter)).toBe(44);
});

test('a counter print stops at the first failure', async ({ page }) => {
  await ready(page);
  await page.locator('.block textarea').fill('No {n}');
  await settled(page);
  await page.locator('#copies').fill('2');
  await page.route('**/api.php?action=print', (route) => route.fulfill({ status: 502, json: { ok: false, error: 'ptouch-print reported a problem', output: 'jam' } }));
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('reported a problem');
  expect(await page.evaluate(() => window.labeler.state.label.counter)).toBe(1);
});
