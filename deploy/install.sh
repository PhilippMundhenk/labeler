#!/bin/sh
# Installs or updates Labeler on Raspberry Pi OS / Debian / Ubuntu.
#
#   curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh -o install.sh
#   less install.sh          # read it first
#   sudo sh install.sh
#
# Run it again to update; config.php and the fonts folder are kept. Options:
#   --version X.Y.Z   install this release instead of the newest
#   --zip FILE        install from a downloaded labeler-X.Y.Z.zip
#   --ptouch-ref REF  ptouch-print tag or branch to build (default v1.9)
#   --skip-ptouch     do not build ptouch-print (it is already installed)
#   --port N          port of the web interface (default 8080)
#   --dir DIR         install folder (default /opt/labeler)
#   --no-service      do not set up systemd and udev (containers, own web server)
set -eu

REPO=PhilippMundhenk/labeler
VERSION=latest
ZIP=
PTOUCH_REF=v1.9
SKIP_PTOUCH=0
PORT=8080
DIR=/opt/labeler
SERVICE=1

say() { printf '\033[1m==> %s\033[0m\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --version) VERSION=${2:?}; shift ;;
    --zip) ZIP=${2:?}; shift ;;
    --ptouch-ref) PTOUCH_REF=${2:?}; shift ;;
    --skip-ptouch) SKIP_PTOUCH=1 ;;
    --port) PORT=${2:?}; shift ;;
    --dir) DIR=${2:?}; shift ;;
    --no-service) SERVICE=0 ;;
    -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
  shift
done

[ "$(id -u)" = 0 ] || die "run as root: sudo sh $0"
case "$PORT" in *[!0-9]*|'') die "--port needs a number" ;; esac
[ -z "$ZIP" ] || [ -f "$ZIP" ] || die "$ZIP not found"
command -v apt-get >/dev/null || die "this script needs apt (Raspberry Pi OS, Debian, Ubuntu)"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

say "Installing packages"
packages="php-cli unzip curl ca-certificates"
[ "$SKIP_PTOUCH" = 1 ] || packages="$packages git cmake gcc make pkg-config gettext libgd-dev libusb-1.0-0-dev"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends $packages >/dev/null

if [ "$SKIP_PTOUCH" = 0 ]; then
  say "Building ptouch-print $PTOUCH_REF"
  git clone -q https://git.familie-radermacher.ch/linux/ptouch-print.git "$work/ptouch-print"
  git -C "$work/ptouch-print" -c advice.detachedHead=false checkout -q "$PTOUCH_REF"
  cmake -S "$work/ptouch-print" -B "$work/ptouch-print/build" >/dev/null
  cmake --build "$work/ptouch-print/build" -j "$(nproc)" >/dev/null
  install -m 755 "$work/ptouch-print/build/ptouch-print" /usr/local/bin/ptouch-print
fi
command -v ptouch-print >/dev/null || die "ptouch-print is not installed; run without --skip-ptouch"
ptouch-print --version 2>&1 | head -n 1

if [ -z "$ZIP" ]; then
  if [ "$VERSION" = latest ]; then
    api=https://api.github.com/repos/$REPO/releases/latest
  else
    api=https://api.github.com/repos/$REPO/releases/tags/v${VERSION#v}
  fi
  say "Downloading Labeler ($VERSION)"
  url=$(curl -fsSL "$api" | grep -o '"browser_download_url": *"[^"]*labeler-[^"]*\.zip"' | head -n 1 | sed 's/.*"\(https[^"]*\)"/\1/')
  [ -n "$url" ] || die "no release package found at $api"
  ZIP=$work/labeler.zip
  curl -fsSL "$url" -o "$ZIP"
fi

say "Installing Labeler to $DIR"
unzip -q "$ZIP" -d "$work/unpacked"
[ -f "$work/unpacked/labeler/public/api.php" ] || die "$ZIP is not a Labeler package"
if [ -d "$DIR" ]; then
  rm -rf "$DIR.old"
  mv "$DIR" "$DIR.old"
  [ ! -f "$DIR.old/config.php" ] || cp -p "$DIR.old/config.php" "$work/unpacked/labeler/"
  if [ -d "$DIR.old/fonts" ]; then
    find "$DIR.old/fonts" -maxdepth 1 -type f ! -name README.md -exec cp -p {} "$work/unpacked/labeler/fonts/" \;
  fi
  echo "previous version kept in $DIR.old"
fi
mkdir -p "$(dirname "$DIR")"
mv "$work/unpacked/labeler" "$DIR"
chown -R root:root "$DIR"
chmod -R u=rwX,go=rX "$DIR"

say "Setting up the user labeler"
getent group lp >/dev/null || groupadd --system lp
if ! id labeler >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin --groups lp labeler
else
  usermod -aG lp labeler
fi

if [ "$SERVICE" = 1 ]; then
  say "Setting up printer access and the service"
  cp "$DIR/deploy/99-labeler-ptouch.rules" /etc/udev/rules.d/
  udevadm control --reload-rules 2>/dev/null && udevadm trigger --subsystem-match=usb 2>/dev/null || true
  sed -e "s#/opt/labeler#$DIR#g" -e "s#0.0.0.0:8080#0.0.0.0:$PORT#" "$DIR/deploy/labeler.service" > /etc/systemd/system/labeler.service
  systemctl daemon-reload
  systemctl enable --now labeler >/dev/null 2>&1
  systemctl restart labeler
  ip=$(hostname -I 2>/dev/null | cut -d' ' -f1)
  say "Done: open http://${ip:-<this computer>}:$PORT/"
  echo "If the printer was already plugged in, unplug it and plug it in again."
else
  say "Done. Start it with: sudo -u labeler php -S 0.0.0.0:$PORT -t $DIR/public"
fi
