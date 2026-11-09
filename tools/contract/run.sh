#!/bin/sh
# Contract test against the real ptouch-print (see Dockerfile in this folder).
# Checks that the command lines and PNGs Labeler produces are accepted by the installed
# version, and that Labeler's parser understands its messages.
set -eu
out=$(mktemp -d)
pass() { echo "ok   $*"; }

ptouch-print --version >"$out/version.txt" 2>&1 || true
php tools/contract/check.php version "$(cat "$out/version.txt")"

code=0
ptouch-print --info >"$out/info.txt" 2>&1 || code=$?
[ "$code" -ne 0 ] || { echo "FAIL: --info without printer exited 0"; exit 1; }
php tools/contract/check.php info "$code" "$(cat "$out/info.txt")"

node tools/contract/make-labels.mjs "$out/in" >/dev/null
for h in 32 76 128; do
  ptouch-print --force-tape-width "$h" --writepng "$out/out-$h.png" --image "$out/in/label-$h.png" >"$out/log-$h.txt" 2>&1 \
    || { cat "$out/log-$h.txt"; echo "FAIL: --image label-$h.png"; exit 1; }
  php tools/contract/check.php same "$out/in/label-$h.png" "$out/out-$h.png"
done
pass "label PNGs are read back unchanged"

ptouch-print --force-tape-width 76 --writepng "$out/blank.png" --image "$out/in/blank-76.png" >/dev/null 2>&1
pass "a blank label is accepted"

# the options Labeler passes when printing must be known to this version
ptouch-print --force-tape-width 76 --writepng "$out/opt.png" --copies 2 --chain --image "$out/in/label-76.png" >/dev/null 2>&1 \
  || { echo "FAIL: --copies/--chain"; exit 1; }
pass "--copies and --chain are accepted"
if ptouch-print --help 2>&1 | grep -q -- '--precut'; then
  ptouch-print --force-tape-width 76 --writepng "$out/opt.png" --precut --image "$out/in/label-76.png" >/dev/null 2>&1 \
    || { echo "FAIL: --precut"; exit 1; }
  pass "--precut is accepted"
fi

# documents issue #1: the old multi-line --text form is rejected since 1.7
if ptouch-print --force-tape-width 76 --writepng "$out/t.png" --text "line 1" "line 2" >/dev/null 2>&1; then
  pass "--text with two arguments still works on this version"
else
  pass "--text with two arguments is rejected (reason for #1)"
fi
sh tools/contract/usb.sh "$out/in"
echo "contract test passed: $(cat "$out/version.txt")"
