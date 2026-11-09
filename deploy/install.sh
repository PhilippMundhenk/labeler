#!/bin/sh
# Installs or updates Labeler on Raspberry Pi OS / Debian / Ubuntu. Everything it needs comes
# from GitHub, so the script alone is enough:
#
#   curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh
#   curl -fsSL https://raw.githubusercontent.com/PhilippMundhenk/labeler/main/deploy/install.sh | sudo sh -s -- --agent
#
# Run it again to update; config.php and the fonts folder are kept. Run from a clone of the
# repository (sudo sh deploy/install.sh) it installs that clone instead of downloading.
usage() {
  cat <<'EOF'
usage: install.sh [options]       (piped: curl ... | sudo sh -s -- [options])

Installs Labeler to /opt/labeler with a systemd service on port 8080, or with --agent only the
printer agent (the API, protected by a generated access token) for an interface that runs
elsewhere, for example in Docker.

  --agent             printer agent only: /opt/labeler-agent, port 8631, token
  --version X.Y.Z     install this release instead of the newest
  --branch NAME       install the current state of a branch (for testing; default: the newest
                      release, or main while there is no release yet)
  --zip FILE          install from a downloaded labeler-X.Y.Z.zip or labeler-agent-X.Y.Z.zip
  --ptouch-ref REF    ptouch-print version (default v1.9)
  --ptouch-patches    ptouch-print with Labeler's patches (half cut, feed, cut every, hires)
  --build-ptouch      build ptouch-print from the sources instead of downloading a ready one
  --ptouch-repo URL   build ptouch-print from this git repository (implies --build-ptouch)
  --skip-ptouch       leave ptouch-print alone (it is already installed)
  --port N            port of the web interface (default 8080, agent 8631)
  --dir DIR           install folder (default /opt/labeler or /opt/labeler-agent)
  --no-service        do not set up systemd and udev (containers, own web server)
  --uninstall         remove Labeler (with --agent: the agent): service, program files and,
                      when neither is left, the udev rule and the user labeler; keeps
                      config.php, fonts and the print log
  --purge             with --uninstall: also remove config.php, fonts, the print log and
                      /usr/local/bin/ptouch-print
EOF
}
set -eu

REPO=PhilippMundhenk/labeler
VERSION=latest
BRANCH=
ZIP=
PTOUCH_REF=v1.9
PTOUCH_PATCHES=0
PTOUCH_REPO=https://git.familie-radermacher.ch/linux/ptouch-print.git
PTOUCH=auto          # auto: keep a matching one, else download, else build | build | skip
PORT=
DIR=
SERVICE=1
AGENT=0
UNINSTALL=0
PURGE=0
# a clone of the repository around the script: install that instead of downloading
TREE=
case "$0" in
  *install.sh)  # run as a file, not piped into sh
    SELF_DIR=$(cd "$(dirname "$0")" && pwd)
    if [ -f "$SELF_DIR/../public/api.php" ] && [ -f "$SELF_DIR/../src/Api.php" ]; then
      TREE=$(cd "$SELF_DIR/.." && pwd)
    fi ;;
esac

say() { printf '\033[1m==> %s\033[0m\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --version) VERSION=${2:?}; TREE=; shift ;;
    --branch) BRANCH=${2:?}; TREE=; shift ;;
    --zip) ZIP=${2:?}; shift ;;
    --ptouch-ref) PTOUCH_REF=${2:?}; shift ;;
    --ptouch-patches) PTOUCH_PATCHES=1 ;;
    --build-ptouch) PTOUCH=build ;;
    --ptouch-repo) PTOUCH_REPO=${2:?}; PTOUCH=build; shift ;;
    --skip-ptouch) PTOUCH=skip ;;
    --port) PORT=${2:?}; shift ;;
    --dir) DIR=${2:?}; shift ;;
    --no-service) SERVICE=0 ;;
    --agent) AGENT=1 ;;
    --uninstall) UNINSTALL=1 ;;
    --purge) PURGE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
  shift
done

if [ "$AGENT" = 1 ]; then
  NAME=labeler-agent; DIR=${DIR:-/opt/labeler-agent}; PORT=${PORT:-8631}
else
  NAME=labeler; DIR=${DIR:-/opt/labeler}; PORT=${PORT:-8080}
fi
[ "$(id -u)" = 0 ] || die "run as root (sudo)"
case "$PORT" in *[!0-9]*|'') die "--port needs a number" ;; esac
[ -z "$ZIP" ] || [ -f "$ZIP" ] || die "$ZIP not found"
[ "$PURGE" = 0 ] || [ "$UNINSTALL" = 1 ] || die "--purge only goes with --uninstall"

if [ "$UNINSTALL" = 1 ]; then
  say "Removing $NAME from $DIR"
  if [ -f "/etc/systemd/system/$NAME.service" ]; then
    systemctl disable --now "$NAME" >/dev/null 2>&1 || true
    rm -f "/etc/systemd/system/$NAME.service"
    systemctl daemon-reload 2>/dev/null || true
  fi
  rm -rf "$DIR.old"
  if [ "$PURGE" = 1 ]; then
    rm -rf "$DIR" "/var/lib/$NAME"
  elif [ -d "$DIR" ]; then
    # keep what was set up by hand: config.php (the agent's token) and the fonts
    find "$DIR" -mindepth 1 -maxdepth 1 ! -name config.php ! -name fonts -exec rm -rf {} +
    [ -n "$(ls -A "$DIR")" ] || rmdir "$DIR"
  fi
  if [ "$AGENT" = 1 ]; then other=labeler; else other=labeler-agent; fi
  if [ -f "/etc/systemd/system/$other.service" ] || [ -f "/opt/$other/public/api.php" ]; then
    echo "$other is still installed: the udev rule, the user labeler and ptouch-print stay"
  else
    rm -f /etc/udev/rules.d/99-labeler-ptouch.rules
    udevadm control --reload-rules 2>/dev/null || true
    ! id labeler >/dev/null 2>&1 || userdel labeler 2>/dev/null || true
    [ "$PURGE" = 0 ] || rm -f /usr/local/bin/ptouch-print
  fi
  say "$NAME removed"
  if [ "$PURGE" = 0 ]; then
    [ ! -d "$DIR" ] || echo "kept: $DIR (config.php, fonts)"
    [ ! -d "/var/lib/$NAME" ] || echo "kept: /var/lib/$NAME (print log, saved labels)"
    echo "kept: /usr/local/bin/ptouch-print. Remove everything with --uninstall --purge."
  fi
  echo "PHP stays installed; remove it with: sudo apt remove php-cli"
  exit 0
fi

command -v apt-get >/dev/null || die "this script needs apt (Raspberry Pi OS, Debian, Ubuntu)"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Installs the packages that are missing; apt is slow on a Pi Zero, so it only runs when needed.
updated=0
need() {
  missing=
  for p in "$@"; do
    dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q "ok installed" || missing="$missing $p"
  done
  [ -n "$missing" ] || return 0
  say "Installing packages:$missing"
  echo "(this can take a few minutes on a Raspberry Pi Zero)"
  if [ "$updated" = 0 ]; then apt-get update -q; updated=1; fi
  # shellcheck disable=SC2086
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends $missing
}

base="php-cli curl ca-certificates"
[ -z "$ZIP" ] || base="$base unzip"
# shellcheck disable=SC2086
need $base

# --- ptouch-print -------------------------------------------------------------------------
suffix=; [ "$PTOUCH_PATCHES" = 0 ] || suffix=-labeler
ptouch_ok() {
  command -v ptouch-print >/dev/null || return 1
  ptouch-print --version 2>&1 | grep -q "${PTOUCH_REF}\." || return 1
  [ "$PTOUCH_PATCHES" = 0 ] || ptouch-print --help 2>&1 | grep -q -- '--halfcut'
}
# a ready-made static ptouch-print (no libraries needed) from the release "ptouch-print-REF"
ptouch_download() {
  case "$(dpkg --print-architecture)" in
    armhf) arch=armv6 ;;      # Raspberry Pi OS 32 bit; ARMv6 also runs on every newer Pi
    arm64) arch=arm64 ;;
    amd64) arch=amd64 ;;
    *) return 1 ;;
  esac
  file=ptouch-print-$PTOUCH_REF$suffix-$arch
  base_url=https://github.com/$REPO/releases/download/ptouch-print-$PTOUCH_REF
  say "Downloading ptouch-print $PTOUCH_REF$suffix ($arch)"
  curl -fsSL "$base_url/$file" -o "$work/$file" 2>/dev/null || { echo "no ready-made ptouch-print for $arch"; return 1; }
  curl -fsSL "$base_url/SHA256SUMS" -o "$work/SHA256SUMS" || return 1
  (cd "$work" && grep " $file\$" SHA256SUMS | sha256sum -c - >/dev/null) || die "checksum of $file does not match"
  install -m 755 "$work/$file" /usr/local/bin/ptouch-print
}
ptouch_build() {
  need git cmake gcc make pkg-config gettext libgd-dev libusb-1.0-0-dev
  say "Building ptouch-print $PTOUCH_REF$suffix from the sources"
  for i in 1 2 3; do
    git clone -q "$PTOUCH_REPO" "$work/ptouch-print" && break
    rm -rf "$work/ptouch-print"
    [ "$i" = 3 ] && die "cannot download ptouch-print; try again later"
    sleep 10
  done
  git -C "$work/ptouch-print" -c advice.detachedHead=false checkout -q "$PTOUCH_REF"
  if [ "$PTOUCH_PATCHES" = 1 ]; then
    patches=${TREE:+$TREE/tools/ptouch-patches}
    if [ -z "$patches" ]; then
      patches=$work/patches; mkdir -p "$patches"
      curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/${BRANCH:-main}" \
        | tar -xz -C "$patches" --strip-components=3 --wildcards '*/tools/ptouch-patches/*.patch'
    fi
    git -C "$work/ptouch-print" -c user.name=install -c user.email=install@localhost am -q "$patches"/*.patch
  fi
  cmake -S "$work/ptouch-print" -B "$work/ptouch-print/build" >/dev/null
  cmake --build "$work/ptouch-print/build" -j "$(nproc)" >/dev/null
  install -m 755 "$work/ptouch-print/build/ptouch-print" /usr/local/bin/ptouch-print
}
case "$PTOUCH" in
  skip) ;;
  build) ptouch_build ;;
  auto)
    if ptouch_ok; then echo "ptouch-print $PTOUCH_REF$suffix is already installed"
    else ptouch_download || ptouch_build
    fi ;;
esac
command -v ptouch-print >/dev/null || die "ptouch-print is not installed; run without --skip-ptouch"
ptouch-print --version 2>&1 | head -n 1

# --- Labeler --------------------------------------------------------------------------------
# Copies what the device needs from a source tree ($1) into $work/unpacked/$NAME: the same
# files tools/package.sh puts into the release zips.
stage() {
  src=$1; dst=$work/unpacked/$NAME
  mkdir -p "$dst/deploy"
  cp -r "$src/src" "$dst/"
  cp "$src/config.example.php" "$src/LICENSE" "$src/README.md" "$src/CHANGELOG.md" "$dst/"
  if [ "$AGENT" = 1 ]; then
    mkdir -p "$dst/public"
    cp "$src/public/api.php" "$dst/public/"
    cp "$src/deploy/install.sh" "$src/deploy/labeler-agent.service" "$src/deploy/99-labeler-ptouch.rules" "$dst/deploy/"
  else
    cp -r "$src/public" "$dst/"
    cp -r "$src/deploy/." "$dst/deploy/"
    mkdir -p "$dst/fonts"
    cp "$src/fonts/README.md" "$dst/fonts/"
  fi
}

if [ -n "$ZIP" ]; then
  unzip -q "$ZIP" -d "$work/unpacked"
elif [ -n "$TREE" ]; then
  say "Taking $NAME from the folder $TREE"
  stage "$TREE"
else
  url=
  if [ -z "$BRANCH" ]; then
    if [ "$VERSION" = latest ]; then
      api=https://api.github.com/repos/$REPO/releases/latest
    else
      api=https://api.github.com/repos/$REPO/releases/tags/v${VERSION#v}
    fi
    if [ "$AGENT" = 1 ]; then pattern='labeler-agent-[0-9][^"]*[.]zip'; else pattern='labeler-[0-9][^"]*[.]zip'; fi
    url=$(curl -fsSL "$api" 2>/dev/null | grep -o "\"browser_download_url\": *\"[^\"]*$pattern\"" | head -n 1 | sed 's/.*"\(https[^"]*\)"/\1/' || true)
    if [ -z "$url" ]; then
      [ "$VERSION" = latest ] || die "no release package found at $api"
      echo "There is no release yet: installing the current state of the branch main."
      BRANCH=main
    fi
  fi
  if [ -n "$url" ]; then
    say "Downloading $NAME ($VERSION)"
    need unzip
    ZIP=$work/labeler.zip
    curl -fsSL "$url" -o "$ZIP"
    unzip -q "$ZIP" -d "$work/unpacked"
  else
    say "Downloading $NAME from the branch $BRANCH"
    mkdir -p "$work/tree"
    curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" | tar -xz -C "$work/tree" --strip-components=1 \
      || die "cannot download the branch $BRANCH"
    stage "$work/tree"
  fi
fi

say "Installing $NAME to $DIR"
[ -f "$work/unpacked/$NAME/public/api.php" ] || die "${ZIP:-the download} is not a $NAME package"
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
  token=$(sed -n "s/.*'token' => '\([0-9a-f]*\)'.*/\1/p" "$DIR/config.php")
fi

# config.php holds the password and tokens: readable only by root and the service
if [ -f "$DIR/config.php" ]; then
  chown root:labeler "$DIR/config.php"
  chmod 640 "$DIR/config.php"
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
