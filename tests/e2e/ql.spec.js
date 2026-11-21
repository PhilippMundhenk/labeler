// Brother QL printers on DK media (#139): the server with the ql driver and the simulated QL
// printer (tools/ql-sim) on its own port.
import { test, expect, settled, previewSize, setQl, qlPrinted } from './fixtures.js';
import { QL_PORT } from '../../playwright.config.js';

test.use({ baseURL: `http://127.0.0.1:${QL_PORT}` });

async function open(page, state = {}) {
  setQl(state);
  await page.goto('/');
  await expect(page.locator('#status')).not.toHaveAttribute('data-state', 'busy');
  await settled(page);
}

async function setText(page, text) {
  await page.locator('.block[data-type="text"] textarea').first().fill(text);
  await settled(page);
}

test('a 62 mm DK roll: 300 dpi, a shortest label, only the options a QL printer has', async ({ page }) => {
  await open(page);
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect(page.locator('#status-text')).toHaveText('QL-570 · 62 mm DK roll');
  await expect(page.locator('#tape-info')).toHaveText('62 mm DK roll · 696 px at 300 dpi');
  await expect(page.locator('#manual-tape')).toBeHidden();
  await page.locator('details.options summary').click();
  for (const id of ['precut', 'halfcut', 'hires', 'feed']) await expect(page.locator(`#${id}`)).toBeHidden();
  for (const id of ['chain', 'cut-every']) await expect(page.locator(`#${id}`)).toBeVisible();
  await page.locator('.block[data-type="text"] input[data-k="size"]').fill('6');
  await setText(page, 'A');
  const size = await previewSize(page);
  expect(size.height).toBe(696);
  expect(size.width).toBe(150);
  await expect(page.locator('#length-info')).toHaveText('12.7 mm long');
  await page.locator('.block[data-type="text"] input[data-k="size"]').fill('');
  await setText(page, 'Flour');
  await page.locator('#copies').fill('2');
  await page.locator('#cut-every').fill('2');
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('2 labels printed');
  const pages = qlPrinted();
  expect(pages).toHaveLength(2);
  const printedSize = await previewSize(page);
  expect([pages[0].media, pages[0].height, pages[0].width, pages[0].outside, pages[0].cut_every]).toEqual(['62', 696, printedSize.width, 0, 2]);
  expect(pages[0].black).toBe(printedSize.black);
});

test('die-cut labels have a fixed size; content that does not fit is flagged', async ({ page }) => {
  await open(page, { media: '29x90' });
  await expect(page.locator('#status-text')).toHaveText('QL-570 · 29 × 90 mm');
  await expect(page.locator('#tape-info')).toHaveText('29 × 90 mm DK labels · 306 × 991 px at 300 dpi');
  await setText(page, 'Short');
  expect(await previewSize(page)).toMatchObject({ width: 991, height: 306 });
  await expect(page.locator('#length-info')).toHaveText('83.9 mm long');
  await expect(page.locator('#length-info')).not.toHaveClass(/over/);

  await setText(page, 'A label text that is far too long for an address label');
  await expect(page.locator('#length-info')).toHaveClass(/over/);
  await expect(page.locator('#length-info')).toContainText('the label only 83.9 mm');
  await expect(page.locator('#print')).toBeDisabled();
  await expect(page.locator('#print-hint')).toHaveText('The content does not fit the label');

  await setText(page, 'Erika\nExample\nStreet 1\nCity');
  await expect(page.locator('#print')).toBeEnabled();
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok')).toContainText('Label printed');
  const [label] = qlPrinted();
  expect([label.media, label.width, label.height, label.media_type, label.margin]).toEqual(['29x90', 991, 306, 11, 0]);
});

test('without a DK roll: design for any DK media, templates pick theirs', async ({ page }) => {
  await open(page, { media: 'none' });
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'warn');
  await expect(page.locator('#printer-message')).toHaveText('No DK roll: insert one and close the cover.');
  await expect(page.locator('#manual-tape')).toBeVisible();
  await expect(page.locator('#tape-select')).toHaveValue('dk:62');
  await expect(page.locator('#tape-info')).toHaveText('62 mm DK roll · 696 px at 300 dpi');
  await expect(page.locator('#tape-select optgroup')).toHaveCount(3);

  await page.locator('#tape-select').selectOption('dk:d24');
  await settled(page);
  await expect(page.locator('#tape-info')).toHaveText('Ø 24 mm DK labels · 236 × 236 px at 300 dpi');
  // the default label is longer than a 24 mm round label: flagged, not cut off
  expect(await previewSize(page)).toMatchObject({ height: 236 });
  await expect(page.locator('#length-info')).toHaveClass(/over/);
  await page.locator('.block[data-type="text"] input[data-k="size"]').fill('6');
  await setText(page, 'A');
  expect(await previewSize(page)).toMatchObject({ width: 236, height: 236 });
  await expect(page.locator('#length-info')).toHaveText('20 mm long');

  await page.locator('#templates').click();
  await page.locator('[data-template="dk-shipping"]').click();
  await settled(page);
  await expect(page.locator('#tape-select')).toHaveValue('dk:62x100');
  await expect(page.locator('#tape-info')).toHaveText('62 × 100 mm DK labels · 696 × 1109 px at 300 dpi');
  expect(await page.evaluate(() => window.labeler.state.errors)).toEqual({});

  // the choice is kept, P-Touch tape is still there
  await page.reload();
  await settled(page);
  await expect(page.locator('#tape-select')).toHaveValue('dk:62x100');
  await page.locator('#tape-select').selectOption('24');
  await settled(page);
  await expect(page.locator('#tape-info')).toHaveText('24 mm tape · 128 px at 180 dpi');
});

test('a template for other DK media warns; the details name the driver', async ({ page }) => {
  await open(page);
  await page.locator('#templates').click();
  await page.locator('[data-template="dk-address"]').click();
  await expect(page.locator('.toast.warn')).toContainText('is made for 29 × 90 mm DK labels');
  await page.locator('#templates').click();
  await page.locator('[data-template="dk-note"]').click();
  await expect(page.locator('.toast.ok').last()).toContainText('Template "Note with date (DK 62 mm)"');
  await settled(page);
  await expect(page.locator('#print')).toBeEnabled();
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok').last()).toContainText('printed');

  await page.locator('#status').click();
  const info = page.locator('#details-info');
  await expect(info).toContainText('Brother QL, built in');
  await expect(info).toContainText('62 mm DK roll, 696 px, 300 dpi');
  await expect(page.locator('#details-log code').first()).toHaveText('ql --media 62 --copies 1');
  await page.locator('#details-close').click();
});

test('errors of the QL printer', async ({ page }) => {
  await open(page, { error: 4096 });
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'warn');
  await expect(page.locator('#printer-message')).toHaveText('The cover is open: close it.');
  setQl({ fail: 'jam' });
  await page.locator('#retry').click();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.bad')).toContainText('Cutter jam');
});
