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
- Images: PNG, JPEG, SVG, black and white or dithered, threshold, invert (#6).
- Labels made of several blocks; margins, gap, minimum length, position, frame; copies, chain
  printing, cut before the label; saved labels; download as PNG (#7).
- Responsive interface with light and dark mode, printer badge, tape colours in the preview,
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
