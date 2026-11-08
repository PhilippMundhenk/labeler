// Browser tests: PHP's built-in server with the ptouch-print simulator.
import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname);
export const RUN = resolve(root, 'test-results/e2e-run');
const PORT = 8099;
export const PASSWORD_PORT = 8100;

export default defineConfig({
  testDir: 'tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  timeout: 30000,
  fullyParallel: false,
  workers: 1, // one simulated printer for all tests
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }], ['list']] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    viewport: { width: 1280, height: 900 },
    serviceWorkers: 'block', // tests/e2e/offline.spec.js allows it where it is the subject
    trace: 'retain-on-failure',
  },
  webServer: [{
    command: `php -S 127.0.0.1:${PORT} -t public src/router.php`,
    url: `http://127.0.0.1:${PORT}/api.php?action=health`,
    reuseExistingServer: false,
    env: {
      LABELER_PTOUCH_BIN: resolve(root, 'tools/ptouch-sim/ptouch-print'),
      LABELER_DATA_DIR: resolve(RUN, 'data'),
      LABELER_FONTS_DIR: resolve(RUN, 'fonts'),
      LABELER_MAX_COPIES: '10',
      PTSIM_STATE: resolve(RUN, 'sim-state.json'),
      PTSIM_OUT: resolve(RUN, 'printed'),
    },
  }, {
    // the same with a password (#30)
    command: `php -S 127.0.0.1:${PASSWORD_PORT} -t public`,
    url: `http://127.0.0.1:${PASSWORD_PORT}/api.php?action=health`,
    reuseExistingServer: false,
    env: {
      LABELER_PTOUCH_BIN: resolve(root, 'tools/ptouch-sim/ptouch-print'),
      LABELER_DATA_DIR: resolve(RUN, 'data-password'),
      LABELER_PASSWORD: 'correct horse',
      PTSIM_STATE: resolve(RUN, 'sim-state.json'),
      PTSIM_OUT: resolve(RUN, 'printed'),
    },
  }],
});
