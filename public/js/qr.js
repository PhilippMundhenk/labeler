// QR code matrix from the vendored qrcode-generator (MIT, public/lib/qrcode.mjs).
import qrcode from '../lib/qrcode.mjs';

const utf8 = new TextEncoder();
// The library encodes byte mode data with this function; the default is Latin-1 only.
qrcode.stringToBytes = (s) => Array.from(utf8.encode(s));

export const LEVELS = ['L', 'M', 'Q', 'H'];

/**
 * @returns {{size: number, dark: (row: number, col: number) => boolean}}
 */
export function qrMatrix(text, level = 'M') {
  if (!text) throw new Error('enter the QR code content');
  const qr = qrcode(0, LEVELS.includes(level) ? level : 'M');
  qr.addData(text, 'Byte');
  try {
    qr.make();
  } catch {
    throw new Error('too much content for a QR code');
  }
  const size = qr.getModuleCount();
  return { size, dark: (row, col) => qr.isDark(row, col) };
}
