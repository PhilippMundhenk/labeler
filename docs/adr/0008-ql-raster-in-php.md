# 0008. Brother QL printers are driven in PHP through the kernel's USB printer device

Date: 2026-11-21 · Status: accepted

## Context

Brother QL printers (DK labels, 300 dpi) are not supported by ptouch-print. The usual way to
drive them on Linux is the Python library brother_ql, or Brother's CUPS driver. The device is a
Raspberry Pi Zero without Docker, and Labeler has no runtime dependencies beyond PHP's standard
library ([ADR 0002](0002-plain-php-no-build.md)). Brother documents the QL raster protocol; a
print job is a few escape commands and one 90-byte line per printed dot row (162 on the wide
models), with no compression needed on the QL-570.

## Decision

The `ql` driver writes the raster protocol itself (`QlRaster`) and talks to the printer through
the kernel's USB printer device (`/dev/usb/lp*`, usblp), which every Raspberry Pi OS kernel has:
write the job, read the 32-byte status answers. Printers are found by the IEEE 1284 id the kernel
publishes in sysfs (`MFG:Brother`, `MDL:QL-…`); the serial number comes from there too. Brother's
reference is the source; brother_ql is the second opinion, and the commands are written in the
order brother_ql writes them, so the contract test can compare whole jobs byte for byte. Where
the two differ for several copies, Labeler follows Brother's reference.

A Unix socket is accepted in place of the device, so the simulator can stand in for the printer
in the unit tests, the browser tests and the demo.

## Consequences

- Nothing is installed for a QL printer: no compiler, no Python, no CUPS. The web server's user
  needs write access to the device (udev rule, as for P-Touch printers).
- The driver waits for the printer's "printing completed" per page and reads its errors, so a
  cutter jam or an open cover during printing is reported, unlike with ptouch-print.
- The behaviour of the printer is in the simulator first; the contract test checks the bytes
  against brother_ql in CI. What neither can show — the orientation on paper, the real error
  answers — needs the test device (a QL-570).
- Two-colour (red/black) media and the network models are not supported.
