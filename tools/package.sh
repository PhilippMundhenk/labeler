#!/bin/sh
# Builds build/labeler-<version>.zip: everything the device needs, nothing else.
#   sh tools/package.sh 1.0.0
set -eu
version=${1:?usage: tools/package.sh <version>}
cd "$(dirname "$0")/.."
stage=build/stage/labeler
rm -rf build/stage "build/labeler-$version.zip"
mkdir -p "$stage/fonts"
cp -r public src deploy "$stage/"
cp config.example.php LICENSE README.md CHANGELOG.md "$stage/"
cp fonts/README.md "$stage/fonts/"
sed -i "s/VERSION = '[^']*'/VERSION = '$version'/" "$stage/src/Config.php"
(cd build/stage && zip -qr "../labeler-$version.zip" labeler)
rm -rf build/stage
echo "build/labeler-$version.zip"
