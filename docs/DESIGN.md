# Design

Status date: 2026-11-08

## Goal

Print labels on a P-Touch printer from a phone or computer in the house, with a preview that is
exactly the printout, without installing anything on the client and with as little as possible on
the small computer the printer hangs on.

## How it works

```
 Browser                                            Raspberry Pi
 ───────────────────────────────────────────        ──────────────────────────────────────────
 editor (main.js) ── label model (label.js)          public/api.php ── src/Api.php
        │                                                │  status:  ptouch-print --version
        ▼                                                │           ptouch-print --info
 render.js: one canvas per block at the tape's           │  print:   checks the PNG (Png.php),
 pixel height and dpi ─▶ black/white bitmap              │           writes a temporary file,
 (bitmap.js; qr.js, code128.js draw directly)            │           ptouch-print [--copies n]
        │                                                │           [--chain] [--precut]
        ▼                                                │           --image label.png
 png.js: 1-bit palette PNG ── POST JSON ───────────────▶ │  (proc_open with an argument list,
                                                         │   no shell; output in the print log)
 preview canvas (tape colour from --info) ◀── status ─── │
```

Icons are searched and downloaded by the browser from an Iconify API (`icon_api`); the SVG of
the chosen icon is stored in the label and rendered like an image, so neither the Pi nor printing
depend on that service.

The browser does all the drawing (ADR [0001](adr/0001-render-in-the-browser.md)). The server knows
nothing about fonts, QR codes or layout; it answers four questions: what is attached (`status`),
print this image (`print`), which fonts are in the folder (`fonts`, `font`), and what happened
(`log`).

## ptouch-print versions

Labeler only uses options that exist in every version since 1.5 (`--info`, `--version`, `--image`)
and adds the others when `--version` says they are there.

| Version | Date | Matters for Labeler |
| --- | --- | --- |
| 1.5 | 2021-10 | `--info` without printer width; `--text a b` takes several lines. No `--copies`: Labeler runs ptouch-print once per copy. |
| 1.6 | 2024-10 | `--copies`, `--chain`, `--force-tape-width`; `--info` shows colour names (`01 (White)`). |
| 1.7 | 2025-08 | argp command line: positional arguments are rejected, so `--text "a" "b"` fails with "No arguments supported" (the cause of #1); second line is `--newline`. `--info` adds the printer width. |
| 1.8 | 2026-03 | `--precut`, `--align`, `--timeout`; hex values with `0x`; PNG resolution 180 dpi. `--precut` sends `ESC i M 0x40`, which Brother's raster command reference defines as **auto cut**; Labeler calls it that. Without it, the printer's own cut setting applies. |
| 1.9 | 2026-09 | `printer has N dpi` line (360 dpi printers), `--serial`, `--list-connected`, serial in the USB message. |

`ptouch-print --image` prints black for palette colour 1 when it is darker than colour 0. A
true-colour PNG loads without an error but prints garbage, so the browser writes a 1-bit palette
PNG and the server refuses anything else. The image may not be higher than the tape's pixel
width (`maximum printing width for this tape`); it is centred on the print head.

The contract test ([DEVELOPMENT.md](DEVELOPMENT.md#contract-test)) builds versions 1.6 to 1.9 from
the upstream sources and checks all of this on every change; a weekly job does the same with the
upstream main branch.

## Decisions

See the [architecture decision records](adr/README.md):

1. [0001](adr/0001-render-in-the-browser.md) Labels are rendered in the browser and printed as a 1-bit PNG.
2. [0002](adr/0002-plain-php-no-build.md) Plain PHP and ES modules, no framework, no build step, no runtime dependencies.
3. [0003](adr/0003-simulated-printer.md) Tests run against a simulated ptouch-print; a contract test pins the simulator to the real tool.
4. [0004](adr/0004-php-built-in-server.md) PHP's built-in server under systemd is the default deployment.
5. [0005](adr/0005-labels-in-the-browser.md) Saved labels live in the browser's storage.

## Limits and open questions

- Tested against the simulator and real ptouch-print builds without a printer; real printers are
  the user's test.
- Text is drawn with the browser's fonts, so the same label can differ slightly between devices
  when a font is missing on one of them. Fonts in the server's `fonts/` folder avoid that.
- Anti-aliased text is cut at 50 % grey. Very small text (below about 6 pt at 180 dpi) is better
  set in bold.
- One printer per installation (the `serial` setting picks one when several are attached).
