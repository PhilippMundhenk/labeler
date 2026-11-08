// Renders a label into a black and white bitmap of the tape's printable height.
import { Bitmap, luminance, threshold, dither } from './bitmap.js';
import { qrMatrix } from './qr.js';
import { buildQrContent } from './qrcontent.js';
import { modules } from './code128.js';
import { layout, fitFontSize, ptToPx, mmToPx, cssFont } from './label.js';

const MEASURE = 'ÁHgjy|';
const images = new Map();

function context(w, h) {
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, w);
  canvas.height = Math.max(1, h);
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = '#000';
  return ctx;
}

function toBitmap(ctx, w, h, mode = 'threshold', level = 128) {
  const { data } = ctx.getImageData(0, 0, w, h);
  const gray = luminance(data, w, h);
  return mode === 'dither' ? dither(gray, w, h, level) : threshold(gray, w, h, level);
}

async function fontMetrics(block) {
  await document.fonts.load(cssFont(block, 100), MEASURE).catch(() => {});
  const ctx = context(1, 1);
  ctx.font = cssFont(block, 100);
  const m = ctx.measureText(MEASURE);
  const ascent = m.actualBoundingBoxAscent || 80;
  const descent = m.actualBoundingBoxDescent || 20;
  return { ascent: ascent / 100, descent: descent / 100, ratio: (ascent + descent) / 100 };
}

async function renderText(b, H, dpi) {
  const lines = b.text.replace(/\s+$/, '').split('\n');
  if (!lines.join('').trim()) return new Bitmap(0, H);
  const metrics = await fontMetrics(b);
  const vpad = b.invert ? Math.round(H * 0.08) : 0;
  const size = b.size > 0 ? ptToPx(b.size, dpi) : fitFontSize(lines.length, H - 2 * vpad, metrics.ratio);
  const font = cssFont(b, size);
  await document.fonts.load(font, b.text).catch(() => {});
  const lineH = metrics.ratio * size;
  const gap = lineH * 0.15;
  const probe = context(1, 1);
  probe.font = font;
  const textW = Math.ceil(Math.max(...lines.map((l) => probe.measureText(l).width)));
  const hpad = b.invert ? Math.round(lineH * 0.3) : 0;
  const W = Math.max(1, textW + 2 * hpad);
  const ctx = context(W, H);
  ctx.font = font;
  ctx.textBaseline = 'alphabetic';
  ctx.textAlign = b.align;
  const x = b.align === 'left' ? hpad : b.align === 'right' ? W - hpad : W / 2;
  const total = lines.length * lineH + (lines.length - 1) * gap;
  const top = (H - total) / 2;
  lines.forEach((line, i) => ctx.fillText(line, x, top + i * (lineH + gap) + metrics.ascent * size));
  const bm = toBitmap(ctx, W, H);
  return b.invert ? bm.invert() : bm;
}

function renderQr(b, H) {
  const qr = qrMatrix(buildQrContent(b), b.level);
  const n = qr.size + 2 * b.quiet;
  const module = Math.floor((H * b.scale) / 100 / n);
  if (module < 1) throw new Error(`the QR code needs ${n} px, the tape has ${H} px: shorten the content or lower the error correction`);
  const side = n * module;
  const bm = new Bitmap(side, H);
  const y0 = Math.floor((H - side) / 2);
  for (let r = 0; r < qr.size; r++) {
    for (let c = 0; c < qr.size; c++) {
      if (qr.dark(r, c)) bm.fillRect((c + b.quiet) * module, y0 + (r + b.quiet) * module, module, module);
    }
  }
  return bm;
}

function renderBarcode(b, H) {
  const mods = modules(b.content, 10);
  const W = mods.length * b.bar;
  const avail = Math.floor((H * b.scale) / 100);
  const textH = b.text ? Math.round(avail * 0.24) : 0;
  const barH = avail - textH;
  const y0 = Math.floor((H - avail) / 2);
  const bm = new Bitmap(W, H);
  mods.forEach((bar, i) => {
    if (bar) bm.fillRect(i * b.bar, y0, b.bar, barH);
  });
  if (textH >= 6) {
    const ctx = context(W, textH);
    ctx.font = `${Math.round(textH * 0.95)}px monospace`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    ctx.fillText(b.content, W / 2, textH);
    bm.blit(toBitmap(ctx, W, textH), 0, y0 + barH);
  }
  return bm;
}

export function loadImage(src) {
  if (!images.has(src)) {
    images.set(src, new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('the image cannot be read'));
      img.src = src;
    }));
  }
  return images.get(src);
}

async function renderImage(b, H) {
  if (!b.src) throw new Error(b.type === 'icon' ? 'search for an icon and choose one' : 'choose an image file');
  const img = await loadImage(b.src);
  const h = Math.max(1, Math.floor((H * b.scale) / 100));
  const w = Math.max(1, Math.min(4000, Math.round(((img.naturalWidth || h) * h) / (img.naturalHeight || h))));
  const ctx = context(w, h);
  ctx.drawImage(img, 0, 0, w, h);
  const part = toBitmap(ctx, w, h, b.mode, b.level);
  if (b.invert) part.invert();
  const bm = new Bitmap(w, H);
  bm.blit(part, 0, Math.floor((H - h) / 2));
  return bm;
}

async function renderBlock(b, H, dpi) {
  switch (b.type) {
    case 'text': return renderText(b, H, dpi);
    case 'qr': return renderQr(b, H);
    case 'barcode': return renderBarcode(b, H);
    case 'image': return renderImage(b, H);
    case 'icon': return renderImage({ ...b, mode: 'threshold', level: 128 }, H);
    default: return new Bitmap(mmToPx(b.width, dpi), H);
  }
}

/**
 * @returns {Promise<{bitmap: Bitmap, errors: Object<string, string>}>}
 */
export async function renderLabel(label, tape) {
  const H = tape.px;
  const errors = {};
  const parts = [];
  for (const block of label.blocks) {
    try {
      parts.push(await renderBlock(block, H, tape.dpi));
    } catch (e) {
      errors[block.id] = e.message || String(e);
      parts.push(new Bitmap(0, H));
    }
  }
  const { width, xs } = layout(parts.map((p) => p.width), label, tape.dpi);
  const bitmap = new Bitmap(width, H);
  parts.forEach((p, i) => bitmap.blit(p, xs[i], 0));
  if (label.frame) bitmap.frame(label.frameWidth);
  return { bitmap, errors };
}

/**
 * Draws the bitmap as it will look on the tape: tape and ink colour, the tape's full
 * width with the printable band in the middle.
 */
export function drawPreview(canvas, bitmap, tape, colors, maxScale = 6) {
  const tapeH = Math.max(bitmap.height, Math.round(((tape.mm || 12) / 25.4) * tape.dpi));
  const avail = canvas.parentElement.clientWidth;
  // the preview stays on screen while editing, so it may take at most ~28 % of the window height
  const maxHeight = Math.max(90, window.innerHeight * 0.28);
  const scale = Math.max(0.25, Math.min(maxScale, (avail - 2) / Math.max(1, bitmap.width), maxHeight / tapeH));
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(tapeH * scale));
  canvas.style.aspectRatio = `${canvas.width} / ${canvas.height}`;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.fillStyle = colors.tape;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  const off = document.createElement('canvas');
  off.width = Math.max(1, bitmap.width);
  off.height = Math.max(1, bitmap.height);
  const octx = off.getContext('2d');
  const img = octx.createImageData(off.width, off.height);
  const ink = hex(colors.ink);
  for (let i = 0; i < bitmap.data.length; i++) {
    if (bitmap.data[i]) {
      img.data.set([...ink, 255], i * 4);
    }
  }
  octx.putImageData(img, 0, 0);
  const y = Math.round(((tapeH - bitmap.height) / 2) * scale);
  ctx.drawImage(off, 0, y, canvas.width, Math.round(bitmap.height * scale));
}

function hex(color) {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color) || [0, '00', '00', '00'];
  return [1, 2, 3].map((i) => parseInt(m[i], 16));
}
