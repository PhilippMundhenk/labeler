# API

The interface and the printer agent talk to the same small JSON API: `public/api.php` with the
action in the query string, for example `GET /api.php?action=status`. Anything that can send
HTTP can use it, for example to print labels from a script.

## Access

| Setting | What the API asks for |
| --- | --- |
| neither `token` nor `password` set | nothing (meant for your own network) |
| `token` set (the printer agent) | `Authorization: Bearer <token>` or `X-Labeler-Token: <token>` on every action except `health`; `401` otherwise |
| `password` set | the session cookie from `login`, or the token; `401` with `"login": true` otherwise |

`health`, `login` and `logout` never need access. A wrong HTTP method gives `405` with an `Allow`
header, an unknown action `404`.

Every action that talks to a printer takes an optional `serial=<USB serial>` in the query string
(letters, digits, `.`, `_`, `-`; up to 64 characters) to pick one of several printers
(ptouch-print 1.9). With `printer_url` set, the interface forwards `status`, `print`, `log` and
`printers` to that printer agent.

## Actions

| Action | Method | Request | Answer |
| --- | --- | --- | --- |
| `health` | GET | | `{"ok": true, "version": "1.0.0"}` |
| `status` | GET | | printer and tape, see below |
| `print` | POST | JSON, see below | `200` printed, `4xx` request refused, `502` the printer reported a problem, `503` no printer ready |
| `fonts` | GET | | `{"fonts": [{"name": "...", "file": "..."}]}`: fonts in the `fonts/` folder |
| `font` | GET | `file=<file name>` from `fonts` | the font file |
| `log` | GET | | `{"enabled": bool, "entries": [{"time", "args", "code", "output", "ms"}]}`: the last ptouch-print runs |
| `printers` | GET | | `{"supported": bool, "printers": [{"model", "serial", "usable"}]}` (needs ptouch-print 1.9) |
| `login` | POST | `{"password": "..."}` | `200` and the session cookie, `401` wrong password |
| `logout` | POST | | `200`, deletes the cookie |
| `labels` | GET | | `{"enabled": bool, "labels": {"<name>": {"label": {...}, "updated": "<ISO time>"}}}`: labels saved on the server |
| `label-save` | POST | `{"name": "...", "label": {...}}` | `200`; `422` name too long, too many labels or label too large |
| `label-delete` | POST | `{"name": "..."}` | `200` |

POST bodies are JSON (`Content-Type: application/json`). Errors come as `{"error": "<message>"}`.

### `status`

```json
{
  "app_version": "1.0.0",
  "ptouch_version": "1.9",
  "features": {"copies": true, "chain": true, "precut": true, "serial": true,
               "feed": false, "halfcut": false, "cut_every": false, "hires": false},
  "max_copies": 50,
  "icon_api": "https://api.iconify.design",
  "password": false,
  "shared_labels": true,
  "printer": {"ready": true, "connected": true, "message": "", "model": "PT-P700",
              "media_width_mm": 12, "tape_px": 76, "dpi": 180, "error_code": 0, ...},
  "agent": null
}
```

`features` says which options the installed ptouch-print has (`feed`, `halfcut`, `cut_every` and
`hires` need [the patches](PTOUCH-PRINT.md)). `printer.message` explains in words why the printer is
not ready. `agent` is set when the interface uses a printer agent: `{"url", "version", "reachable"}`.

### `print`

```json
{"png": "<base64>", "copies": 1, "chain": false, "precut": false,
 "feed": 14, "halfcut": false, "cut_every": 5, "hires": false}
```

Only `png` is needed: a PNG at most as high as the tape's printable width (`printer.tape_px`), any
length, black and white; `data:image/png;base64,` in front is allowed. `copies` is 1 to
`max_copies`. `feed` (0 to 1000 dots) and `cut_every` (1 to 99) may be left out; unsupported
options are skipped and named in `notes`. The answer:

```json
{"ok": true, "error": null, "output": "<what ptouch-print printed>", "notes": [],
 "width_px": 361, "height_px": 76, "length_mm": 50.9, "copies": 1}
```

After printing, Labeler asks the printer again, because ptouch-print ends with success even
when the cutter jammed or the cover was opened during printing; such a problem comes back as
`502` with the printer's message in `error`.

Example, from a computer on the network:

```sh
curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"png\": \"$(base64 -w0 label.png)\", \"copies\": 2}" \
  "http://<printer-agent>:8631/api.php?action=print"
```
