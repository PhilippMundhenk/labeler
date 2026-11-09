# Docker

The image `ghcr.io/philippmundhenk/labeler` contains the interface, PHP and ptouch-print. It
runs as user 1000, listens on port 8080 and keeps the print log and fonts in `/data`.

## Image tags

| Tag | What it is | Updated | Use it for |
| --- | --- | --- | --- |
| `main` | the newest state of the branch `main`, every merged change | on every change, within minutes | testing changes before they are released |
| `latest` | the newest release | only when a version is released (tag `vX.Y.Z`) | normal use (`docker-compose.yml` uses it) |
| `X.Y.Z` (e.g. `1.0.0`) | exactly that release | never | pinning a version |
| `X.Y` (e.g. `1.0`) | the newest bug-fix release of that version | with each `X.Y.*` release | staying on a version but getting fixes |

Every tag is built for `linux/amd64`, `linux/arm64` and `linux/arm/v7`. Check what you run with
`docker image inspect ghcr.io/philippmundhenk/labeler:latest --format '{{.Created}}'`, and update
with `docker compose pull && docker compose up -d`.

The image also carries the standard OCI labels (`org.opencontainers.image.title`, `description`,
`source`, `licenses`, `version`, `revision`, `created`), so
`docker image inspect ghcr.io/philippmundhenk/labeler:latest --format '{{json .Config.Labels}}'` shows
the version and the commit it was built from.

## Try it without a printer

```sh
docker run --rm -p 8080:8080 -e LABELER_DEMO=1 ghcr.io/philippmundhenk/labeler
```

Open <http://localhost:8080/>. The demo uses the simulated printer (12 mm tape, ptouch-print 1.9);
printed labels are written as PNG and JSON to `/data/demo-printed`. Mount a folder on `/data` to
look at them. Other simulated printers: `-e PTSIM_TAPE_MM=24 -e PTSIM_VERSION=1.5` and the other
`PTSIM_*` variables in [tools/ptouch-sim/README.md](../tools/ptouch-sim/README.md).

## Printer on another computer (recommended)

The printer hangs on a small computer anywhere, the interface runs on your server:

1. On the computer with the printer: `sudo sh install.sh --agent`
   ([INSTALL.md](INSTALL.md#printer-agent-printer-and-interface-on-different-computers)). It prints
   `LABELER_PRINTER_URL` and `LABELER_PRINTER_TOKEN`.
2. Put both into [`docker-compose.yml`](../docker-compose.yml) and run `docker compose up -d`.

The printer badge shows the agent's printer; the details dialog shows the agent's address.

## Printer on the Docker host

Pass the USB bus and the group that owns the printer's device node:

```yaml
    devices:
      - /dev/bus/usb:/dev/bus/usb
    group_add:
      - "7"          # the host's lp group with deploy/99-labeler-ptouch.rules installed
```

Install the udev rule on the host (`deploy/99-labeler-ptouch.rules`), then unplug and plug in the
printer. `docker compose logs` says on start which way the container prints.

## The image as printer agent

The same image works as an agent on a computer with Docker: set `LABELER_TOKEN` to a long random
value (`openssl rand -hex 24`), pass the USB device as above and publish the port. An instance
with `LABELER_TOKEN` only answers API requests with the token, so its own web page cannot print.

## Settings

All `LABELER_*` variables from the [README](../README.md#configuration) work. In the container
`LABELER_DATA_DIR` is `/data` and `LABELER_FONTS_DIR` is `/data/fonts`. Additional:

| Variable | Default | Purpose |
| --- | --- | --- |
| `LABELER_DEMO` | `0` | `1` prints to the simulated printer |
| `PORT` | `8080` | Port inside the container |
| `LABELER_PASSWORD` | empty | Password for the interface (plain or a `password_hash()` hash) |

The image is built for amd64, arm64 and arm/v7. Build it yourself with `docker build -t labeler .`
(`--build-arg PTOUCH_REF=v1.8` for another ptouch-print version).
