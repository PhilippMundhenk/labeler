// The label editor: blocks, styles, layout, saved labels.
import { test, expect, ready, settled, previewSize, setSim } from './fixtures.js';

const block = (page, type, n = 0) => page.locator(`.block[data-type="${type}"]`).nth(n);

test('text styles change the rendering', async ({ page }) => {
  await ready(page);
  const text = block(page, 'text');
  await text.locator('textarea').fill('Hello');
  const base = await previewSize(page);
  await text.getByLabel('Bold').uncheck();
  const thin = await previewSize(page);
  expect(thin.black).toBeLessThan(base.black);
  await text.getByLabel('Italic').check();
  await text.getByText('Left', { exact: true }).click();
  await text.getByLabel('White on black').check();
  const inverted = await previewSize(page);
  expect(inverted.black).toBeGreaterThan(inverted.width * 76 * 0.5);
  await text.getByLabel('Size (pt)').fill('8');
  const small = await previewSize(page);
  expect(small.width).toBeLessThan(inverted.width);
  await text.getByLabel('Size (pt)').fill('');
  await text.getByText('Right', { exact: true }).click();
  await text.locator('textarea').fill('a\nlonger line');
  await settled(page);
  await text.locator('select').selectOption('monospace');
  await settled(page);
  await expect(text.getByLabel('Size (pt)')).toHaveAttribute('placeholder', 'Auto');
  expect(await page.evaluate(() => window.labeler.state.label.blocks[0])).toMatchObject({
    font: 'monospace', size: 0, italic: true, bold: false, invert: true, align: 'right',
  });
});

test('an empty text gives an empty label that cannot be printed', async ({ page }) => {
  await ready(page);
  await block(page, 'text').locator('textarea').fill('   ');
  await settled(page);
  await expect(page.locator('#print')).toBeDisabled();
  await expect(page.locator('#print')).toHaveAttribute('title', 'The label is empty');
  await expect(page.locator('#print-hint')).toHaveText('The label is empty');
});

test('blocks can be added, moved, duplicated and removed', async ({ page }) => {
  await ready(page);
  for (const type of ['qr', 'barcode', 'spacer']) await page.locator(`[data-add="${type}"]`).click();
  const types = () => page.locator('.block').evaluateAll((els) => els.map((e) => e.dataset.type));
  expect(await types()).toEqual(['text', 'qr', 'barcode', 'spacer']);
  await block(page, 'spacer').locator('[data-act="up"]').click();
  expect(await types()).toEqual(['text', 'qr', 'spacer', 'barcode']);
  await expect(block(page, 'spacer').locator('[data-act="up"]')).toBeFocused();
  await block(page, 'text').locator('[data-act="down"]').click();
  expect(await types()).toEqual(['qr', 'text', 'spacer', 'barcode']);
  await block(page, 'barcode').locator('[data-act="down"]').click({ force: true });
  await block(page, 'qr').locator('[data-act="up"]').click({ force: true });
  await block(page, 'qr').locator('[data-act="copy"]').click();
  expect(await types()).toEqual(['qr', 'qr', 'text', 'spacer', 'barcode']);
  await expect(block(page, 'qr', 1).locator('[data-act="copy"]')).toBeFocused();
  await block(page, 'barcode').locator('[data-act="down"]').evaluate((b) => b.removeAttribute('disabled'));
  await block(page, 'barcode').locator('[data-act="down"]').click();
  await expect(block(page, 'barcode').locator('[data-act="copy"]')).toBeFocused();
  while (await page.locator('.block').count()) await page.locator('.block [data-act="delete"]').first().click();
  await expect(page.locator('#empty-hint')).toBeVisible();
  await expect(page.locator('#add-text')).toBeFocused();
  await page.locator('#clear').click();
  expect(await types()).toEqual(['text']);
});

test('QR code options and errors', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="qr"]').click();
  const qr = block(page, 'qr');
  const before = await previewSize(page);
  await qr.getByText('H 30 %').click();
  await qr.getByLabel('Quiet zone (modules)').fill('0');
  await qr.getByLabel('Height').fill('60');
  await expect(qr.locator('output')).toHaveText('60%');
  const after = await previewSize(page);
  expect(after.width).not.toBe(before.width);
  await qr.locator('textarea').fill('x'.repeat(400));
  await settled(page);
  await expect(qr).toHaveClass(/has-error/);
  await expect(qr.locator('.block-error')).toContainText('the QR code needs');
  await expect(page.locator('#print')).toBeDisabled();
  await expect(page.locator('#print')).toHaveAttribute('title', 'Fix the marked blocks first');
  await qr.locator('textarea').fill('');
  await settled(page);
  await expect(qr.locator('.block-error')).toHaveText('enter the QR code content');
});

test('barcode options and errors', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="barcode"]').click();
  const bc = block(page, 'barcode');
  const wide = await previewSize(page);
  await bc.getByLabel('Bar width (px)').fill('1');
  const narrow = await previewSize(page);
  expect(narrow.width).toBeLessThan(wide.width);
  await bc.getByLabel('Show text below').uncheck();
  await bc.getByLabel('Bar width (px)').fill('');
  await settled(page);
  await bc.locator('input[data-k="content"]').fill('Grüße');
  await settled(page);
  await expect(bc.locator('.block-error')).toContainText('cannot be encoded');
});

test('images: upload, conversion, invert and size', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="image"]').click();
  const img = block(page, 'image');
  await settled(page);
  await expect(img.locator('.block-error')).toHaveText('choose an image file');
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></defs><rect width="300" height="200" fill="url(#g)"/><circle cx="150" cy="100" r="60" fill="#000"/></svg>';
  await img.locator('input[type="file"]').setInputFiles({ name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(svg) });
  await expect(img.locator('.hint')).toHaveText('logo.svg');
  await expect(img.locator('.block-error')).toHaveText('');
  const plain = await previewSize(page);
  await img.getByText('Dithered').click();
  const dithered = await previewSize(page);
  expect(dithered.black).not.toBe(plain.black);
  await img.getByLabel('Invert').check();
  await img.getByLabel('Threshold').fill('200');
  await img.getByLabel('Height').fill('50');
  const half = await previewSize(page);
  expect(half.width).toBeLessThan(plain.width);
  await img.locator('input[type="file"]').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
  await expect(img.locator('.block-error')).toContainText('not an image');
});

test('layout: margins, gap, minimum length, position and frame', async ({ page }) => {
  await ready(page);
  await page.locator('[data-add="spacer"]').click();
  await block(page, 'spacer').getByLabel('Width (mm)').fill('10');
  await page.locator('#margin').fill('0');
  await page.locator('#gap').fill('0');
  const tight = await previewSize(page);
  await page.locator('#margin').fill('5');
  const margins = await previewSize(page);
  expect(margins.width - tight.width).toBe(2 * 35);
  await page.locator('#length').fill('100');
  await expect(page.locator('#length-info')).toHaveText('100 mm long');
  await page.locator('#length').fill('');
  await expect(page.locator('#length-info')).not.toHaveText('100 mm long');
  await page.locator('#length').fill('100');
  await page.getByText('Left', { exact: true }).last().click();
  await page.locator('#margin').fill('');
  await expect(page.locator('#frame-width')).toBeDisabled();
  await page.locator('#frame').check();
  await page.locator('#frame-width').fill('4');
  const framed = await previewSize(page);
  expect(framed.black).toBeGreaterThan(margins.black + 2 * 700 * 4);
  expect(await page.evaluate(() => window.labeler.state.label)).toMatchObject({ align: 'left', frame: true, frameWidth: 4, length: 100, margin: 5 });
});

test('cut marks at both ends (#51)', async ({ page }) => {
  await ready(page);
  await settled(page);
  const edge = () => page.evaluate(() => {
    const b = window.labeler.state.bitmap;
    let left = 0;
    let right = 0;
    for (let y = 0; y < b.height; y++) {
      left += b.get(0, y);
      right += b.get(b.width - 1, y);
    }
    return [left, right];
  });
  expect(await edge()).toEqual([0, 0]);
  await page.locator('#cut-marks').check();
  await settled(page);
  const [left, right] = await edge();
  expect(left).toBe(40);
  expect(right).toBe(40);
});

test('rotated blocks (#23)', async ({ page }) => {
  setSim({ tape_mm: 24 });
  await ready(page);
  const text = block(page, 'text');
  await text.locator('textarea').fill('Shelf');
  const flat = await previewSize(page);
  await text.getByText('90°', { exact: true }).click();
  const turned = await previewSize(page);
  expect(turned.height).toBe(128);
  expect(turned.width).toBeLessThan(flat.width);
  await text.getByText('270°', { exact: true }).click();
  expect((await previewSize(page)).width).toBe(turned.width);
  await text.getByText('180°', { exact: true }).click();
  expect((await previewSize(page)).width).toBe(flat.width);
  await text.getByText('90°', { exact: true }).click();
  await text.getByLabel('Size (pt)').fill('40');
  await text.locator('textarea').fill('A very long text that cannot run across');
  await settled(page);
  await expect(text.locator('.block-error')).toContainText('too long to run across the tape');
  await page.locator('[data-add="spacer"]').click();
  await expect(block(page, 'spacer').getByText('90°')).toHaveCount(0);
});

test('mirrored printing flips the label (#50)', async ({ page }) => {
  await ready(page);
  await block(page, 'text').locator('textarea').fill('AB');
  await page.locator('#margin').fill('0');
  await settled(page);
  const plain = await page.evaluate(() => Array.from(window.labeler.state.bitmap.data));
  const width = (await previewSize(page)).width;
  await page.locator('#mirror').check();
  await settled(page);
  const mirrored = await page.evaluate(() => Array.from(window.labeler.state.bitmap.data));
  const flip = (data) => data.map((_, i) => data[Math.floor(i / width) * width + (width - 1 - (i % width))]);
  expect(mirrored).toEqual(flip(plain));
  await page.reload();
  await expect(page.locator('#mirror')).toBeChecked();
});

test('the label survives a reload; saved labels', async ({ page }) => {
  await ready(page);
  await block(page, 'text').locator('textarea').fill('Kitchen');
  await page.waitForTimeout(400);
  await page.reload();
  await expect(block(page, 'text').locator('textarea')).toHaveValue('Kitchen');
  await expect(page.locator('#saved-list')).toContainText('No saved labels yet');
  await page.locator('#save-name').fill('Room');
  await page.locator('#save-form button').click();
  await expect(page.locator('.toast.ok')).toContainText('Saved "Room"');
  await page.locator('#clear').click();
  await expect(block(page, 'text').locator('textarea')).toHaveValue('Label');
  await page.locator('#save-form button').click();
  await page.locator('[data-load="Room"]').click();
  await expect(block(page, 'text').locator('textarea')).toHaveValue('Kitchen');
  await page.locator('[data-remove="Room"]').click();
  await expect(page.locator('#saved-list')).toContainText('No saved labels yet');
});

test('full browser storage is reported', async ({ page }) => {
  await page.addInitScript(() => {
    Storage.prototype.setItem = () => {
      throw new DOMException('full', 'QuotaExceededError');
    };
  });
  await ready(page);
  await block(page, 'text').locator('textarea').fill('x');
  await expect(page.locator('.toast.warn')).toContainText('too large to be kept');
  await page.locator('#save-name').fill('A');
  await page.locator('#save-form button').click();
  await expect(page.locator('.toast.bad')).toContainText('storage is full');
});

test('broken data in the browser storage is ignored', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('labeler.label', '{not json');
    localStorage.setItem('labeler.saved', JSON.stringify({ Old: { blocks: [{ type: 'teleporter' }, { type: 'text', text: 'kept' }] } }));
  });
  await ready(page);
  await expect(block(page, 'text').locator('textarea')).toHaveValue('Label');
  await page.locator('[data-load="Old"]').click();
  await expect(page.locator('.block')).toHaveCount(1);
  await expect(block(page, 'text').locator('textarea')).toHaveValue('kept');
});

test('fonts from the server folder and from the device', async ({ page }) => {
  await page.addInitScript(() => {
    window.queryLocalFonts = async () => [{ family: 'Device Sans' }, { family: 'Device Sans' }, { family: 'Another' }];
  });
  await ready(page);
  const select = block(page, 'text').locator('select');
  await expect(select.locator('optgroup[label="Server"] option')).toHaveText(['broken', 'Test Font']);
  await page.locator('#local-fonts').click();
  await expect(select.locator('optgroup[label="This device"] option')).toHaveText(['Another', 'Device Sans']);
  await expect(page.locator('#local-fonts')).toBeHidden();
  const generic = await previewSize(page);
  await select.selectOption('Test Font');
  await page.waitForTimeout(300);
  const custom = await previewSize(page);
  expect(custom.black).not.toBe(generic.black);
});

test('fonts of the device can be refused', async ({ page }) => {
  await page.addInitScript(() => {
    window.queryLocalFonts = async () => {
      throw new DOMException('denied', 'NotAllowedError');
    };
    localStorage.setItem('labeler.label', JSON.stringify({ blocks: [{ type: 'text', text: 'x', font: 'Gone Font' }] }));
  });
  await ready(page);
  await page.locator('#local-fonts').click();
  await expect(page.locator('#local-fonts')).toBeDisabled();
  await expect(page.locator('#local-fonts')).toHaveText('Fonts of this device are not available');
  await expect(block(page, 'text').locator('select')).toHaveValue('Gone Font');
});

test('no printer: design for a chosen tape, then connect', async ({ page }) => {
  setSim({ printer: 'none' });
  await page.goto('/');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'bad');
  await expect(page.locator('#printer-message')).toContainText('No printer found');
  await expect(page.locator('#print')).toBeDisabled();
  await expect(page.locator('#print')).toHaveAttribute('title', 'No printer ready');
  await page.locator('#tape-select').selectOption('24');
  expect((await previewSize(page)).height).toBe(128);
  setSim({});
  await page.locator('#retry').click();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
  await expect(page.locator('#printer-notice')).toBeHidden();
  expect((await previewSize(page)).height).toBe(76);
});

test('a printer that switched itself off is explained (#49)', async ({ page }) => {
  await ready(page);
  setSim({ printer: 'none' });
  // coming back to the tab checks the printer again
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await expect(page.locator('#printer-message')).toContainText('switched itself off (auto power off)');
  setSim({});
  await page.locator('#retry').click();
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'ok');
});

test('tape colours from the printer are used in the preview', async ({ page }) => {
  setSim({ tape_color: 6, text_color: 1 });
  await ready(page);
  const color = await page.locator('#preview').evaluate((c) => [...c.getContext('2d').getImageData(1, 1, 1, 1).data].slice(0, 3));
  expect(color).toEqual([0xf5, 0xd3, 0x27]);
});

test('the server cannot be reached', async ({ page }) => {
  await page.route('**/api.php?action=*', (route) => route.abort());
  await page.goto('/');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'bad');
  await expect(page.locator('#printer-message')).toHaveText('the server cannot be reached');
  await page.locator('#status').click();
  await expect(page.locator('#details-info')).toContainText('server not reachable');
  await expect(page.locator('#details-log')).toContainText('Nothing printed yet');
});

test('the page comes back to the foreground', async ({ page }) => {
  await ready(page);
  setSim({ tape_mm: 24 });
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await expect(page.locator('#status-text')).toHaveText('PT-P700 · 24 mm');
  await page.setViewportSize({ width: 800, height: 700 });
  await settled(page);
});

test('phone layout has no horizontal scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 740 });
  await ready(page);
  for (const type of ['qr', 'barcode', 'image', 'spacer']) await page.locator(`[data-add="${type}"]`).click();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('the print log can be off', async ({ page }) => {
  await page.route('**/api.php?action=log', (route) => route.fulfill({ json: { enabled: false, entries: [] } }));
  await ready(page);
  await page.locator('#status').click();
  await expect(page.locator('#details-log')).toContainText('The print log is off');
});

test('printer not ready, older status without colours', async ({ page }) => {
  setSim({ mode: 'plite' });
  await page.goto('/');
  await expect(page.locator('#status')).toHaveAttribute('data-state', 'warn');
  await expect(page.locator('#printer-message')).toContainText('P-Lite');
  await page.unroute('**/*');
  await page.route('**/api.php?action=status', async (route) => {
    const res = await route.fetch();
    const json = await res.json();
    json.printer = { ...json.printer, ready: true, mode: undefined, model: null, tape_color: null, text_color: null, media_width_mm: 12, tape_px: 76, dpi: 180 };
    json.features = undefined;
    json.max_copies = undefined;
    json.agent = { url: 'http://printer-pi:8631', reachable: false };
    await route.fulfill({ json });
  });
  await page.locator('#retry').click();
  await expect(page.locator('#status-text')).toHaveText('Printer · 12 mm');
  await expect(page.locator('#chain')).toBeDisabled();
  await page.locator('#status').click();
  await expect(page.locator('#details-info')).toContainText('Serial');
  await expect(page.locator('#details-info')).toContainText('http://printer-pi:8631 (not reachable)');
});
