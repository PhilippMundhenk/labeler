#!/bin/sh
# Container smoke test: the image as printer agent (simulated printer, token) and as interface
# talking to it over a Docker network; a print goes through both; non-root; healthcheck.
#   sh tools/container-smoke.sh [image]
set -eu
cd "$(dirname "$0")/.."
image=${1:-labeler:smoke}
[ $# -gt 0 ] || docker build -q -t "$image" . >/dev/null
net=labeler-smoke-$$
token=smoke-test-token
cleanup() { docker rm -f "$net-agent" "$net-ui" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; }
trap cleanup EXIT
fail() { echo "FAIL: $*"; docker logs "$net-agent" 2>&1 | tail -5; docker logs "$net-ui" 2>&1 | tail -5; exit 1; }

docker network create "$net" >/dev/null
docker run -d --name "$net-agent" --network "$net" -e LABELER_DEMO=1 -e LABELER_TOKEN=$token -e PORT=8631 -e PTSIM_TAPE_MM=12 "$image" >/dev/null
docker run -d --name "$net-ui" --network "$net" -p 127.0.0.1::8080 -e LABELER_PRINTER_URL=http://$net-agent:8631 -e LABELER_PRINTER_TOKEN=$token "$image" >/dev/null
port=$(docker port "$net-ui" 8080 | head -n 1 | sed 's/.*://')
url=http://127.0.0.1:$port

for i in $(seq 1 50); do curl -fsS "$url/api.php?action=health" >/dev/null 2>&1 && break; sleep 0.2; done
curl -fsS "$url/" | grep -q '<title>Labeler</title>' || fail "page"
curl -fsS "$url/api.php?action=status" | grep -q '"ready":true' || fail "status through the agent"
curl -fsS "$url/api.php?action=status" | grep -q '"reachable":true' || fail "agent info"

png=$(docker run --rm --entrypoint php "$image" -r 'require "/app/tools/ptouch-sim/Png.php"; echo base64_encode(Labeler\Sim\Png::blank(40, 76));')
code=$(curl -s -o /tmp/print.json -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"png\":\"$png\",\"copies\":2}" "$url/api.php?action=print")
[ "$code" = 200 ] || { cat /tmp/print.json; fail "print returned $code"; }
[ "$(docker exec "$net-agent" sh -c 'ls /data/demo-printed/*.json | wc -l')" = 2 ] || fail "agent did not print 2 labels"

agent_status=$(docker exec "$net-agent" php -r 'echo @file_get_contents("http://127.0.0.1:8631/api.php?action=status") === false ? "denied" : "open";')
[ "$agent_status" = denied ] || fail "agent answered without token"
[ "$(docker exec "$net-ui" id -u)" = 1000 ] || fail "not running as uid 1000"
docker logs "$net-ui" 2>&1 | grep -q "printer agent at" || fail "start message"
for i in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$net-ui")" = healthy ] && break
  sleep 1
done
[ "$(docker inspect -f '{{.State.Health.Status}}' "$net-ui")" = healthy ] || fail "healthcheck"
echo "container smoke test passed"
