#!/bin/sh
# Builds build/labeler-<version>.zip (interface and API, everything the device needs) and
# build/labeler-agent-<version>.zip (only the API, for the computer the printer hangs on).
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

agent=build/stage/labeler-agent
mkdir -p "$agent/public" "$agent/deploy"
cp -r "$stage/src" "$agent/"
cp public/api.php "$agent/public/"
cp deploy/install.sh deploy/labeler-agent.service deploy/99-labeler-ptouch.rules "$agent/deploy/"
cp config.example.php LICENSE README.md CHANGELOG.md "$agent/"
(cd build/stage && zip -qr "../labeler-agent-$version.zip" labeler-agent)
rm -rf build/stage
echo "build/labeler-$version.zip"
echo "build/labeler-agent-$version.zip"
