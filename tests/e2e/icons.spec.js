// Icon search through the (faked) Iconify API.
import { test, expect, ready, settled, previewSize, printed } from './fixtures.js';

test('search, choose and print an icon', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="icon"]').click();
  const block = page.locator('.block[data-type="icon"]');
  await settled(page);
  await expect(block.locator('.block-error')).toHaveText('search for an icon and choose one');
  const search = block.getByLabel('Search icons');
  await expect(search).toBeFocused();
  await search.fill('w');
  await search.fill('wifi');
  await expect(block.locator('[data-icon-pick]')).toHaveCount(3);
  await block.getByText('Brands', { exact: true }).click();
  await expect(block.locator('[data-icon-pick]')).toHaveCount(1);
  await block.getByText('Material', { exact: true }).click();
  await expect(block.locator('[data-icon-pick]')).toHaveCount(2);
  const before = await previewSize(page);
  await block.locator('[data-icon-pick="mdi:wifi"]').click();
  await expect(block.locator('.hint')).toContainText('mdi:wifi · Material Design Icons, Apache 2.0');
  await expect(block.locator('[data-icon-pick="mdi:wifi"]')).toHaveClass(/selected/);
  await expect(block.locator('.block-error')).toHaveText('');
  const after = await previewSize(page);
  expect(after.black).toBeGreaterThan(before.black + 1000);
  await block.getByLabel('Invert').check();
  await block.getByLabel('Height').fill('50');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toBeVisible();
  expect(printed()).toHaveLength(1);
  await page.reload();
  await expect(page.locator('.block[data-type="icon"] .hint')).toContainText('mdi:wifi');
  expect((await previewSize(page)).black).toBeGreaterThan(0);
});

test('icon search problems are shown in the block', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="icon"]').click();
  const block = page.locator('.block[data-type="icon"]');
  const search = block.getByLabel('Search icons');
  await search.fill('nothing');
  await expect(block.locator('.icon-results')).toContainText('No icons found.');
  await search.fill('offline');
  await expect(block.locator('.icon-results')).toContainText('the icon service cannot be reached');
  await search.fill('busy');
  await expect(block.locator('.icon-results')).toContainText('HTTP 503');
  await search.fill('wifi');
  await block.locator('[data-icon-pick="mdi:wifi-off"]').click();
  await expect(block.locator('.icon-results')).toContainText('icon mdi:wifi-off not found');
  await search.fill('');
  await expect(block.locator('[data-icon-pick]')).toHaveCount(0);
});

test('previews come in one request per collection, and once (#87)', async ({ page }) => {
  const requests = [];
  page.on('request', (r) => { if (r.url().includes('api.iconify.design')) requests.push(new URL(r.url()).pathname); });
  await ready(page);
  await page.locator('[data-add="icon"]').click();
  const block = page.locator('.block[data-type="icon"]');
  const search = block.getByLabel('Search icons');
  await search.fill('wifi');
  await expect(block.locator('[data-icon-pick="mdi:wifi"] img')).toHaveAttribute('src', /^data:image\/svg\+xml;base64,/);
  await expect(block.locator('[data-icon-pick="simple-icons:wifi"] img')).toHaveCount(1);
  // unknown to the service: the name stands in for the picture
  await expect(block.locator('[data-icon-pick="mdi:wifi-off"]')).toContainText('wifi-off');
  expect(requests.filter((p) => p.endsWith('.json')).sort()).toEqual(['/mdi.json', '/simple-icons.json']);
  expect(requests.filter((p) => p.endsWith('.svg'))).toEqual([]);
  await search.fill('wif');
  await search.fill('wifi');
  await expect(block.locator('[data-icon-pick]')).toHaveCount(3);
  expect(requests.filter((p) => p.endsWith('.json'))).toHaveLength(2);

  await search.fill('locked');
  await expect(block.locator('.icon-results')).toContainText('No previews: the icon service answered HTTP 429');
  await expect(block.locator('[data-icon-pick="mdi:lock"]')).toContainText('lock');
});

test('icons can be switched off', async ({ page }) => {
  await page.route('**/api.php?action=status', async (route) => {
    const json = await (await route.fetch()).json();
    await route.fulfill({ json: { ...json, icon_api: '' } });
  });
  await ready(page);
  await expect(page.locator('#add-icon')).toBeHidden();
});
