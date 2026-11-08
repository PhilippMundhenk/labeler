#!/bin/sh
# Starts Labeler with PHP's built-in server and says once how it is set up.
set -eu

mkdir -p "${LABELER_FONTS_DIR:-/data/fonts}" 2>/dev/null || true

if [ "${LABELER_DEMO:-0}" = 1 ]; then
  export LABELER_PTOUCH_BIN=/app/tools/ptouch-sim/ptouch-print
  export PTSIM_OUT="${PTSIM_OUT:-/data/demo-printed}"
  echo "labeler: demo mode, printing to a simulated printer (labels are saved in $PTSIM_OUT)"
elif [ -n "${LABELER_PRINTER_URL:-}" ]; then
  echo "labeler: printing through the printer agent at $LABELER_PRINTER_URL"
  [ -n "${LABELER_PRINTER_TOKEN:-}" ] || echo "labeler: warning: LABELER_PRINTER_TOKEN is empty; agents installed with install.sh --agent need it"
elif [ -d /dev/bus/usb ]; then
  echo "labeler: printing on a printer attached to this host over USB"
else
  echo "labeler: warning: no printer configured: set LABELER_PRINTER_URL for a printer agent, pass /dev/bus/usb for a local printer, or LABELER_DEMO=1"
fi
if [ -n "${LABELER_TOKEN:-}" ]; then
  echo "labeler: API access token set: this container is a printer agent, the web page itself cannot print"
fi

exec php -S "0.0.0.0:${PORT:-8080}" -t /app/public
