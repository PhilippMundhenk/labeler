# 0003. Tests run against a simulated ptouch-print, pinned to the real one by a contract test

Date: 2026-11-08 · Status: accepted

## Context

CI has no label printer, and the version differences of ptouch-print (command line, `--info`
format, options) are exactly what broke the first version. Mocking the PHP wrapper would test
nothing of that.

## Decision

`tools/ptouch-sim/ptouch-print` imitates ptouch-print 1.5 to 1.9 with a printer attached: options,
messages, exit codes and image checks, with printer states (no printer, P-Lite, no tape, errors,
USB permissions, 360 dpi) switchable at runtime. PHP tests, browser tests, the demo and the
screenshots use it. A contract test builds the real ptouch-print from the upstream sources and
checks the assumptions the simulator encodes (PNG format, accepted options, error output).

## Consequences

- Every test drives the real code paths, including process handling and output parsing.
- The simulator can drift from the real tool; the contract test and a weekly run against upstream's
  main branch are the guard. `--info` with a printer cannot be checked without hardware.
- The simulator is code too, and is covered by its own tests.
