// The label model: a row of blocks plus layout settings, and the maths around it.

/** Tape width in mm => printable pixels at [180 dpi, 360 dpi] (from ptouch-print's libptouch). */
export const TAPES = {
  4: [24, 48], 6: [32, 64], 9: [52, 106], 12: [76, 150],
  18: [120, 234], 21: [124, 248], 24: [128, 320], 36: [192, 454],
};

export const FONTS = ['sans-serif', 'serif', 'monospace', 'system-ui', 'Arial', 'Helvetica', 'Verdana',
  'Tahoma', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New', 'Impact', 'DejaVu Sans',
  'Liberation Sans', 'Roboto', 'Noto Sans'];

export const mmToPx = (mm, dpi) => Math.round((mm / 25.4) * dpi);
export const pxToMm = (px, dpi) => Math.round(((px / dpi) * 25.4) * 10) / 10;

/** Printable pixels of a tape, limited by the printhead. */
export function tapePx(mm, dpi = 180, maxPx = Infinity) {
  const entry = TAPES[mm];
  if (!entry) return 0;
  return Math.min(maxPx, entry[dpi >= 360 ? 1 : 0]);
}

export const BLOCK_DEFAULTS = {
  text: { type: 'text', text: 'Label', font: 'sans-serif', size: 0, bold: true, italic: false, align: 'center', invert: false, rotate: 0, lineSizes: '', underline: false, spacing: 0 },
  qr: {
    type: 'qr', kind: 'text', content: 'https://example.com', level: 'M', scale: 100, quiet: 1,
    wifiSsid: '', wifiPassword: '', wifiType: 'WPA', wifiHidden: false,
    contactName: '', org: '', phone: '', email: '', url: '', subject: '',
  },
  barcode: { type: 'barcode', format: 'code128', content: '1234567890', text: true, bar: 2, scale: 100, rotate: 0 },
  image: { type: 'image', src: '', name: '', mode: 'threshold', level: 128, invert: false, scale: 100, rotate: 0 },
  icon: { type: 'icon', icon: '', src: '', invert: false, scale: 100, rotate: 0 },
  spacer: { type: 'spacer', width: 5 },
};

export const BLOCK_NAMES = { text: 'Text', icon: 'Icon', qr: 'QR code', barcode: 'Barcode', image: 'Image', spacer: 'Space' };

export const LABEL_DEFAULTS = { margin: 2, gap: 2, length: 0, align: 'center', frame: false, frameWidth: 2, counter: 1, mirror: false, cutMarks: false, flag: false, flagDiameter: 6 };

let counter = 0;
export function newId() {
  counter += 1;
  return `b${Date.now().toString(36)}${counter}`;
}

export function newBlock(type) {
  if (!BLOCK_DEFAULTS[type]) throw new Error(`unknown block type ${type}`);
  return { id: newId(), ...BLOCK_DEFAULTS[type] };
}

export function defaultLabel() {
  return { ...LABEL_DEFAULTS, blocks: [newBlock('text')] };
}

const num = (v, min, max, fallback) => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback;
};
const oneOf = (v, options, fallback) => (options.includes(v) ? v : fallback);
const str = (v, max, fallback = '') => (typeof v === 'string' ? v.slice(0, max) : fallback);

export function normalizeBlock(raw) {
  if (!raw || typeof raw !== 'object' || !BLOCK_DEFAULTS[raw.type]) return null;
  const d = BLOCK_DEFAULTS[raw.type];
  const b = { id: str(raw.id, 40) || newId(), type: raw.type };
  switch (raw.type) {
    case 'text':
      Object.assign(b, {
        text: str(raw.text, 500, d.text),
        font: str(raw.font, 100) || d.font,
        size: num(raw.size, 0, 200, 0),
        bold: Boolean(raw.bold),
        italic: Boolean(raw.italic),
        align: oneOf(raw.align, ['left', 'center', 'right'], d.align),
        invert: Boolean(raw.invert),
        lineSizes: str(raw.lineSizes, 60),
        underline: Boolean(raw.underline),
        spacing: num(raw.spacing, -10, 100, 0),
      });
      break;
    case 'qr':
      Object.assign(b, {
        kind: oneOf(raw.kind, ['text', 'wifi', 'contact', 'email', 'phone'], d.kind),
        content: str(raw.content, 2000, d.content),
        wifiSsid: str(raw.wifiSsid, 64),
        wifiPassword: str(raw.wifiPassword, 128),
        wifiType: oneOf(raw.wifiType, ['WPA', 'WEP', 'nopass'], d.wifiType),
        wifiHidden: Boolean(raw.wifiHidden),
        contactName: str(raw.contactName, 200),
        org: str(raw.org, 200),
        phone: str(raw.phone, 50),
        email: str(raw.email, 200),
        url: str(raw.url, 500),
        subject: str(raw.subject, 200),
        level: oneOf(raw.level, ['L', 'M', 'Q', 'H'], d.level),
        scale: num(raw.scale, 20, 100, d.scale),
        quiet: num(raw.quiet, 0, 4, d.quiet),
      });
      break;
    case 'barcode':
      Object.assign(b, {
        format: oneOf(raw.format, ['code128', 'ean13', 'upca', 'code39'], d.format),
        content: str(raw.content, 80, d.content),
        text: raw.text === undefined ? d.text : Boolean(raw.text),
        bar: num(raw.bar, 1, 6, d.bar),
        scale: num(raw.scale, 20, 100, d.scale),
      });
      break;
    case 'image':
      Object.assign(b, {
        src: typeof raw.src === 'string' && raw.src.startsWith('data:image/') ? raw.src : '',
        name: str(raw.name, 200),
        mode: oneOf(raw.mode, ['threshold', 'dither'], d.mode),
        level: num(raw.level, 1, 254, d.level),
        invert: Boolean(raw.invert),
        scale: num(raw.scale, 10, 100, d.scale),
      });
      break;
    case 'icon':
      Object.assign(b, {
        icon: typeof raw.icon === 'string' && /^[a-z0-9-]+:[a-z0-9-]+$/.test(raw.icon) ? raw.icon : '',
        src: typeof raw.src === 'string' && raw.src.startsWith('data:image/svg+xml') ? raw.src : '',
        invert: Boolean(raw.invert),
        scale: num(raw.scale, 10, 100, d.scale),
      });
      break;
    default: // spacer
      b.width = num(raw.width, 0, 500, d.width);
  }
  if (b.type !== 'spacer') b.rotate = [0, 90, 180, 270].includes(Number(raw.rotate)) ? Number(raw.rotate) : 0;
  return b;
}

export function normalizeLabel(raw) {
  const r = raw && typeof raw === 'object' ? raw : {};
  const blocks = Array.isArray(r.blocks) ? r.blocks.map(normalizeBlock).filter(Boolean).slice(0, 30) : [];
  return {
    margin: num(r.margin, 0, 100, LABEL_DEFAULTS.margin),
    gap: num(r.gap, 0, 100, LABEL_DEFAULTS.gap),
    length: num(r.length, 0, 1000, LABEL_DEFAULTS.length),
    align: oneOf(r.align, ['left', 'center', 'right'], LABEL_DEFAULTS.align),
    frame: Boolean(r.frame),
    mirror: Boolean(r.mirror),
    cutMarks: Boolean(r.cutMarks),
    flag: Boolean(r.flag),
    flagDiameter: num(r.flagDiameter, 1, 50, LABEL_DEFAULTS.flagDiameter),
    frameWidth: num(r.frameWidth, 1, 10, LABEL_DEFAULTS.frameWidth),
    counter: Math.round(num(r.counter, 0, 999999999, LABEL_DEFAULTS.counter)),
    blocks,
  };
}

/**
 * Horizontal positions of the blocks.
 * @param {number[]} widths block widths in px
 * @returns {{width: number, xs: number[]}}
 */
export function layout(widths, label, dpi) {
  const margin = mmToPx(label.margin, dpi);
  const gap = mmToPx(label.gap, dpi);
  const frame = label.frame ? label.frameWidth : 0;
  const content = widths.reduce((s, w) => s + w, 0) + gap * Math.max(0, widths.length - 1);
  const natural = content + 2 * (margin + frame);
  const width = Math.max(natural, mmToPx(label.length, dpi), 1);
  const free = width - natural;
  let x = margin + frame + (label.align === 'center' ? Math.floor(free / 2) : label.align === 'right' ? free : 0);
  const xs = widths.map((w) => {
    const at = x;
    x += w + gap;
    return at;
  });
  return { width, xs };
}

/** Tape around a cable: the circumference, in px. */
export const flagGap = (diameterMm, dpi) => mmToPx(Math.PI * diameterMm, dpi);

/** Font size in px so that `lines` lines fill `height` px (ratio: glyph height / font size). */
export function fitFontSize(lines, height, ratio, lineGap = 0.15) {
  const n = Math.max(1, lines);
  return Math.max(4, Math.floor(height / (ratio * (n + (n - 1) * lineGap))));
}

/**
 * Relative size of each line from a list like "100, 60" (percent); lines without a value take
 * the last one. Values outside 20..300 are clamped.
 */
export function lineScales(spec, lines) {
  const values = String(spec || '').split(/[,;\s]+/).map(Number).filter((v) => Number.isFinite(v) && v > 0)
    .map((v) => Math.min(300, Math.max(20, v)) / 100);
  if (!values.length) values.push(1);
  return Array.from({ length: Math.max(1, lines) }, (_, i) => values[Math.min(i, values.length - 1)]);
}

/** Base font size in px so that lines with these scales fill `height` px. */
export function fitScaledFontSize(scales, height, ratio, lineGap = 0.15) {
  const total = scales.reduce((s, v) => s + v, 0) + lineGap * scales.slice(0, -1).reduce((s, v) => s + v, 0);
  return Math.max(4, Math.floor(height / (ratio * total)));
}

const GENERIC_FAMILIES = ['sans-serif', 'serif', 'monospace', 'system-ui', 'cursive', 'fantasy'];

export const ptToPx =(pt, dpi) => Math.round((pt / 72) * dpi);

export function cssFont(block, px) {
  const family = GENERIC_FAMILIES.includes(block.font)
    ? block.font
    : `"${block.font.replace(/["\\]/g, '')}", sans-serif`;
  return `${block.italic ? 'italic ' : ''}${block.bold ? 'bold ' : ''}${px}px ${family}`;
}
