// Undo and redo (#25).
import { test, expect, ready, settled } from './fixtures.js';

const texts = (page) => page.locator('.block textarea').evaluateAll((els) => els.map((e) => e.value));
const types = (page) => page.locator('.block').evaluateAll((els) => els.map((e) => e.dataset.type));

test('undo and redo typing, blocks and start over', async ({ page }) => {
  await ready(page);
  await expect(page.locator('#undo')).toBeDisabled();
  await page.locator('.block textarea').fill('First');
  await page.waitForTimeout(400); // one typing burst is one step
  await page.locator('.block textarea').fill('Second');
  await page.locator('[data-add="spacer"]').click();
  expect(await types(page)).toEqual(['text', 'spacer']);

  await page.locator('#undo').click();
  expect(await types(page)).toEqual(['text']);
  expect(await texts(page)).toEqual(['Second']);
  await page.locator('#undo').click();
  expect(await texts(page)).toEqual(['First']);
  await page.locator('#undo').click();
  expect(await texts(page)).toEqual(['Label']);
  await expect(page.locator('#undo')).toBeDisabled();

  await page.locator('#redo').click();
  await page.locator('#redo').click();
  expect(await texts(page)).toEqual(['Second']);
  await page.locator('#clear').click();
  expect(await texts(page)).toEqual(['Label']);

  // keyboard: outside text fields Ctrl+Z undoes the label, Ctrl+Shift+Z / Ctrl+Y redo
  await page.locator('#preview').click();
  await page.keyboard.press('Control+z');
  expect(await texts(page)).toEqual(['Second']);
  await page.keyboard.press('Control+Shift+z');
  expect(await texts(page)).toEqual(['Label']);
  await page.keyboard.press('Control+z');
  await page.keyboard.press('Control+y');
  expect(await texts(page)).toEqual(['Label']);
  await expect(page.locator('#redo')).toBeDisabled();

  // inside a text field Ctrl+Z is the browser's own text undo
  await page.locator('.block textarea').focus();
  await page.keyboard.press('Control+z');
  expect(await types(page)).toEqual(['text']);
  await settled(page);
});

test('loading a saved label can be undone', async ({ page }) => {
  await ready(page);
  await page.locator('.block textarea').fill('Saved one');
  await page.locator('#save-name').fill('S');
  await page.locator('#save-form button').click();
  await page.locator('#clear').click();
  await page.locator('[data-load="S"]').click();
  expect(await texts(page)).toEqual(['Saved one']);
  await page.locator('#undo').click();
  expect(await texts(page)).toEqual(['Label']);
});
