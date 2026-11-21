# Brother QL simulator

`ql-printer` in this folder is a Brother QL printer on a Unix socket: Labeler's `ql` driver
opens it like the USB printer device `/dev/usb/lp0`. `QlSimulator.php` reads Brother's raster
protocol the way the printer does (invalidate, initialize, status request, raster mode, print
information, cut and margin commands, raster lines, print), answers with the 32-byte status and
checks the job: the media in the print information must be the inserted one, every raster line
must have the model's length (90 bytes, 162 on the wide models), the number of lines must be the
announced one. Anything else ends the job with an error status, as on the printer. It needs only
PHP. The unit tests, the browser tests and the demo use it.

```sh
QLSIM_OUT=/tmp/labels tools/ql-sim/ql-printer /tmp/ql.sock &
LABELER_PRINTER_DRIVER=ql LABELER_QL_DEVICE=/tmp/ql.sock php -S 127.0.0.1:8080 -t public
```

Printed pages are written to `QLSIM_OUT` as `label-NNNN.png`, turned back into the label as the
editor drew it (rows across the printable width, columns along the label), with a
`label-NNNN.json` record: media, size, black dots, dots outside the printable area (`outside`,
always 0 unless the driver is wrong), page number and the settings of the page (`autocut`,
`cut_every`, `cut_at_end`, `margin`, `quality`, `media_type`, `width_mm`, `length_mm`).

| Variable | Default | Meaning |
| --- | --- | --- |
| `QLSIM_PRINTER` | `QL-570` | model, reported by its model code in the status (`QlRaster::MODELS`) |
| `QLSIM_SERIAL` | `000G0Z123456` | serial number (the driver reads it from sysfs; kept for the records) |
| `QLSIM_MEDIA` | `62` | inserted DK media, an id of `QlRaster::MEDIA` (`62`, `29`, `29x90`, `62x100`, `d24`, ...); `none` = no roll |
| `QLSIM_ERROR` | `0` | status error bits, low byte error information 1, high byte 2: `4` cutter jam, `4096` (0x1000) cover open |
| `QLSIM_FAIL` | empty | `jam`: the page fails with a cutter jam while printing; `silent`: the printer never answers |
| `QLSIM_DELAY_MS` | `0` | how long a page takes |
| `QLSIM_STATE` | unset | JSON file with the same keys in lower case; read for every connection and command, so tests can change the state of a running printer |

What it does not check: the meaning of the cut settings (they are recorded), compression (`M`),
two-colour media and the network models.
