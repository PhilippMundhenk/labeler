// Browser tests: PHP's built-in server with the ptouch-print simulator.
import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';
import { tmpdir } from 'node:os';

const root = resolve(import.meta.dirname);
export const RUN = resolve(root, 'test-results/e2e-run');
const PORT = 8099;
export const PASSWORD_PORT = 8100;
export const QL_PORT = 8101;
// a P-touch and a QL printer behind one interface (#147)
export const MULTI_PORT = 8102;
const MULTI_SOCKET = resolve(tmpdir(), `labeler-e2e-ql-${MULTI_PORT}.sock`);
// the simulated Brother QL printer listens here (outside test-results, which is cleaned)
const QL_SOCKET = resolve(tmpdir(), `labeler-e2e-ql-${QL_PORT}.sock`);

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
  }, {
    // a Brother QL printer with the ql driver (#139): the simulator on a socket, then the server
    command: `sh -c 'php tools/ql-sim/ql-printer "$QL_SOCKET" & exec php -S 127.0.0.1:${QL_PORT} -t public src/router.php'`,
    url: `http://127.0.0.1:${QL_PORT}/api.php?action=health`,
    reuseExistingServer: false,
    env: {
      QL_SOCKET,
      LABELER_PRINTER_DRIVER: 'ql',
      LABELER_QL_DEVICE: QL_SOCKET,
      LABELER_DATA_DIR: resolve(RUN, 'data-ql'),
      LABELER_TIMEOUT: '10',
      QLSIM_STATE: resolve(RUN, 'ql-state.json'),
      QLSIM_OUT: resolve(RUN, 'ql-printed'),
    },
  }, {
    command: `sh -c 'php tools/ql-sim/ql-printer "$QL_SOCKET" & exec php -S 127.0.0.1:${MULTI_PORT} -t public src/router.php'`,
    url: `http://127.0.0.1:${MULTI_PORT}/api.php?action=health`,
    reuseExistingServer: false,
    env: {
      QL_SOCKET: MULTI_SOCKET,
      // several requests at once, as in labeler.service: one printer prints while the other does too
      PHP_CLI_SERVER_WORKERS: '4',
      LABELER_PRINTER_DRIVER: 'ptouch,ql',
      LABELER_PTOUCH_BIN: resolve(root, 'tools/ptouch-sim/ptouch-print'),
      LABELER_QL_DEVICE: MULTI_SOCKET,
      LABELER_DATA_DIR: resolve(RUN, 'data-multi'),
      LABELER_TIMEOUT: '10',
      PTSIM_STATE: resolve(RUN, 'multi-sim-state.json'),
      PTSIM_OUT: resolve(RUN, 'multi-printed'),
      QLSIM_STATE: resolve(RUN, 'multi-ql-state.json'),
      QLSIM_OUT: resolve(RUN, 'multi-ql-printed'),
    },
  }],
});
