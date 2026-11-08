// 1-bit palette PNG encoder: the format `ptouch-print --image` prints correctly.
// Palette index 0 is white, index 1 is black.

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

export function crc32(bytes, crc = 0xffffffff) {
  for (let i = 0; i < bytes.length; i++) crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const out = new Uint8Array(12 + data.length);
  const view = new DataView(out.buffer);
  view.setUint32(0, data.length);
  for (let i = 0; i < 4; i++) out[4 + i] = type.charCodeAt(i);
  out.set(data, 8);
  view.setUint32(8 + data.length, crc32(out.subarray(4, 8 + data.length)));
  return out;
}

/** Raw scanlines: filter byte 0, then 8 pixels per byte, most significant bit first. */
export function packRows(bitmap) {
  const stride = Math.ceil(bitmap.width / 8);
  const raw = new Uint8Array((stride + 1) * bitmap.height);
  for (let y = 0; y < bitmap.height; y++) {
    const row = y * (stride + 1) + 1;
    for (let x = 0; x < bitmap.width; x++) {
      if (bitmap.data[y * bitmap.width + x]) raw[row + (x >> 3)] |= 0x80 >> (x & 7);
    }
  }
  return raw;
}

/** zlib deflate in the browser (CompressionStream "deflate" is the zlib format). */
export async function browserDeflate(bytes) {
  const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate'));
  return new Uint8Array(await new Response(stream).arrayBuffer());
}

/**
 * @param {{width: number, height: number, data: Uint8Array}} bitmap 1 = black
 * @param {(bytes: Uint8Array) => Promise<Uint8Array>|Uint8Array} deflate zlib compressor
 * @param {number} dpi written as pHYs so viewers show the physical size
 */
export async function encodePng(bitmap, deflate = browserDeflate, dpi = 180) {
  const ihdr = new Uint8Array(13);
  const view = new DataView(ihdr.buffer);
  view.setUint32(0, bitmap.width);
  view.setUint32(4, bitmap.height);
  ihdr[8] = 1; // bit depth
  ihdr[9] = 3; // palette
  const phys = new Uint8Array(9);
  const ppm = Math.round(dpi / 0.0254);
  new DataView(phys.buffer).setUint32(0, ppm);
  new DataView(phys.buffer).setUint32(4, ppm);
  phys[8] = 1;
  const parts = [
    Uint8Array.of(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a),
    chunk('IHDR', ihdr),
    chunk('PLTE', Uint8Array.of(255, 255, 255, 0, 0, 0)),
    chunk('pHYs', phys),
    chunk('IDAT', await deflate(packRows(bitmap))),
    chunk('IEND', new Uint8Array(0)),
  ];
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let pos = 0;
  for (const p of parts) {
    out.set(p, pos);
    pos += p.length;
  }
  return out;
}

export function toBase64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(s);
}
