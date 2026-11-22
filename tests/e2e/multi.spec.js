// A P-touch and a Brother QL printer behind one interface (#147): the printer picker, the media
// of each printer, and both printing at the same time.
import { test, expect, settled, setMulti, qlPrinted } from './fixtures.js';
import { MULTI_PORT } from '../../playwright.config.js';

test.use({ baseURL: `http://127.0.0.1:${MULTI_PORT}` });

async function open(page) {
  await page.goto('/');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await settled(page);
}

async function pick(page, id) {
  await page.locator('#printer-pick').selectOption(id);
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect.poll(() => page.evaluate(() => window.labeler.state.status.printer_id)).toBe(id);
  await settled(page);
}

test('the picker shows both printers with their media; the choice is kept', async ({ page }) => {
  await open(page);
  const picker = page.locator('#printer-pick');
  await expect(picker).toBeVisible();
  await expect(picker.locator('option')).toHaveText(['P-touch · 12 mm', 'Brother QL · 62 mm DK roll']);
  await expect(picker).toHaveValue('ptouch');
  await expect(page.locator('#tape-info')).toHaveText('12 mm tape · 76 px at 180 dpi');

  await pick(page, 'ql');
  await expect(page.locator('#status-text')).toHaveText('QL-570 · 62 mm DK roll');
  await expect(page.locator('#tape-info')).toHaveText('62 mm DK roll · 696 px at 300 dpi');
  await page.locator('details.options summary').click();
  await expect(page.locator('#halfcut')).toBeHidden();

  await page.reload();
  await settled(page);
  await expect(page.locator('#printer-pick')).toHaveValue('ql');
  await expect(page.locator('#tape-info')).toHaveText('62 mm DK roll · 696 px at 300 dpi');

  // a printer that is not configured any more: back to the first one, no error
  await page.evaluate(() => localStorage.setItem('labeler.printer', JSON.stringify('gone')));
  await page.reload();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect(page.locator('#blocked-dialog')).toBeHidden();
  await expect(page.locator('#printer-pick')).toHaveValue('ptouch');
});

test('one printer prints while the other does too; each job stays on its printer', async ({ page }) => {
  setMulti({ delay_ms: 4000 }, {});
  await open(page);
  await page.locator('.block[data-type="text"] textarea').first().fill('Tape');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('#print')).toBeDisabled();
  await expect(page.locator('#print-hint')).toHaveText('Printing…');

  // the P-touch is still busy: the QL can print meanwhile, on its own media
  await pick(page, 'ql');
  await expect(page.locator('#print')).toBeEnabled();
  await page.locator('.block[data-type="text"] textarea').first().fill('Roll');
  await settled(page);
  await page.locator('#print').click();
  await expect(page.locator('.toast.ok').first()).toContainText('printed');
  expect(qlPrinted('multi-ql-printed')).toHaveLength(1);
  expect(qlPrinted('multi-printed'), 'the P-touch label is not done yet').toHaveLength(0);

  await expect.poll(() => qlPrinted('multi-printed').length, { timeout: 10000 }).toBe(1);
  const [tape] = qlPrinted('multi-printed');
  const [roll] = qlPrinted('multi-ql-printed');
  expect(tape.height_px).toBe(76);
  expect([roll.media, roll.height]).toEqual(['62', 696]);
  await expect(page.locator('.toast.ok')).toHaveCount(2);

  await page.locator('#status').click();
  await expect(page.locator('#details-log li strong')).toHaveText(['P-touch', 'Brother QL']);
  await page.locator('#details-close').click();
});
