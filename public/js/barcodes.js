// 1D barcodes: Code 128 (code128.js), EAN-13, UPC-A and Code 39.
import { modules as code128 } from './code128.js';

export const FORMATS = [
  ['code128', 'Code 128', 'Letters, digits and punctuation.'],
  ['ean13', 'EAN-13', '12 digits (check digit added) or 13 digits.'],
  ['upca', 'UPC-A', '11 digits (check digit added) or 12 digits.'],
  ['code39', 'Code 39', 'Capital letters, digits, space and - . $ / + %.'],
];

// EAN: L codes, G and R codes derive from them; parity pattern of the first digit
const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const R = L.map((c) => [...c].map((b) => (b === '1' ? '0' : '1')).join(''));
const G = R.map((c) => [...c].reverse().join(''));
const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

/** Check digit of the digits before it: weights 1 and 3 from the right, 3 first. */
export function checkDigit(digits) {
  let sum = 0;
  [...digits].reverse().forEach((d, i) => {
    sum += Number(d) * (i % 2 === 0 ? 3 : 1);
  });
  return String((10 - (sum % 10)) % 10);
}

function withCheck(content, length, name) {
  const s = content.replace(/\s+/g, '');
  if (!/^\d+$/.test(s) || (s.length !== length - 1 && s.length !== length)) {
    throw new Error(`${name} needs ${length - 1} or ${length} digits`);
  }
  if (s.length === length - 1) return s + checkDigit(s);
  if (checkDigit(s.slice(0, -1)) !== s.slice(-1)) throw new Error(`wrong check digit, it should be ${checkDigit(s.slice(0, -1))}`);
  return s;
}

function ean13Bits(code) {
  const parity = PARITY[Number(code[0])];
  let bits = '101';
  for (let i = 1; i <= 6; i++) bits += (parity[i - 1] === 'L' ? L : G)[Number(code[i])];
  bits += '01010';
  for (let i = 7; i <= 12; i++) bits += R[Number(code[i])];
  return `${bits}101`;
}

// Code 39 from the zxing tables: 9 elements (bar, space, ...), bit set = wide
const C39 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';
const C39_CODES = [0x034, 0x121, 0x061, 0x160, 0x031, 0x130, 0x070, 0x025, 0x124, 0x064, 0x109, 0x049, 0x148, 0x019,
  0x118, 0x058, 0x00d, 0x10c, 0x04c, 0x01c, 0x103, 0x043, 0x142, 0x013, 0x112, 0x052, 0x007, 0x106, 0x046, 0x016, 0x181,
  0x0c1, 0x1c0, 0x091, 0x190, 0x0d0, 0x085, 0x184, 0x0c4, 0x0a8, 0x0a2, 0x08a, 0x02a];
const C39_STAR = 0x094;

function code39Modules(text) {
  const s = text.toUpperCase();
  if (!s) throw new Error('enter the barcode content');
  for (const ch of s) if (!C39.includes(ch)) throw new Error(`"${ch}" cannot be encoded in Code 39`);
  const out = [];
  const symbol = (code) => {
    for (let k = 8; k >= 0; k--) {
      const width = (code >> k) & 1 ? 3 : 1;
      for (let n = 0; n < width; n++) out.push((8 - k) % 2 === 0);
    }
    out.push(false); // gap between characters
  };
  symbol(C39_STAR);
  for (const ch of s) symbol(C39_CODES[C39.indexOf(ch)]);
  symbol(C39_STAR);
  out.pop();
  return { modules: out, text: s };
}

const pad = (mods, quiet) => [...new Array(quiet).fill(false), ...mods, ...new Array(quiet).fill(false)];

/**
 * @returns {{modules: boolean[], text: string, guards: number[]}} guards: module ranges [from, to)
 * that extend into the text line (EAN/UPC guard bars)
 */
export function encodeBarcode(format, content) {
  switch (format) {
    case 'ean13':
    case 'upca': {
      const code = format === 'upca' ? `0${withCheck(content, 12, 'UPC-A')}` : withCheck(content, 13, 'EAN-13');
      const mods = pad([...ean13Bits(code)].map((b) => b === '1'), 11);
      return { modules: mods, text: format === 'upca' ? code.slice(1) : code, guards: [[11, 14], [56, 61], [103, 106]] };
    }
    case 'code39': {
      const c = code39Modules(content);
      return { modules: pad(c.modules, 10), text: c.text, guards: [] };
    }
    default:
      return { modules: code128(content, 10), text: content, guards: [] };
  }
}
