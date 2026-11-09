// Black and white bitmaps: 1 byte per pixel, 1 = black (printed), 0 = white.

export class Bitmap {
  constructor(width, height, data) {
    this.width = Math.max(0, Math.round(width));
    this.height = Math.max(0, Math.round(height));
    this.data = data || new Uint8Array(this.width * this.height);
  }

  get(x, y) {
    return x >= 0 && y >= 0 && x < this.width && y < this.height ? this.data[y * this.width + x] : 0;
  }

  set(x, y, v = 1) {
    if (x >= 0 && y >= 0 && x < this.width && y < this.height) this.data[y * this.width + x] = v ? 1 : 0;
  }

  fillRect(x, y, w, h, v = 1) {
    const x0 = Math.max(0, Math.round(x));
    const y0 = Math.max(0, Math.round(y));
    const x1 = Math.min(this.width, Math.round(x + w));
    const y1 = Math.min(this.height, Math.round(y + h));
    for (let yy = y0; yy < y1; yy++) this.data.fill(v ? 1 : 0, yy * this.width + x0, yy * this.width + Math.max(x0, x1));
  }

  /** Copy `src` onto this bitmap at (x, y); only black pixels are drawn. */
  blit(src, x, y) {
    x = Math.round(x);
    y = Math.round(y);
    for (let sy = 0; sy < src.height; sy++) {
      for (let sx = 0; sx < src.width; sx++) {
        if (src.data[sy * src.width + sx]) this.set(x + sx, y + sy, 1);
      }
    }
  }

  /** Half as many rows, a pixel black when either of its two source rows is (high resolution). */
  halveRows() {
    const out = new Bitmap(this.width, Math.ceil(this.height / 2));
    for (let y = 0; y < this.height; y++) {
      const row = y * this.width;
      const to = (y >> 1) * this.width;
      for (let x = 0; x < this.width; x++) if (this.data[row + x]) out.data[to + x] = 1;
    }
    return out;
  }

  invert() {
    for (let i = 0; i < this.data.length; i++) this.data[i] = this.data[i] ? 0 : 1;
    return this;
  }

  /** Rectangle outline of `line` pixels along the edges. */
  frame(line, inset = 0) {
    const w = this.width - 2 * inset;
    const h = this.height - 2 * inset;
    this.fillRect(inset, inset, w, line);
    this.fillRect(inset, inset + h - line, w, line);
    this.fillRect(inset, inset, line, h);
    this.fillRect(inset + w - line, inset, line, h);
    return this;
  }

  /** Dashed vertical line in column x, for cutting by hand. */
  dashedColumn(x, dash = 4) {
    for (let y = 0; y < this.height; y++) if (Math.floor(y / dash) % 2 === 0) this.set(x, y, 1);
    return this;
  }

  /** The bitmap turned by 90 degrees clockwise (a new bitmap). */
  rotate90() {
    const out = new Bitmap(this.height, this.width);
    for (let y = 0; y < this.height; y++) {
      for (let x = 0; x < this.width; x++) {
        if (this.data[y * this.width + x]) out.data[x * out.width + (this.height - 1 - y)] = 1;
      }
    }
    return out;
  }

  /** Turned by 180 degrees, in place. */
  rotate180() {
    this.data.reverse();
    return this;
  }

  /** Flips the bitmap left to right, for clear tape read from the back. */
  mirror() {
    for (let y = 0; y < this.height; y++) {
      const row = this.data.subarray(y * this.width, (y + 1) * this.width);
      row.reverse();
    }
    return this;
  }

  countBlack() {
    let n = 0;
    for (const v of this.data) n += v;
    return n;
  }

  /** Columns [first, last] that contain black pixels, or null for an empty bitmap. */
  inkColumns() {
    let first = -1;
    let last = -1;
    for (let x = 0; x < this.width; x++) {
      for (let y = 0; y < this.height; y++) {
        if (this.data[y * this.width + x]) {
          if (first < 0) first = x;
          last = x;
          break;
        }
      }
    }
    return first < 0 ? null : [first, last];
  }

  /** Sub-bitmap of columns x .. x + w - 1. */
  cropColumns(x, w) {
    const out = new Bitmap(w, this.height);
    for (let y = 0; y < this.height; y++) {
      for (let xx = 0; xx < w; xx++) out.data[y * w + xx] = this.get(x + xx, y);
    }
    return out;
  }
}

/**
 * Luminance (0 black .. 255 white) of RGBA pixels, transparent areas count as white paper.
 */
export function luminance(rgba, width, height) {
  const gray = new Float32Array(width * height);
  for (let i = 0; i < gray.length; i++) {
    const a = rgba[i * 4 + 3] / 255;
    const l = 0.299 * rgba[i * 4] + 0.587 * rgba[i * 4 + 1] + 0.114 * rgba[i * 4 + 2];
    gray[i] = l * a + 255 * (1 - a);
  }
  return gray;
}

export function threshold(gray, width, height, level = 128) {
  const bm = new Bitmap(width, height);
  for (let i = 0; i < gray.length; i++) bm.data[i] = gray[i] < level ? 1 : 0;
  return bm;
}

/** Floyd-Steinberg error diffusion, for photos and greyscale logos. */
export function dither(gray, width, height, level = 128) {
  const g = Float32Array.from(gray);
  const bm = new Bitmap(width, height);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = y * width + x;
      const black = g[i] < level;
      bm.data[i] = black ? 1 : 0;
      const err = g[i] - (black ? 0 : 255);
      if (x + 1 < width) g[i + 1] += (err * 7) / 16;
      if (y + 1 < height) {
        if (x > 0) g[i + width - 1] += (err * 3) / 16;
        g[i + width] += (err * 5) / 16;
        if (x + 1 < width) g[i + width + 1] += err / 16;
      }
    }
  }
  return bm;
}
