#!/bin/sh
# Labeler with the simulated printer on port 8080, for trying out the UI.
#   DEV_PORT=8080 tools/dev.sh sh tools/demo.sh       then open http://localhost:8080/
# Printed labels are written to test-results/demo/printed/. Change the printer with
#   PTSIM_VERSION=1.5 PTSIM_TAPE_MM=24 ... (see tools/ptouch-sim/README.md)
cd "$(dirname "$0")/.."
mkdir -p test-results/demo
export LABELER_PTOUCH_BIN="$PWD/tools/ptouch-sim/ptouch-print"
export LABELER_DATA_DIR="$PWD/test-results/demo/data"
export PTSIM_OUT="${PTSIM_OUT:-$PWD/test-results/demo/printed}"
echo "Labeler demo on http://localhost:8080/ (simulated printer)"
exec php -S 0.0.0.0:8080 -t public
