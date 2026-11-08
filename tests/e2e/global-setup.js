import { mkdirSync, rmSync, copyFileSync, existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { RUN } from '../../playwright.config.js';

// A font that ships with the test image (GPL with font exception); not stored in the repository.
const FONT = '/usr/share/fonts/opentype/tlwg/Loma.otf';

export default function globalSetup() {
  rmSync(RUN, { recursive: true, force: true });
  for (const dir of ['data', 'fonts', 'printed']) mkdirSync(resolve(RUN, dir), { recursive: true });
  if (existsSync(FONT)) copyFileSync(FONT, resolve(RUN, 'fonts/Test-Font.otf'));
  writeFileSync(resolve(RUN, 'fonts/broken.woff2'), 'not a font');
  writeFileSync(resolve(RUN, 'sim-state.json'), '{}');
}
