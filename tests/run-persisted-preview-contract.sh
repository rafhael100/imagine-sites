#!/usr/bin/env bash
set -euo pipefail
# Local binaries only; no downloads, TCP listener or existing database.
: "${PG_BIN:?Set PG_BIN to PostgreSQL 18 bin directory}"
: "${PHP_BIN:?Set PHP_BIN to PHP 8.3+ binary}"
repo=$(cd "$(dirname "$0")/.." && pwd)
umask 077
lab=$(mktemp -d /tmp/sites-preview-contract-XXXXXXXX)
mkdir "$lab/socket"
export PREVIEW_CONTRACT_PGDATA="$lab/data" PREVIEW_CONTRACT_SOCKET="$lab/socket"
export PREVIEW_CONTRACT_LOGIN="$(id -un)" SITES_LOCAL_PREVIEW_CONTRACT=1 APP_ENV=testing
unset DB_URL DB_PASSWORD SITES_DB_PASSWORD_FILE
"$PG_BIN/initdb" -D "$lab/data" --auth-local=trust --auth-host=reject --no-locale -E UTF8 > "$lab/initdb.log"
export PREVIEW_CONTRACT_CLUSTER
PREVIEW_CONTRACT_CLUSTER=$(LC_ALL=C "$PG_BIN/pg_controldata" "$lab/data" | awk '/Database system identifier:/ {print $NF}')
[[ "$PREVIEW_CONTRACT_CLUSTER" =~ ^[0-9]+$ ]]
cleanup() {
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    "$PG_BIN/pg_ctl" -D "$lab/data" -m fast -w stop > "$lab/stop.log" 2>&1
  fi
  echo "Disposable cluster inactive; logs: $lab"
}
trap cleanup EXIT
"$PG_BIN/pg_ctl" -D "$lab/data" -l "$lab/postgres.log" -o "-k $lab/socket -p 55439 -c listen_addresses=''" -w start
# A mismatched expected identity must fail, then the correct read-only check must
# still observe an absent namespace, proving the failure occurred before DDL.
wrong_cluster=1
[[ "$PREVIEW_CONTRACT_CLUSTER" != "$wrong_cluster" ]] || wrong_cluster=2
if PREVIEW_CONTRACT_CLUSTER="$wrong_cluster" "$PHP_BIN" "$repo/tests/persisted-preview-evidence-contract.php" > "$lab/denied.log" 2>&1; then
  echo 'Incorrect cluster identity was accepted' >&2; exit 1
fi
grep -q 'Disposable SQL identity mismatch' "$lab/denied.log"
PREVIEW_CONTRACT_PREFLIGHT_ONLY=1 "$PHP_BIN" "$repo/tests/persisted-preview-evidence-contract.php" | tee "$lab/preflight.log"
grep -q '^PREFLIGHT OK:' "$lab/preflight.log"
"$PHP_BIN" "$repo/tests/persisted-preview-evidence-contract.php" | tee "$lab/contract.log"
grep -q '^OK: 22 persisted-preview/' "$lab/contract.log"
