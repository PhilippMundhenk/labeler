# Third-party software in the ptouch-print builds

The static ptouch-print files (`ptouch-print-<version>[-labeler]-<arch>`) published as the
pre-release `ptouch-print-<version>` and the copy in the container image are not part of Labeler
and are not under Labeler's MIT license. They contain:

| Component | License | License text | Source |
| --- | --- | --- | --- |
| [ptouch-print](https://git.familie-radermacher.ch/linux/ptouch-print) by Dominic Radermacher | GPL-3.0 | [licenses/ptouch-print.txt](licenses/ptouch-print.txt) | `ptouch-print-<version>[-labeler]-source.tar.gz` next to the binaries: the exact sources the binary was built from, with Labeler's patches applied in the `-labeler` variant |
| [libusb](https://libusb.info) 1.0.29 | LGPL-2.1-or-later | [licenses/libusb.txt](licenses/libusb.txt) | `libusb-1.0.29.tar.bz2` next to the binaries |
| [libgd](https://libgd.github.io) 2.3.3 | GD license (permissive) | [licenses/libgd.txt](licenses/libgd.txt) | `libgd-2.3.3.tar.gz` next to the binaries |
| [libpng](http://www.libpng.org) (Alpine 3.22) | PNG Reference Library License v2 | [licenses/libpng.txt](licenses/libpng.txt) | Alpine Linux |
| [zlib](https://zlib.net) (Alpine 3.22) | zlib license | [licenses/zlib.txt](licenses/zlib.txt) | Alpine Linux |
| [musl](https://musl.libc.org) libc (Alpine 3.22) | MIT | [licenses/musl.txt](licenses/musl.txt) | Alpine Linux |

How the files are built: [Dockerfile](Dockerfile) in this folder (the build steps, compiler
flags and checksums of every download) and the patches in [`../ptouch-patches`](../ptouch-patches).
With the source archives above and this Dockerfile anyone can rebuild ptouch-print, also against
a modified libusb, as the GPL and the LGPL require.

The patches in `tools/ptouch-patches` change ptouch-print and are under its license, GPL-3.0. The
patched sources carry `CHANGES.fork`, which says what was changed and when; the same commits are
in the fork <https://github.com/PhilippMundhenk/ptouch-print>.
