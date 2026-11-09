# CLAUDE.md

## Commands

PHP and the browser tests run in the development container (`tools/dev/Dockerfile`); PHP is not
installed on the Windows host.

    tools/dev.sh composer install && tools/dev.sh npm ci     # after dependency changes
    tools/dev.sh composer coverage                            # PHPUnit + 95 % gate per file
    tools/dev.sh npm test                                     # lint, node tests, Playwright, JS gate
    npm run test:unit                                         # node tests only, works on the host
    docker build -t labeler-contract -f tools/contract/Dockerfile tools && docker run --rm -v "$PWD:/work" labeler-contract
    docker buildx build --platform linux/arm/v6 -f tools/ptouch-build/Dockerfile --output type=local,dest=build/ptouch tools
    tools/dev.sh npm run screenshots                          # docs/screenshots/*.png
    DEV_PORT=8080 tools/dev.sh sh tools/demo.sh               # UI with the simulated printer

From Git Bash, `docker run -v` needs `MSYS_NO_PATHCONV=1` (tools/dev.sh sets it).

## Rules

- The device is a Raspberry Pi Zero without Docker: no runtime dependencies beyond PHP's standard
  library, PHP 7.4 syntax, no build step for `public/`.
- `deploy/install.sh` must work piped into `sh` (no `$0`-relative files unless run as a file) and
  on a Pi Zero: no apt run when nothing is missing, no compiler unless no static build fits.
- Never pass anything to a shell: ptouch-print runs through `Process::run()` with an argument list.
- Behaviour of ptouch-print belongs in the simulator (`tools/ptouch-sim`) first, with tests; the
  contract test checks the assumption against the real tool.
- Every file in `src/`, `tools/ptouch-sim/` and `public/js/` keeps 95 % line coverage. Browser-only
  code (`main.js`, `render.js`) is covered by the Playwright suite (`tests/e2e/fixtures.js`
  writes V8 coverage for c8).
- Keep `package.json` version, `Config::VERSION` and the Playwright image tag in
  `tools/dev/Dockerfile` consistent (`tools/check-versions.js` runs in lint).
- Docs: README for users, docs/ for the rest, CHANGELOG with issue numbers, ADRs in docs/adr.
