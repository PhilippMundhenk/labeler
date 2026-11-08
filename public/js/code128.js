// Code 128 barcodes (code sets B and C, switching to C for runs of digits).

// Bar/space widths per symbol value 0..105, then the stop pattern (106).
export const PATTERNS = (
  '212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 ' +
  '122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 ' +
  '321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 ' +
  '211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 ' +
  '213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 ' +
  '121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 ' +
  '241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 ' +
  '412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 ' +
  '211214 211232 2331112'
).split(' ');

const START_B = 104;
const START_C = 105;
const CODE_B = 100;
const CODE_C = 99;
const STOP = 106;

function digitRun(text, i) {
  let n = 0;
  while (i + n < text.length && text[i + n] >= '0' && text[i + n] <= '9') n++;
  return n;
}

/** Symbol values including start and checksum, without the stop symbol. */
export function symbols(text) {
  if (!text) throw new Error('enter the barcode content');
  for (const ch of text) {
    const c = ch.codePointAt(0);
    if (c < 32 || c > 126) throw new Error(`"${ch}" cannot be encoded in Code 128`);
  }
  const values = [];
  let set = null;
  const use = (wanted) => {
    if (set === wanted) return;
    if (set === null) values.push(wanted === 'C' ? START_C : START_B);
    else values.push(wanted === 'C' ? CODE_C : CODE_B);
    set = wanted;
  };
  let i = 0;
  while (i < text.length) {
    let run = digitRun(text, i);
    if (run < 4) {
      use('B');
      values.push(text.charCodeAt(i) - 32);
      i++;
      continue;
    }
    if (run % 2 === 1) {
      // odd run of digits: the first one goes into set B so the rest pairs up
      use('B');
      values.push(text.charCodeAt(i) - 32);
      i++;
      run--;
    }
    use('C');
    for (const end = i + run; i < end; i += 2) values.push(Number(text.substr(i, 2)));
  }
  let sum = values[0];
  for (let k = 1; k < values.length; k++) sum += values[k] * k;
  values.push(sum % 103);
  return values;
}

/**
 * Module pattern of the barcode: true = bar, false = space, with `quiet` empty modules
 * on both sides.
 */
export function modules(text, quiet = 10) {
  const out = new Array(quiet).fill(false);
  for (const value of [...symbols(text), STOP]) {
    const widths = PATTERNS[value];
    for (let k = 0; k < widths.length; k++) {
      for (let n = 0; n < Number(widths[k]); n++) out.push(k % 2 === 0);
    }
  }
  for (let n = 0; n < quiet; n++) out.push(false);
  return out;
}
