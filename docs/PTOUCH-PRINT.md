# ptouch-print with Labeler's patches

Brother's raster protocol can do more than ptouch-print 1.9 offers. Labeler carries small patches
for ptouch-print in [`tools/ptouch-patches`](../tools/ptouch-patches) that add four options. With
a patched ptouch-print, *More options* in the interface switches them on; with the normal one
they stay greyed out and say why.

| Option | Command sent to the printer | In the interface |
| --- | --- | --- |
| `--halfcut` | `ESC i K` with the half cut bit | *Half cut*: the tape is cut, its backing is not, so a strip of labels stays in one piece. Only printers with a half cutter (for example the PT-P900 series); others ignore it. |
| `--cut-every <n>` | `ESC i M 40` (auto cut) and `ESC i A n` | *Cut after every n labels*: with several copies, cut only after every n labels. |
| `--feed <dots>` | `ESC i d n1 n2` | *Margin* in mm: blank tape before and after the label (the printer's default is 2 mm). Less margin wastes less tape. |
| `--hires` | `ESC i K` with the high resolution bit | *High resolution*: twice the dots along the tape (180 × 360 dpi, 360 × 720 dpi on 360 dpi printers). Labeler draws the label at twice the length for it. Only some printers (for example the PT-P750W and the PT-P900 series); it prints slower. |

Two more patches change nothing you can see: the udev rule also gives the group `lp` access (so a
service can print without Labeler's own rule), and sizes are printed with the right format on
32-bit systems (no compiler warnings on Raspberry Pi OS).

Without these options the patched ptouch-print sends exactly the same bytes as the original; the
contract test checks this. The commands come from Brother's *Raster Command Reference*; they are
checked byte for byte against a simulated USB printer in CI, not yet on every printer model.

## Installing it

- **Raspberry Pi or another Linux computer:** run the install script with `--ptouch-patches`,
  for example `curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --agent --ptouch-patches`.
  It downloads the ready-made patched build (`ptouch-print-v1.9-labeler-<arch>`), or with
  `--build-ptouch` builds it from the sources with the patches applied.
- **Docker, printer on the Docker host:** build the image with
  `docker build --build-arg PTOUCH_PATCHES=1 -t labeler .`.
- **By hand:** clone the fork (`git clone -b labeler https://github.com/PhilippMundhenk/ptouch-print.git`),
  or the original with `git checkout v1.9` and `git am /path/to/labeler/tools/ptouch-patches/*.patch`,
  then build as in [INSTALL.md](INSTALL.md#1-ptouch-print).

Going back is the same without `--ptouch-patches` (the script replaces a patched build only when
the version differs; run it with `--build-ptouch` or delete `/usr/local/bin/ptouch-print` first).

## The fork

The same changes, one commit each, are in the fork
[PhilippMundhenk/ptouch-print](https://github.com/PhilippMundhenk/ptouch-print) (branch `labeler`,
on top of v1.9; `master` mirrors the original). ptouch-print is by Dominic Radermacher; the fork
and the patches stay under its license, the GPL-3.0, and say what was changed and when
(`CHANGES.fork`, which the last patch adds).

## Upstream

The patches are written so that they can go to ptouch-print's author unchanged, one feature per
patch, each with a message saying what it does. The last one only adds the fork's notes.
