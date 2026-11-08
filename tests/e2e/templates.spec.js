// Templates (#19): every template renders without errors and prints; applying one is undoable.
import { test, expect, ready, settled, previewSize, printed } from './fixtures.js';
import { TEMPLATES } from '../../public/js/templates.js';

test('every template renders and can be printed', async ({ page }) => {
  await ready(page);
  for (const t of TEMPLATES) {
    await page.locator('#templates').click();
    await page.locator(`[data-template="${t.id}"]`).click();
    await expect(page.locator('#template-dialog')).toBeHidden();
    await settled(page);
    expect(await page.evaluate(() => window.labeler.state.errors), t.id).toEqual({});
    expect((await previewSize(page)).black, t.id).toBeGreaterThan(0);
    await expect(page.locator('#print')).toBeEnabled();
  }
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok').last()).toContainText('printed');
  expect(printed()).toHaveLength(1);
});

test('a template replaces the label and Undo brings it back', async ({ page }) => {
  await ready(page);
  await page.locator('.block textarea').fill('Mine');
  await page.locator('#templates').click();
  await expect(page.locator('#template-list li')).toHaveCount(TEMPLATES.length);
  await page.locator('[data-template="asset"]').click();
  await expect(page.locator('.toast.ok')).toContainText('Template "Asset tag"');
  await expect(page.locator('.block')).toHaveCount(2);
  await page.locator('#undo').click();
  await expect(page.locator('.block textarea')).toHaveValue('Mine');
  await page.locator('#templates').click();
  await page.locator('#template-close').click();
  await expect(page.locator('#template-dialog')).toBeHidden();
});
