#!/bin/sh
# Fetches the sources of ptouch-print at a version into a folder, for the container images and
# the contract test:
#   sh tools/ptouch-fetch.sh v1.9 /src/ptouch-print
#   sh tools/ptouch-fetch.sh --check-pins          # the pinned commits are upstream's (weekly CI)
# Upstream is a small self-hosted server that is sometimes unreachable for minutes. Then a release
# tag is taken from the GitHub fork instead, and only if it is the commit pinned below for that
# tag. Other refs (master) only come from upstream.
set -eu
# PTOUCH_UPSTREAM and PTOUCH_FETCH_PAUSE only exist so CI can try the way over the fork
UPSTREAM=${PTOUCH_UPSTREAM:-https://git.familie-radermacher.ch/linux/ptouch-print.git}
PAUSE=${PTOUCH_FETCH_PAUSE:-15}
MIRROR=https://github.com/PhilippMundhenk/ptouch-print.git

# release tag => commit (the tag's target upstream)
pinned() {
  case "$1" in
    v1.5) echo e3c0073466ed99dbde9bbbcceea1c54f64967fc8 ;;
    v1.6) echo aa5392bc135161252d06c48745c0d53c281d69f3 ;;
    v1.7) echo e730c3b480e5959e655f7622462f77ff39e5b6fc ;;
    v1.8) echo 76c419f952a65384e759646d313c52a7b398e697 ;;
    v1.9) echo 02be73c75be483bd2bf947a048d447200e3229af ;;
    *) echo ;;
  esac
}

if [ "${1:-}" = --check-pins ]; then
  failed=0
  for tag in v1.5 v1.6 v1.7 v1.8 v1.9; do
    have=$(git ls-remote "$UPSTREAM" "refs/tags/$tag^{}" | cut -f1)
    if [ "$have" = "$(pinned "$tag")" ]; then echo "ok   $tag $have"; else echo "FAIL $tag upstream ${have:-missing}, pinned $(pinned "$tag")"; failed=1; fi
  done
  exit "$failed"
fi

ref=${1:?usage: ptouch-fetch.sh REF DEST}
dest=${2:?usage: ptouch-fetch.sh REF DEST}
want=$(pinned "$ref")

# tries: pauses growing by $3 seconds; a stalled transfer (under 1 kB/s for 30 s) is retried
clone() {
  i=1
  while [ "$i" -le "$2" ]; do
    git -c http.lowSpeedLimit=1000 -c http.lowSpeedTime=30 clone -q "$1" "$dest" && return 0
    rm -rf "$dest"
    [ "$i" = "$2" ] || sleep $((i * $3))
    i=$((i + 1))
  done
  return 1
}

if ! clone "$UPSTREAM" 3 "$PAUSE"; then
  [ -n "$want" ] || { echo "ptouch-fetch: $UPSTREAM cannot be reached, and $ref has no pinned commit for the mirror" >&2; exit 1; }
  echo "ptouch-fetch: $UPSTREAM cannot be reached; taking $ref from $MIRROR" >&2
  clone "$MIRROR" 3 "$PAUSE" || { echo "ptouch-fetch: $MIRROR cannot be reached either" >&2; exit 1; }
fi
git -C "$dest" -c advice.detachedHead=false checkout -q "$ref"
if [ -n "$want" ] && [ "$(git -C "$dest" rev-parse HEAD)" != "$want" ]; then
  echo "ptouch-fetch: $ref is $(git -C "$dest" rev-parse HEAD), expected $want" >&2
  exit 1
fi
echo "ptouch-print $ref: $(git -C "$dest" rev-parse --short HEAD)"
