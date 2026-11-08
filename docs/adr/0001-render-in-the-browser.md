# 0001. Labels are rendered in the browser and printed as a 1-bit PNG

Date: 2026-11-08 · Status: accepted

## Context

The first version passed the form fields to `ptouch-print --text` through a shell and showed a
preview written by `--writepng` to a fixed path. That broke three ways: ptouch-print 1.7 changed
its command line so a second line is rejected (#1), the shell made the text a command injection
(#2), and fonts, QR codes and images (#4, #5, #6) would each have needed server-side tools on a
Raspberry Pi Zero. ptouch-print's own text rendering only offers a font name and a size.

## Decision

The browser draws the whole label on canvases at the tape's pixel height and the printer's dpi
(both from `--info`), turns it into a black and white bitmap, and encodes a 1-bit palette PNG, the
format `ptouch-print --image` prints correctly. The server checks the PNG (palette, at most two
colours, not higher than the tape) and runs `ptouch-print --image` with an argument list.

## Consequences

- The preview is the printout, pixel for pixel, and updates while typing without a request.
- Every font the browser has can be used; QR codes, barcodes and images need no server packages.
- The server only depends on `--info`, `--version` and `--image`, which exist since 1.5; the text
  options of ptouch-print are not used at all, so their changes cannot break printing again.
- The Pi does almost no work; a label is a few kilobytes.
- A label looks different on two devices when a font exists on only one of them. Fonts in the
  server's `fonts/` folder are loaded by every browser.
- Browser support: canvas, `CompressionStream` and ES modules (all current browsers).
