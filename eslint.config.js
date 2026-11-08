import js from '@eslint/js';
import globals from 'globals';

export default [
  { ignores: ['node_modules/', 'vendor/', 'build/', 'public/lib/', 'coverage/', 'test-results/', 'playwright-report/'] },
  js.configs.recommended,
  {
    files: ['**/*.js', '**/*.mjs'],
    languageOptions: { ecmaVersion: 2024, sourceType: 'module', globals: { ...globals.node } },
    rules: {
      'no-unused-vars': ['error', { args: 'after-used', caughtErrors: 'none' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
    },
  },
  { files: ['public/js/**/*.js'], languageOptions: { globals: { ...globals.browser } } },
  // page.evaluate callbacks run in the browser
  { files: ['tests/e2e/**/*.js', 'tools/screenshots.js'], languageOptions: { globals: { ...globals.node, ...globals.browser } } },
];
