# 0002. Plain PHP and ES modules: no framework, no build step, no runtime dependencies

Date: 2026-11-08 · Status: accepted

## Context

The device is a Raspberry Pi Zero W, updated by hand. Existing installations already run PHP.
Every runtime dependency is something to install, update and keep compatible on the Pi.

## Decision

The server is a handful of PHP classes loaded by `src/bootstrap.php`, compatible with PHP 7.4 to
8.4 and using only the standard library (no gd, no mbstring, no Composer at runtime). The client is
plain ES modules and CSS, served as they are. The one third-party piece, the QR encoder, is
vendored as a single file. Composer and npm are development tools only.

## Consequences

- Installing is unpacking a zip; updating is replacing a folder.
- No bundler: modules are loaded individually, which is fine on a local network.
- PHP 7.4 syntax limits the server code slightly (no enums, no `match`); CI runs the tests on 7.4,
  8.2 and 8.4.
- Dependabot keeps the development tools current; the vendored QR library is updated by hand
  (DEVELOPMENT.md).
