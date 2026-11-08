// Builds label PNGs with the same code the browser uses (bitmap, QR, Code 128, PNG encoder).
import { writeFileSync, mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { Bitmap } from '../../public/js/bitmap.js';
import { encodePng } from '../../public/js/png.js';
import { qrMatrix } from '../../public/js/qr.js';
import { modules } from '../../public/js/code128.js';

const dir = process.argv[2];
mkdirSync(dir, { recursive: true });

async function save(name, bm) {
  writeFileSync(`${dir}/${name}.png`, await encodePng(bm, (b) => deflateSync(b)));
}

for (const height of [32, 76, 128]) {
  const bm = new Bitmap(301, height); // odd width: rows end inside a byte
  bm.frame(2);
  const qr = qrMatrix('https://example.com/contract', 'M');
  const m = Math.floor((height - 8) / qr.size);
  for (let r = 0; r < qr.size; r++) {
    for (let c = 0; c < qr.size; c++) if (qr.dark(r, c)) bm.fillRect(6 + c * m, 4 + r * m, m, m);
  }
  modules('LBL-0042', 2).forEach((bar, i) => bar && bm.fillRect(120 + i, 4, 1, height - 8));
  await save(`label-${height}`, bm);
}
await save('blank-76', new Bitmap(50, 76));
console.log(`labels written to ${dir}`);
