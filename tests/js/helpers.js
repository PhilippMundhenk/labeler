import zxing from '@zxing/library';
import { inflateSync } from 'node:zlib';

const { RGBLuminanceSource, BinaryBitmap, HybridBinarizer, QRCodeReader, Code128Reader, Code39Reader, EAN13Reader, MultiFormatReader, BarcodeFormat, DecodeHintType } = zxing;

const READERS = { qr: () => new QRCodeReader(), code128: () => new Code128Reader(), code39: () => new Code39Reader(), ean13: () => new EAN13Reader(), upca: () => new MultiFormatReader() };

/** Scale a 0/1 bitmap up and surround it with white, as luminance bytes. */
export function luminanceOf(bm, scale = 2, border = 20) {
  const w = bm.width * scale + 2 * border;
  const h = bm.height * scale + 2 * border;
  const lum = new Uint8ClampedArray(w * h).fill(255);
  for (let y = 0; y < bm.height * scale; y++) {
    for (let x = 0; x < bm.width * scale; x++) {
      if (bm.data[Math.floor(y / scale) * bm.width + Math.floor(x / scale)]) lum[(y + border) * w + x + border] = 0;
    }
  }
  return { lum, w, h };
}

export function decode(bm, kind, scale = 2) {
  const { lum, w, h } = luminanceOf(bm, scale);
  const bitmap = new BinaryBitmap(new HybridBinarizer(new RGBLuminanceSource(lum, w, h)));
  const hints = new Map([[DecodeHintType.PURE_BARCODE, kind === 'qr'], [DecodeHintType.TRY_HARDER, true]]);
  if (kind === 'upca') hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.UPC_A]);
  const reader = READERS[kind]();
  return reader.decode(bitmap, hints).getText();
}

/** Parse a PNG made by public/js/png.js back into chunks and pixels. */
export function parsePng(bytes) {
  const buf = Buffer.from(bytes);
  const chunks = {};
  let pos = 8;
  const order = [];
  while (pos < buf.length) {
    const len = buf.readUInt32BE(pos);
    const type = buf.toString('latin1', pos + 4, pos + 8);
    chunks[type] = (chunks[type] || Buffer.alloc(0));
    chunks[type] = Buffer.concat([chunks[type], buf.subarray(pos + 8, pos + 8 + len)]);
    order.push({ type, crc: buf.readUInt32BE(pos + 8 + len), body: buf.subarray(pos + 4, pos + 8 + len) });
    pos += 12 + len;
  }
  const width = chunks.IHDR.readUInt32BE(0);
  const height = chunks.IHDR.readUInt32BE(4);
  const raw = inflateSync(chunks.IDAT);
  const stride = Math.ceil(width / 8);
  const pixels = [];
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) pixels.push((raw[y * (stride + 1) + 1 + (x >> 3)] >> (7 - (x & 7))) & 1);
  }
  return { signature: buf.subarray(0, 8), chunks, order, width, height, raw, pixels };
}
