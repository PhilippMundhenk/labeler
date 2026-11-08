#!/bin/sh
# Installs or updates Labeler on Raspberry Pi OS / Debian / Ubuntu.
#
#   curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh -o install.sh
#   less install.sh          # read it first
#   sudo sh install.sh
#
# Run it again to update; config.php and the fonts folder are kept.
#
#   sudo sh install.sh --agent
#
# installs only the printer agent (the API, protected by a generated access token) on the
# computer the printer is plugged into, for an interface running elsewhere (Docker). Options:
#   --agent           printer agent only: /opt/labeler-agent, port 8631, token
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
PORT=
DIR=
SERVICE=1
AGENT=0

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
    --agent) AGENT=1 ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
  shift
done

if [ "$AGENT" = 1 ]; then
  NAME=labeler-agent; DIR=${DIR:-/opt/labeler-agent}; PORT=${PORT:-8631}
else
  NAME=labeler; DIR=${DIR:-/opt/labeler}; PORT=${PORT:-8080}
fi
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
  for i in 1 2 3; do
    git clone -q https://git.familie-radermacher.ch/linux/ptouch-print.git "$work/ptouch-print" && break
    rm -rf "$work/ptouch-print"
    [ "$i" = 3 ] && die "cannot download ptouch-print; try again later"
    sleep 10
  done
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
  say "Downloading $NAME ($VERSION)"
  if [ "$AGENT" = 1 ]; then pattern='labeler-agent-[0-9][^"]*\.zip'; else pattern='labeler-[0-9][^"]*\.zip'; fi
  url=$(curl -fsSL "$api" | grep -o "\"browser_download_url\": *\"[^\"]*$pattern\"" | head -n 1 | sed 's/.*"\(https[^"]*\)"/\1/')
  [ -n "$url" ] || die "no release package found at $api"
  ZIP=$work/labeler.zip
  curl -fsSL "$url" -o "$ZIP"
fi

say "Installing $NAME to $DIR"
unzip -q "$ZIP" -d "$work/unpacked"
[ -f "$work/unpacked/$NAME/public/api.php" ] || die "$ZIP is not a $NAME package"
if [ -d "$DIR" ]; then
  rm -rf "$DIR.old"
  mv "$DIR" "$DIR.old"
  [ ! -f "$DIR.old/config.php" ] || cp -p "$DIR.old/config.php" "$work/unpacked/$NAME/"
  if [ -d "$DIR.old/fonts" ] && [ -d "$work/unpacked/$NAME/fonts" ]; then
    find "$DIR.old/fonts" -maxdepth 1 -type f ! -name README.md -exec cp -p {} "$work/unpacked/$NAME/fonts/" \;
  fi
  echo "previous version kept in $DIR.old"
fi
mkdir -p "$(dirname "$DIR")"
mv "$work/unpacked/$NAME" "$DIR"
chown -R root:root "$DIR"
chmod -R u=rwX,go=rX "$DIR"

say "Setting up the user labeler"
getent group lp >/dev/null || groupadd --system lp
if ! id labeler >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin --groups lp labeler
else
  usermod -aG lp labeler
fi

if [ "$AGENT" = 1 ]; then
  if [ ! -f "$DIR/config.php" ] || ! grep -q "'token' => '[0-9a-f]" "$DIR/config.php"; then
    token=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
    printf "<?php\n\n// Access token of this printer agent; the interface needs it as printer_token.\nreturn ['token' => '%s'];\n" "$token" > "$DIR/config.php"
  fi
  chown root:labeler "$DIR/config.php"
  chmod 640 "$DIR/config.php"
  token=$(sed -n "s/.*'token' => '\([0-9a-f]*\)'.*/\1/p" "$DIR/config.php")
fi

if [ "$SERVICE" = 1 ]; then
  say "Setting up printer access and the service"
  cp "$DIR/deploy/99-labeler-ptouch.rules" /etc/udev/rules.d/
  udevadm control --reload-rules 2>/dev/null && udevadm trigger --subsystem-match=usb 2>/dev/null || true
  sed -e "s#/opt/$NAME#$DIR#g" -e "s#0.0.0.0:[0-9]*#0.0.0.0:$PORT#" "$DIR/deploy/$NAME.service" > "/etc/systemd/system/$NAME.service"
  systemctl daemon-reload
  systemctl enable --now "$NAME" >/dev/null 2>&1
  systemctl restart "$NAME"
fi
ip=$(hostname -I 2>/dev/null | cut -d' ' -f1)
if [ "$AGENT" = 1 ]; then
  say "Printer agent ready. Give these two settings to the interface (Docker):"
  echo "  LABELER_PRINTER_URL=http://${ip:-<this computer>}:$PORT"
  echo "  LABELER_PRINTER_TOKEN=$token"
elif [ "$SERVICE" = 1 ]; then
  say "Done: open http://${ip:-<this computer>}:$PORT/"
fi
if [ "$SERVICE" = 1 ]; then
  echo "If the printer was already plugged in, unplug it and plug it in again."
else
  echo "Start it with: sudo -u labeler php -S 0.0.0.0:$PORT -t $DIR/public"
fi
