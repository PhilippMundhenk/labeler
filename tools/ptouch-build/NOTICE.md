# Third-party software in the ptouch-print builds

The static ptouch-print files (`ptouch-print-<version>[-labeler]-<arch>`) published as the
pre-release `ptouch-print-<version>` and the copy in the container image are not part of Labeler
and are not under Labeler's MIT license. They contain:

| Component | License | License text (next to the binaries) | Source |
| --- | --- | --- | --- |
| [ptouch-print](https://git.familie-radermacher.ch/linux/ptouch-print) by Dominic Radermacher | GPL-3.0 | `LICENSE-ptouch-print.txt` | `ptouch-print-<version>[-labeler]-source.tar.gz` next to the binaries: the exact sources the binary was built from, with Labeler's patches applied in the `-labeler` variant |
| [libusb](https://libusb.info) 1.0.29 | LGPL-2.1-or-later | `LICENSE-libusb.txt` | `libusb-1.0.29.tar.bz2` next to the binaries |
| [libgd](https://libgd.github.io) 2.3.3 | GD license (permissive) | `LICENSE-libgd.txt` | `libgd-2.3.3.tar.gz` next to the binaries |
| [libpng](http://www.libpng.org) (Alpine 3.22) | PNG Reference Library License v2 | `LICENSE-libpng.txt` | Alpine Linux |
| [zlib](https://zlib.net) (Alpine 3.22) | zlib license | `LICENSE-zlib.txt` | Alpine Linux |
| [musl](https://musl.libc.org) libc (Alpine 3.22) | MIT | `LICENSE-musl.txt` | Alpine Linux |

How the files are built: `ptouch-build.Dockerfile` (the build steps, compiler flags and checksums
of every download) and `ptouch-patches.tar.gz` (Labeler's patches), both next to the binaries and
in the repository under
[tools/ptouch-build](https://github.com/PhilippMundhenk/labeler/tree/main/tools/ptouch-build) and
[tools/ptouch-patches](https://github.com/PhilippMundhenk/labeler/tree/main/tools/ptouch-patches);
the license texts are also in
[tools/ptouch-build/licenses](https://github.com/PhilippMundhenk/labeler/tree/main/tools/ptouch-build/licenses).
With the source archives and these build files anyone can rebuild ptouch-print, also against a
modified libusb, as the GPL and the LGPL require.

The patches in `tools/ptouch-patches` change ptouch-print and are under its license, GPL-3.0. The
patched sources carry `CHANGES.fork`, which says what was changed and when; the same commits are
in the fork <https://github.com/PhilippMundhenk/ptouch-print>.
