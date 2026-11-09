# Installation

Labeler runs where the printer is plugged in, typically a Raspberry Pi (a Zero W is enough) with
Raspberry Pi OS. It needs PHP 7.4 or newer and ptouch-print 1.5 or newer. PHP 7.4 is what Debian
11 (bullseye) ships; bullseye's long-term support ended in 2026, so it gets no security updates any
more: prefer Raspberry Pi OS bookworm or newer (PHP 8.2). The steps below assume
Raspberry Pi OS or Debian; other Linux distributions work the same way with their package names.

## Install script

[`deploy/install.sh`](../deploy/install.sh) does steps 1 to 4a below: installs the missing
packages, puts ptouch-print in place, downloads the newest release to `/opt/labeler`, creates the
user `labeler`, installs the udev rule and the systemd service. Run it again to update;
`config.php` and the fonts are kept, the previous version stays in `/opt/labeler.old`.

```sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --port 80
sudo sh install.sh --zip labeler-1.0.0.zip      # a downloaded release
sudo sh deploy/install.sh                       # in a clone: installs the clone
sh install.sh --help
```

What it installs, so it stays quick on a Pi Zero:

- **Packages:** only the missing ones of `php-cli curl ca-certificates` (and `unzip` for a
  release zip). `apt` does not run at all when everything is there, so updates take seconds.
- **ptouch-print:** kept when the installed one is already the wanted version. Otherwise it
  downloads a ready-made static build (one file of about 0.5 MB without library dependencies,
  from [the ptouch-print builds](https://github.com/PhilippMundhenk/labeler/releases/tag/ptouch-print-v1.9),
  checked against `SHA256SUMS`): `armv6` for 32-bit Raspberry Pi OS (every Pi, Zero included),
  `arm64` and `amd64`. The static build reads PNG images only, which is all Labeler uses;
  ptouch-print's own `--text` is missing from it. Only when there is no build for the system,
  or with `--build-ptouch`, does it install a compiler and libraries (about 100 packages) and
  build ptouch-print from the sources, which takes long on a Pi Zero.
- **Labeler:** the newest release; before the first release, the branch `main` (`--branch NAME`
  picks another branch).

CI runs the script on Debian bookworm and trixie for every change.

## Printer agent: printer and interface on different computers

The interface can run on a server (for example in Docker) while the printer hangs on a small
computer elsewhere. That computer only runs the printer agent: Labeler's API without the
interface, protected by an access token.

```sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --agent
```

installs ptouch-print, the agent in `/opt/labeler-agent`, the udev rule and the service
`labeler-agent` on port 8631, generates a token (kept in `/opt/labeler-agent/config.php`, readable
only by root and the service) and prints:

```
LABELER_PRINTER_URL=http://192.0.2.10:8631
LABELER_PRINTER_TOKEN=3f9c...
```

Set both on the interface (`config.php` keys `printer_url` and `printer_token`, or environment
variables). The printer badge and the details dialog then show the agent; when it cannot be
reached or the token is wrong, they say so. Running the script again with `--agent` updates the
agent and keeps the token. Without `--no-service`, `systemctl status labeler-agent` shows it.

The token travels in plain HTTP. On your own network that is usually fine; across networks you
do not trust, put the agent behind a reverse proxy with TLS or a VPN.

The agent by hand, without the script:

```sh
sudo apt install --no-install-recommends php-cli
# ptouch-print: see step 1 below (static build or from the sources)
cd /opt && sudo unzip ~/labeler-agent-1.0.0.zip         # from the releases page
printf "<?php\nreturn ['token' => '%s'];\n" "$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')" \
  | sudo tee /opt/labeler-agent/config.php >/dev/null
sudo useradd --system --groups lp labeler
sudo chown root:labeler /opt/labeler-agent/config.php && sudo chmod 640 /opt/labeler-agent/config.php
sudo cp /opt/labeler-agent/deploy/99-labeler-ptouch.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules                       # then unplug and plug in the printer
sudo cp /opt/labeler-agent/deploy/labeler-agent.service /etc/systemd/system/
sudo systemctl enable --now labeler-agent
sudo cat /opt/labeler-agent/config.php                    # the token for the interface
```

## 1. ptouch-print

Debian and Raspberry Pi OS only package the CUPS driver, not ptouch-print. Either download the
static build for your system from the
[ptouch-print builds](https://github.com/PhilippMundhenk/labeler/releases/tag/ptouch-print-v1.9)
and check it:

```sh
curl -fsSLO https://github.com/PhilippMundhenk/labeler/releases/download/ptouch-print-v1.9/ptouch-print-v1.9-armv6
curl -fsSLO https://github.com/PhilippMundhenk/labeler/releases/download/ptouch-print-v1.9/SHA256SUMS
sha256sum --ignore-missing -c SHA256SUMS
sudo install -m 755 ptouch-print-v1.9-armv6 /usr/local/bin/ptouch-print
```

or build it from the sources (several minutes on a Pi Zero):

```sh
sudo apt install git cmake gcc make pkg-config gettext libgd-dev libusb-1.0-0-dev
git clone https://git.familie-radermacher.ch/linux/ptouch-print.git
cd ptouch-print
git checkout v1.9            # or the newest tag: git tag
cmake -S . -B build && cmake --build build
sudo cmake --install build   # installs /usr/local/bin/ptouch-print
ptouch-print --version
```

With the printer connected and switched on (mode switch on **E**, not EL), check it:

```sh
sudo ptouch-print --info
```

`maximum printing width for this tape is 76px` and `media width = 12 mm` mean the printer and the
tape are found.

## 2. Labeler

```sh
sudo apt install php-cli unzip
cd /opt
sudo unzip ~/labeler-1.0.0.zip   # from the releases page
```

The release contains `public/` (the web root), `src/` (the PHP code, outside the web root),
`deploy/` (examples), `fonts/` and `config.example.php`.

## 3. Printer access for the service

ptouch-print's own udev rule only lets users who are logged in at the console use the printer.
A service needs a group:

```sh
sudo useradd --system --groups lp labeler
sudo cp /opt/labeler/deploy/99-labeler-ptouch.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules
```

Unplug the printer and plug it in again. The rule gives the group `lp` access to Brother USB
devices.

## 4a. Run it with PHP's built-in server (recommended)

No Apache or nginx needed:

```sh
sudo cp /opt/labeler/deploy/labeler.service /etc/systemd/system/
sudo systemctl enable --now labeler
```

Open `http://<raspberry-pi>:8080/`. The port is set in `ExecStart` of the unit file. The print log
is kept in `/var/lib/labeler`. Check the service with `systemctl status labeler` and
`journalctl -u labeler`.

## 4b. Or use your web server

Make `/opt/labeler/public` the document root (or an alias) and make sure the PHP process user is in
the group `lp`:

- nginx with PHP-FPM: [`deploy/nginx.conf`](../deploy/nginx.conf), then
  `sudo usermod -aG lp www-data` and restart PHP-FPM.
- lighttpd: [`deploy/lighttpd.conf`](../deploy/lighttpd.conf) serves Labeler under `/labeler/`,
  then `sudo usermod -aG lp www-data` and restart lighttpd.
- Apache: `Alias /labeler /opt/labeler/public` with `mod_php` or PHP-FPM.

With your own web server, let it handle caching and compression (nginx: `gzip on;`, `etag on;`);
with the built-in server, `src/router.php` does it (the service file already uses it).

For the print log, create a writable data folder: `sudo install -d -o www-data /opt/labeler/data`.
Never make the whole `/opt/labeler` the document root: `src/` and `config.php` must not be served.

## Configuration

Optional. Copy `config.example.php` to `/opt/labeler/config.php` and change what you need, or set
`LABELER_*` variables (`Environment=` lines in `labeler.service`). All settings are listed in the
[README](../README.md#configuration). Fonts: copy `.ttf`, `.otf`, `.woff` or `.woff2` files into
`/opt/labeler/fonts`.

## Updating

```sh
cd /opt
sudo mv labeler labeler.old
sudo unzip ~/labeler-1.1.0.zip
sudo cp labeler.old/config.php labeler/ 2>/dev/null; sudo cp -r labeler.old/fonts/. labeler/fonts/
sudo systemctl restart labeler
```

Coming from the first version of Labeler (a single `index.php` in the web root): remove
`index.php`, `main.css` and `preview.png` from the old web root and install as above.

## Uninstalling

```sh
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --uninstall
curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --uninstall --agent
sudo sh /opt/labeler/deploy/install.sh --uninstall          # the copy that came with the installation
```

stops and removes the service and the program files. `config.php` (the agent's token), the fonts
and the print log and saved labels in `/var/lib/labeler` stay, so a later install picks them up
again. When neither the interface nor the agent is left, the udev rule and the user `labeler`
go too. `--purge` also deletes the kept files and `/usr/local/bin/ptouch-print`. PHP stays
installed (`sudo apt remove php-cli`).

By hand (for the agent, use `labeler-agent` in every name):

```sh
sudo systemctl disable --now labeler
sudo rm /etc/systemd/system/labeler.service && sudo systemctl daemon-reload
sudo rm -rf /opt/labeler /opt/labeler.old /var/lib/labeler
# when neither the interface nor the agent is left:
sudo rm /etc/udev/rules.d/99-labeler-ptouch.rules && sudo udevadm control --reload-rules
sudo userdel labeler
sudo rm /usr/local/bin/ptouch-print
```

## Troubleshooting

The badge in the top right corner shows the state; click it for the details and the print log
with the exact ptouch-print command and output.

| Message | What to do |
| --- | --- |
| ptouch-print was not found | Install it (step 1) or set `ptouch_bin` to its full path, e.g. `/usr/local/bin/ptouch-print`. |
| No printer found | USB cable, power, mode switch on **E**. `lsusb` should list a Brother device. |
| A printer is attached, but the web server may not use it | udev rule and group (step 3); unplug and plug in the printer; restart the service. |
| The printer is in P-Lite mode | Mode switch from EL to E, or hold the PLite button for two seconds. |
| No tape cassette inserted | Insert tape; close the cover. |
| The cover is open / Cutter jam / Replace the tape cassette / overheated / batteries weak | What the printer reports about itself; the message says what to do. Unknown error bits are shown as `error 0x…`. |
| Chain printing / auto cut is greyed out | The installed ptouch-print is too old: chain needs 1.6, auto cut 1.8. Auto cut also needs a printer with a cutter. |

### The printer switches itself off

P-touch printers switch themselves off after a while without use (auto power off). Switched off,
they disappear from USB, so Labeler shows "No printer" and, if it saw the printer a moment
before, says that it probably switched itself off. **A P-touch printer cannot be switched on over
USB**: Brother's raster command reference has no power command, and a computer can only wake a
sleeping USB device, not one that is off. What helps:

- Turn auto power off off: Brother's *Printer Setting Tool* (Windows, macOS), *Device Settings*,
  *Auto Power Off* = *None* (separately for AC adapter and batteries). The printer keeps the
  setting, so this is needed once.
- Some models (PT-P900 series) have *Auto Power On*: they switch on when power is connected, so a
  smart plug can switch them on and off.
- Switching the USB port off and on (`uhubctl`) does not help: the printer has its own power supply.

Run ptouch-print as the service user to see what the service sees:

```sh
sudo -u labeler ptouch-print --info
```
