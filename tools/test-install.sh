#!/bin/sh
# Tests deploy/install.sh in a clean Debian container: fresh install with a real ptouch-print
# build, then an update that must keep config.php and the fonts, then the API as user labeler.
#   sh tools/test-install.sh [debian:bookworm]
set -eu
cd "$(dirname "$0")/.."
image=${1:-debian:bookworm}
MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD:/src:ro" "$image" sh -euc '
  apt-get update -qq && apt-get install -y -qq zip tar >/dev/null
  mkdir /tmp/repo
  tar -C /src --exclude=./node_modules --exclude=./vendor --exclude=./test-results --exclude=./build --exclude=./coverage -cf - . | tar -C /tmp/repo -xf -
  cd /tmp/repo
  sh tools/package.sh 0.0.0-test >/dev/null

  sh deploy/install.sh --zip build/labeler-0.0.0-test.zip --no-service
  test -x /usr/local/bin/ptouch-print
  id labeler | grep -q "(lp)"
  test -f /opt/labeler/public/index.html

  echo "<?php return [\"max_copies\" => 3];" > /opt/labeler/config.php
  echo font > /opt/labeler/fonts/Test.ttf
  sh deploy/install.sh --zip build/labeler-0.0.0-test.zip --no-service --skip-ptouch
  test -f /opt/labeler/config.php
  test -f /opt/labeler/fonts/Test.ttf
  test -d /opt/labeler.old
  if sh deploy/install.sh --port abc 2>/dev/null; then echo "FAIL: bad port accepted"; exit 1; fi

  su -s /bin/sh labeler -c "php -S 127.0.0.1:8080 -t /opt/labeler/public" >/dev/null 2>&1 &
  for i in $(seq 1 30); do curl -fsS http://127.0.0.1:8080/api.php?action=health >/dev/null 2>&1 && break; sleep 0.3; done
  status=$(curl -fsS http://127.0.0.1:8080/api.php?action=status)
  echo "$status" | grep -q "\"max_copies\":3"
  echo "$status" | grep -q "No printer found"
  curl -fsS http://127.0.0.1:8080/api.php?action=fonts | grep -q "Test.ttf"

  # printer agent: only the API, token generated once and kept on update
  sh deploy/install.sh --agent --zip build/labeler-agent-0.0.0-test.zip --no-service --skip-ptouch > /tmp/agent.txt
  token=$(sed -n "s/.*LABELER_PRINTER_TOKEN=//p" /tmp/agent.txt)
  [ ${#token} -eq 48 ]
  test ! -e /opt/labeler-agent/public/index.html
  test "$(stat -c %a /opt/labeler-agent/config.php)" = 640
  sh deploy/install.sh --agent --zip build/labeler-agent-0.0.0-test.zip --no-service --skip-ptouch | grep -q "TOKEN=$token"
  su -s /bin/sh labeler -c "php -S 127.0.0.1:8631 -t /opt/labeler-agent/public" >/dev/null 2>&1 &
  for i in $(seq 1 30); do curl -fsS http://127.0.0.1:8631/api.php?action=health >/dev/null 2>&1 && break; sleep 0.3; done
  test "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8631/api.php?action=status)" = 401
  curl -fsS -H "Authorization: Bearer $token" http://127.0.0.1:8631/api.php?action=status | grep -q "No printer found"
  echo "install test passed"
'
