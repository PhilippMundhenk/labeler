// Accessibility (#31): axe-core on every screen and state, in light and dark mode.
import AxeBuilder from '@axe-core/playwright';
import { test, expect, ready, settled, setSim } from './fixtures.js';
import { PASSWORD_PORT } from '../../playwright.config.js';

async function check(page, what) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  const found = result.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`);
  expect(found, what).toEqual([]);
}

async function everything(page) {
  for (const type of ['icon', 'qr', 'barcode', 'image', 'spacer']) await page.locator(`[data-add="${type}"]`).click();
  await page.locator('.block[data-type="barcode"] input[data-k="content"]').fill('Grüße'); // an error message
  await page.locator('summary').click();
  await page.locator('#batch-on').check();
  await settled(page);
}

for (const scheme of ['light', 'dark']) {
  test(`editor with every block, ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await ready(page);
    await everything(page);
    await check(page, `editor ${scheme}`);
  });

  test(`dialogs and notices, ${scheme}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    setSim({ printer: 'none' });
    await page.goto('/');
    await expect(page.locator('#printer-notice')).toBeVisible();
    await check(page, `no printer ${scheme}`);
    await page.locator('#status').click();
    await expect(page.locator('#details')).toBeVisible();
    await check(page, `details ${scheme}`);
    await page.locator('#details-close').click();
    await page.locator('#templates').click();
    await check(page, `templates ${scheme}`);
  });
}

test('phone width', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 740 });
  await ready(page);
  await everything(page);
  await check(page, 'phone');
});

test('login dialog', async ({ page }) => {
  await page.goto(`http://127.0.0.1:${PASSWORD_PORT}/`);
  await expect(page.locator('#login-dialog')).toBeVisible();
  await check(page, 'login');
});

test('keyboard only: reach the text, change it, print', async ({ page }) => {
  await ready(page);
  await page.locator('body').click({ position: { x: 5, y: 300 } });
  let reached = false;
  for (let i = 0; i < 40 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => document.activeElement && document.activeElement.matches('.block textarea'));
  }
  expect(reached, 'the label text is reachable with Tab').toBe(true);
  await page.keyboard.press('Control+a');
  await page.keyboard.type('Typed');
  await settled(page);
  const outline = await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
  expect(outline).not.toBe('none');
  await page.keyboard.press('Control+Enter');
  await expect(page.locator('.toast.ok')).toContainText('printed');
  await expect(page.locator('.block textarea')).toHaveValue('Typed');
});
