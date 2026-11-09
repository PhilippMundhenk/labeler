#!/bin/sh
# USB part of the contract test: runs ptouch-print against the fake libusb (fake-libusb.c) and
# checks the commands it sends to the printer for the options Labeler uses. The options from
# patches/ (--feed, --halfcut, --cut-every, --hires) are checked when this build has them.
#   sh tools/contract/usb.sh <folder with label-76.png>
set -eu
in=${1:?usage: usb.sh <label folder>}
lib=/usr/local/lib/fake-usb
out=$(mktemp -d)
pass() { echo "ok   usb: $*"; }
fail() { echo "FAIL usb: $*"; [ ! -f "$out/log" ] || cat "$out/log"; exit 1; }

# runs ptouch-print on the fake printer; $out/cmds gets the commands, raster lines left out
usb() {
  rm -f "$out/bytes"
  FAKE_USB_OUT="$out/bytes" LD_LIBRARY_PATH=$lib ptouch-print "$@" >"$out/log" 2>&1 || return $?
  grep -v '^47' "$out/bytes" | sed 's/^0\{200\}//' >"$out/cmds" || true
  grep -c '^47' "$out/bytes" >"$out/rasters" || true
}
has() { grep -qx "$1" "$out/cmds"; }
count() { grep -cx "$1" "$out/cmds" || true; }
last() { tail -n 1 "$out/cmds"; }

if ! LD_LIBRARY_PATH=$lib ptouch-print --version >/dev/null 2>&1; then
  echo "skip usb: this ptouch-print needs libusb functions the fake does not have"
  exit 0
fi
label=$in/label-76.png
width=$(php -r 'echo getimagesize($argv[1])[0];' "$label")

usb --info || fail "--info on the fake printer"
grep -q "media width = 12 mm" "$out/log" || fail "--info does not show the fake 12 mm tape"
pass "--info reads the status"

usb --image "$label" || fail "--image"
has 1b40 || fail "no initialize command (ESC @)"
has 1b6953 || fail "no status request (ESC i S)"
[ "$(cat "$out/rasters")" = "$width" ] || fail "$(cat "$out/rasters") raster lines for a $width px long label"
[ "$(last)" = 1a ] || fail "the label does not end with print and feed (1A)"
pass "a label is sent as $width raster lines and ends with print and feed"

usb --chain --image "$label" || fail "--chain"
[ "$(last)" = 0c ] || fail "--chain does not end with print without feed (0C)"
pass "--chain ends with 0C"

usb --copies 3 --image "$label" || fail "--copies"
[ "$(cat "$out/rasters")" = "$((3 * width))" ] || fail "--copies 3 did not send three labels"
[ "$(count 0c)" = 2 ] && [ "$(last)" = 1a ] || fail "--copies 3 does not end the first two with 0C and the last with 1A"
pass "--copies 3 sends three labels"

if ptouch-print --help 2>&1 | grep -q -- '--precut'; then
  usb --precut --image "$label" || fail "--precut"
  has 1b694d40 || fail "--precut does not send auto cut (ESC i M 40)"
  pass "--precut sends ESC i M 40 (auto cut)"
fi

if ! ptouch-print --help 2>&1 | grep -q -- '--halfcut'; then
  echo "usb checks passed (no extensions in this build)"
  exit 0
fi

usb --feed 4 --image "$label" || fail "--feed"
has 1b69640400 || fail "--feed 4 does not send ESC i d 04 00"
usb --image "$label"
! grep -q '^1b6964' "$out/cmds" || fail "without --feed a margin command is sent"
pass "--feed 4 sends ESC i d 04 00, nothing without it"

usb --halfcut --image "$label" || fail "--halfcut"
has 1b694b0c || fail "--halfcut does not send ESC i K 0C (half cut, no chain)"
usb --halfcut --chain --image "$label"
has 1b694b04 || fail "--halfcut --chain does not send ESC i K 04"
usb --image "$label"
! grep -q '^1b694b' "$out/cmds" || fail "without the options ESC i K is sent"
pass "--halfcut sends ESC i K with the half cut and chain bits"

usb --cut-every 2 --copies 3 --image "$label" || fail "--cut-every"
[ "$(count 1b694102)" = 3 ] && [ "$(count 1b694d40)" = 3 ] || fail "--cut-every 2 does not send auto cut and ESC i A 02 per label"
pass "--cut-every 2 sends auto cut and ESC i A 02"

usb --hires --image "$label" || fail "--hires"
has 1b694b48 || fail "--hires does not send ESC i K 48"
[ "$(cat "$out/rasters")" = "$width" ] || fail "--hires changed the number of raster lines"
pass "--hires sends ESC i K 48 and the image as it is"

for bad in "--feed x" "--feed 1001" "--cut-every 0" "--cut-every 100"; do
  # shellcheck disable=SC2086
  if usb $bad --image "$label"; then fail "$bad was accepted"; fi
done
pass "out-of-range values are rejected"

FAKE_USB_PID=2085 FAKE_USB_TAPE=24 usb --hires --halfcut --image "$label" || fail "PT-P900 hires"
has 1b694b4c || fail "PT-P900: --hires --halfcut does not send ESC i K 4C"
grep -q '^1b697a' "$out/cmds" || fail "PT-P900: no print information command"
pass "PT-P900: print information and ESC i K 4C"

FAKE_USB_PID=20e0 usb --chain --feed 2 --image "$label" || fail "PT-D460BT"
has 1b694b0000 || fail "PT-D460BT: the chain command changed"
[ "$(tail -n 2 "$out/cmds" | head -n 1)" = 1b69640200 ] || fail "PT-D460BT: --feed is not sent after its own margin"
pass "PT-D460BT: chain command unchanged, --feed after its magic margin"
echo "usb checks passed (with extensions)"
