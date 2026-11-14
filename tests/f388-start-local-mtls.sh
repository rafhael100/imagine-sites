#!/usr/bin/env bash
# F1-388: ephemeral TLS listener. Nothing binds outside localhost.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${SITES_F388_MTLS_LAB:-}" = 1
test "${F388_FIXTURE_DIR:-}" = /tmp/f388-fixture
python3 tests/fixtures/f388-fake-portal-mtls-server.py \
  --ca "$F388_FIXTURE_DIR/ca.crt" \
  --server-cert "$F388_FIXTURE_DIR/server.crt" \
  --server-key "$F388_FIXTURE_DIR/server.key" \
  --fixture-dir "$F388_FIXTURE_DIR" \
  > /tmp/f388-portal-server.log 2>&1 &
echo "$!" > /tmp/f388-portal-server.pid
ready=0
for i in $(seq 1 40); do
  status="$(curl --noproxy '*' --silent --max-time 2 \
    --cacert "$F388_FIXTURE_DIR/ca.crt" \
    --cert "$F388_FIXTURE_DIR/client.crt" \
    --key "$F388_FIXTURE_DIR/client.key" \
    -o /dev/null -w '%{http_code}' \
    https://localhost:18443/internal/sites/v1/entitlements/resolve || true)"
  if [ "$status" = 501 ]; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
echo 'F1-388 PASS: localhost-only HTTPS listener requires valid mTLS identity.'
