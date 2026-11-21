#!/bin/sh
# Contract test for the ql driver (see Dockerfile in this folder): Labeler's raster jobs must
# be byte for byte what brother_ql writes for the same label, model and media.
set -eu
out=$(mktemp -d)
php tools/ql-contract/make.php "$out"
python3 tools/ql-contract/compare.py "$out"
