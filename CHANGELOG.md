# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

The first versioned release: Labeler was rewritten from a single PHP page into a label editor.

### Added

- Labels are drawn in the browser and printed as a 1-bit PNG with `ptouch-print --image`; the
  preview is the printout and updates while typing (#3).
- Font selection (built-in list, fonts in the server's `fonts/` folder, fonts of the device),
  size, bold, italic, alignment, white on black, any number of lines (#4).
- QR codes and Code 128 barcodes (#5).
- Several printers: with ptouch-print 1.9 the details dialog lists the attached printers and the
  choice is kept per browser; also through a printer agent (#28).
- Recent prints: the last 20 printed labels with a small picture, to load or print again (#27).
- Drag and drop: move blocks by their handle, drop image files onto the page to add them (#26).
- Cable flags: the content twice with the cable's circumference between, the second half turned
  so both sides read upright; the cable label template uses it (#20).
- Text: a size per line (e.g. a large first and a small second line), underline, letter
  spacing (#24).
- Text, barcode, image and icon blocks can be turned by 90, 180 or 270 degrees; turned blocks
  run across the tape (#23).
- Templates to start from: asset tag, Wi-Fi guest access, cable label, folder spine, address,
  name tag, shelf label with barcode (#19).
- Undo and redo for every change (buttons, Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y); a typing burst is one step (#25).
- A printer that switched itself off is explained, with how to turn auto power off off; the docs
  say why a P-Touch printer cannot be switched on over USB (#49).
- Mirrored printing for clear tape read from the back (#50).
- Cut marks at both ends of a label, to cut chained labels by hand (#51).
- "Cut before the label" is called "Auto cut", which is what ptouch-print's `--precut` switches on
  (`ESC i M 0x40` in Brother's raster command reference) (#48).
- An error during printing (cutter jam, cover opened, tape ran out) is reported instead of
  "printed": ptouch-print does not notice it, so the status is checked after every job (#47).
- Printer errors are named with what to do: cover open, cutter jam, no or wrong tape, weak
  batteries, wrong adapter, overheating (#46).
- Barcode types EAN-13, UPC-A (check digit added or verified) and Code 39 (#22).
- QR helpers for Wi-Fi, contacts (vCard), e-mail and phone numbers with correct escaping (#21).
- Icon blocks with search over Material Design Icons and Simple Icons through the Iconify API,
  `icon_api` setting (#16).
- Placeholders {date}, {time}, {datetime} and a counter {n} that numbers every copy (#17).
- Batch printing: one label per row of a list, CSV file or spreadsheet paste, with {1}, {2} or
  {column name} in any block, a row preview and chained printing (#18).
- Images: PNG, JPEG, SVG, black and white or dithered, threshold, invert (#6).
- Labels made of several blocks; margins, gap, minimum length, position, frame; copies, chain
  printing, cut before the label; saved labels; download as PNG (#7).
- Responsive interface with light and dark mode, toggle chips, inline block errors, reasons
  next to a disabled Print button, printer badge, tape colours in the preview,
  details dialog with the print log, web app manifest (#8).
- JSON API (`status`, `print`, `fonts`, `font`, `log`, `health`), `config.php` and `LABELER_*`
  settings (#9).
- Simulated ptouch-print, PHP, JS and browser tests with 95 % coverage gates (#10).
- Contract test against ptouch-print 1.6 to 1.9 built from the upstream sources, weekly run
  against upstream (#11).
- CI, release zip, Dependabot, secret scanning (#12).
- Install and update script for Raspberry Pi OS and Debian, tested in CI (#33).
- Printer agent: the API alone on the computer with the printer, protected by an access token
  (`install.sh --agent`); the interface forwards to it with `printer_url` and `printer_token` (#42).
- Docker image `ghcr.io/philippmundhenk/labeler` (amd64, arm64, arm/v7) with ptouch-print, demo
  mode with the simulated printer, docker-compose example; works as interface or agent (#43).
- Documentation, release package, systemd unit, udev rule, nginx and lighttpd examples (#13, #14).

### Fixed

- Preview and two-line labels failed with ptouch-print 1.7 and newer; tape width shown wrong with
  1.9; preview written to a hard-coded path and cached by the browser (#1).

### Security

- Label text was passed to a shell unescaped (command injection) and echoed without HTML
  escaping (#2).
