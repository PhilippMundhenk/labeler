#!/bin/sh
# Runs a command in the development container (PHP + pcov, Composer, Node, Playwright).
#   tools/dev.sh composer install
#   tools/dev.sh composer coverage
#   tools/dev.sh npm test
set -e
cd "$(dirname "$0")/.."
docker build -q -t labeler-dev tools/dev >/dev/null
exec env MSYS_NO_PATHCONV=1 docker run --rm -t ${DEV_PORT:+-p 127.0.0.1:$DEV_PORT:8080} -v "$PWD:/work" -w /work \
  -e HOME=/tmp -e npm_config_cache=/tmp/npm -e COMPOSER_HOME=/tmp/composer \
  labeler-dev "$@"
