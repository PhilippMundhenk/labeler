# ptouch-print simulator

`ptouch-print` in this folder behaves like the real
[ptouch-print](https://git.familie-radermacher.ch/linux/ptouch-print.git) with a P-Touch printer
attached: same options, messages and exit codes for versions 1.5 to 1.9. It needs only PHP.
The unit tests, the browser tests, the demo mode and the screenshots use it.

```sh
PTSIM_OUT=/tmp/labels tools/ptouch-sim/ptouch-print --info
LABELER_PTOUCH_BIN=$PWD/tools/ptouch-sim/ptouch-print php -S 127.0.0.1:8080 -t public
```

Printed labels are written to `PTSIM_OUT` as `label-NNNN.png` with a `label-NNNN.json` record
(size, black pixels, copies, chain, precut). Nothing is sent to a printer.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PTSIM_VERSION` | `1.9` | ptouch-print version to imitate (`1.5` ... `1.9`): command line and `--info` format |
| `PTSIM_PRINTER` | `PT-P700` | model (`PT-P700`, `PT-P750W`, `PT-H500`, `PT-D600`, `PT-P300BT`, `PT-P900W`) or `none` |
| `PTSIM_SERIAL` | `000F1Z401234` | USB serial number |
| `PTSIM_MODE` | `normal` | `plite` (mode switch on EL), `unsupported`, `access` (no USB permission) |
| `PTSIM_TAPE_MM` | `12` | inserted tape: 4, 6, 9, 12, 18, 21, 24, 36; `0` = no cassette |
| `PTSIM_TAPE_COLOR`, `PTSIM_TEXT_COLOR` | `1`, `8` | colour codes as reported by the printer (white, black) |
| `PTSIM_ERROR` | `0` | status error bits as ptouch-print prints them, e.g. `4096` (0x1000) cover open, `4` cutter jam, `8` weak batteries |
| `PTSIM_FAIL` | empty | `print` makes printing fail with a USB write error; `jam` lets the print end with exit code 0 like the real tool, then the printer reports a cutter jam (needs `PTSIM_STATE`) |
| `PTSIM_DELAY_MS` | `0` | how long a print takes |
| `PTSIM_STATE` | unset | JSON file with the same keys in lower case; read on every call, so tests can change the state of a running server |
