# CLAUDE.md

## Commands

PHP and the browser tests run in the development container (`tools/dev/Dockerfile`); PHP is not
installed on the Windows host.

    tools/dev.sh composer install && tools/dev.sh npm ci     # after dependency changes
    tools/dev.sh composer coverage                            # PHPUnit + 95 % gate per file
    tools/dev.sh npm test                                     # lint, node tests, Playwright, JS gate
    npm run test:unit                                         # node tests only, works on the host
    docker build -t labeler-contract tools/contract && docker run --rm -v "$PWD:/work" labeler-contract
    tools/dev.sh npm run screenshots                          # docs/screenshots/*.png
    DEV_PORT=8080 tools/dev.sh sh tools/demo.sh               # UI with the simulated printer

From Git Bash, `docker run -v` needs `MSYS_NO_PATHCONV=1` (tools/dev.sh sets it).

## Rules

- The device is a Raspberry Pi Zero without Docker: no runtime dependencies beyond PHP's standard
  library, PHP 7.4 syntax, no build step for `public/`.
- Never pass anything to a shell: ptouch-print runs through `Process::run()` with an argument list.
- Behaviour of ptouch-print belongs in the simulator (`tools/ptouch-sim`) first, with tests; the
  contract test checks the assumption against the real tool.
- Every file in `src/`, `tools/ptouch-sim/` and `public/js/` keeps 95 % line coverage. Browser-only
  code (`main.js`, `render.js`) is covered by the Playwright suite (`tests/e2e/fixtures.js`
  writes V8 coverage for c8).
- Keep `package.json` version, `Config::VERSION` and the Playwright image tag in
  `tools/dev/Dockerfile` consistent (`tools/check-versions.js` runs in lint).
- Docs: README for users, docs/ for the rest, CHANGELOG with issue numbers, ADRs in docs/adr.
