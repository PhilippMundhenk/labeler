// Optional password (#30).
import { test, expect, settled } from './fixtures.js';
import { PASSWORD_PORT } from '../../playwright.config.js';

const URL = `http://127.0.0.1:${PASSWORD_PORT}/`;

test('log in, print, log out', async ({ page }) => {
  await page.goto(URL);
  await expect(page.locator('#login-dialog')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#login-dialog')).toBeVisible();
  await page.locator('#login-password').fill('wrong');
  await page.locator('#login-form button').click();
  await expect(page.locator('#login-error')).toHaveText('wrong password');
  await page.locator('#login-password').fill('correct horse');
  await page.locator('#login-form button').click();
  await expect(page.locator('#login-dialog')).toBeHidden();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  await expect(page.locator('#logout')).toBeVisible();
  await page.reload();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect(page.locator('#login-dialog')).toBeHidden();
  await page.locator('#logout').click();
  await expect(page.locator('#login-dialog')).toBeVisible();
});
