# Development

## Layout

```
public/            web root: index.html, app.css, api.php, js/ (ES modules), lib/qrcode.mjs (vendored)
src/               PHP: Api, Config, Log, Png, Process, PtouchPrint, Response, bootstrap.php
tools/ptouch-sim/  simulated ptouch-print with a simulated printer (PHP, see its README)
tools/contract/    contract test against the real ptouch-print, built from upstream
tools/dev/         development image: PHP + pcov, Composer, Node, Playwright
tests/php/         PHPUnit: API, ptouch-print wrapper, PNG checks, config, log, simulator
tests/js/          node:test: PNG encoder, bitmaps, Code 128, QR, label model, API client
tests/e2e/         Playwright: the real UI against PHP's built-in server, the simulator and a fake Iconify API
deploy/            systemd unit, udev rule, nginx and lighttpd examples
```

Container: `docker build -t labeler . && sh tools/container-smoke.sh labeler` runs the image as
printer agent and as interface and prints through both.

Nothing in `tests/`, `tools/`, `node_modules/` or `vendor/` goes onto the device; `tools/package.sh`
builds the release zip from `public/`, `src/`, `deploy/`, `fonts/` and the docs.

## Running the checks

Everything runs in the development image, so only Docker is needed:

```sh
tools/dev.sh composer install && tools/dev.sh npm ci   # once
tools/dev.sh composer coverage    # PHPUnit with the 95 % gate per file
tools/dev.sh npm test             # lint, node tests, browser tests, merged JS coverage gate
docker build -t labeler-contract tools/contract && docker run --rm -v "$PWD:/work" labeler-contract
```

With PHP 7.4+ and Node 22+ installed locally, `composer coverage` (needs pcov) and `npm test` work
without Docker too; the browser tests need `npx playwright install chromium`.

Try the UI with the simulated printer:

```sh
DEV_PORT=8080 tools/dev.sh sh tools/demo.sh     # then open http://localhost:8080/
```

Printed labels land in `test-results/demo/printed/` as PNG plus JSON. Other printers and states:
`PTSIM_*` variables, e.g. `DEV_PORT=8080 tools/dev.sh env PTSIM_VERSION=1.5 PTSIM_TAPE_MM=24 sh tools/demo.sh`.

## Tests

Three layers plus a contract test, all in CI:

1. **PHP unit tests** (`tests/php`, PHPUnit 9.6 so they run on PHP 7.4 to 8.4). The API and the
   ptouch-print wrapper run the simulator as a real subprocess, so argument passing, exit codes and
   output parsing are exercised end to end. One test feeds shell metacharacters through the
   process layer and checks nothing was executed.
2. **JS unit tests** (`tests/js`, `node:test`). QR codes and barcodes are decoded with zxing, so
   they are checked by an independent reader rather than against the encoder itself. PNGs are
   parsed back and their CRCs verified.
3. **Browser tests** (`tests/e2e`, Playwright, Chromium). The real page against `php -S` and the
   simulator: every block type and option, layout, saved labels, fonts (server folder and device
   fonts), every printer state (missing, P-Lite, no tape, USB permission, 360 dpi, version 1.5),
   failing prints, and the printed PNG itself: the simulator stores it and the test scans its QR
   code and barcode with zxing. axe-core checks accessibility (WCAG 2.1 AA) of every screen
   in light and dark mode, and a keyboard-only test walks from the page start to a print.
4. **Contract test** (`tools/contract`). Builds ptouch-print 1.6, 1.7, 1.8 and 1.9 from the
   upstream sources and checks that the labels from our PNG encoder are read back pixel for pixel,
   that `--copies`, `--chain` and `--precut` are accepted where Labeler uses them, that our parser
   understands the real "no printer" output, and records whether `--text a b` still works (it
   stopped in 1.7, which is what broke the first version of Labeler, #1). A weekly workflow runs it
   against upstream's main branch.

### The simulated printer

`tools/ptouch-sim/ptouch-print` is a drop-in for the real program: same options per version, same
messages on stdout and stderr, same exit codes, the same image checks (palette, height). State
comes from `PTSIM_*` variables or a JSON file that tests rewrite while the server runs. See
[tools/ptouch-sim/README.md](../tools/ptouch-sim/README.md).

### Coverage

| Part | Tool | Gate | Measured 2026-11-08 |
| --- | --- | --- | --- |
| PHP (`src/`, simulator) | PHPUnit + pcov, `tools/coverage-gate.php` | 95 % lines per file | 99.7 % |
| JS (`public/js/`) | c8 over node tests **and** browser tests | 95 % lines per file | 99.2 % |

The browser tests collect V8 coverage of every page and write it in the format c8 reads
(`tests/e2e/fixtures.js`), so `npm run coverage` merges it with the node tests and applies one gate.
Excluded: `public/lib/qrcode.mjs` (vendored), `src/bootstrap.php` (only `require_once` lines,
loaded before coverage starts), `public/api.php` (request wiring, exercised by every browser test).

## Translations

The interface is written in English; `public/js/i18n.js` translates it as it appears on the page
(a MutationObserver over text nodes and the placeholder, title, aria-label and label attributes).
The English text is the key, so a new string needs only an entry in `public/js/i18n-de.js`;
`{name}` in a key stands for a part that is taken over (numbers, names). Content the user typed,
font names and saved label names sit inside `[data-no-i18n]` and are never translated. In German,
texts without a translation are collected, and `tests/e2e/i18n.spec.js` walks every screen and
fails on any. A new language: copy `i18n-de.js`, translate, add it to `DICTIONARIES` and
`LANGUAGES` in `i18n.js`.

## Screenshots

```sh
tools/dev.sh npm run screenshots
```

starts a demo instance on the simulator and writes `docs/screenshots/*.png` (demo content only).

## Releases

1. Move the `Unreleased` entries in `CHANGELOG.md` under the new version and date.
2. Set the version in `package.json` and `src/Config.php` (lint checks they match).
3. `git tag vX.Y.Z && git push --tags`: the release workflow builds `labeler-X.Y.Z.zip` with
   `tools/package.sh` and attaches it to a GitHub release.

The QR library is vendored: `public/lib/qrcode.mjs` is `dist/qrcode.mjs` of
[qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 2.0.4. Update it by copying
the file of a newer version and running the tests.
