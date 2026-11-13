#!/bin/sh
# F1-237: only fixed internal DNS and paths; no host, proxy, remote API.
set -eu
HOST="http://sites-web:8080"
check() {
  method="$1"
  path="$2"
  expected="$3"
  actual="$(curl --noproxy '*' --silent --output /dev/null \
    --max-time 5 --write-out '%{http_code}' --request "$method" "$HOST$path" || true)"
  if [ "$actual" != "$expected" ]; then
    echo "FAIL F1-237: unexpected FPM status for route category"
    exit 1
  fi
}
ready=0
attempt=0
while [ "$attempt" -lt 50 ]; do
  if curl --noproxy '*' --silent --output /dev/null --fail --max-time 2 "$HOST/up"; then
    ready=1
    break
  fi
  attempt=$((attempt+1))
  sleep 1
done
if [ "$ready" -ne 1 ]; then
  echo "FAIL F1-237: isolated Nginx/FPM health did not respond"
  exit 1
fi
check GET /up 200
check POST /_lab/sites/v1/create-project 404
check POST /_lab/sites/v3/create-project 404
check GET /_lab/sites/v3/unknown-future 404
check GET /_lab/sites/v1/review 404
check GET '/_lab/sites/v1/seo-copy-review?product=onepage&seo=custom&social=on' 404
check GET /_lab/sites/v1/unknown-future 404
check POST /api/sites/publish 404
check GET /api/integrations/portal 404
echo "PASS F1-237: cached synthetic Laravel routes unavailable under real FPM/Nginx."
echo "PASS F1-237: /up remains 200, no public host port or operational integration."
