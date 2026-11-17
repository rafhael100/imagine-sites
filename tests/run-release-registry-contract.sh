#!/usr/bin/env bash
set -euo pipefail
# Own disposable CI cluster only. Never attach to an existing service or target.
test "${GITHUB_ACTIONS:-}" = true
test "${SITES_RELEASE_REGISTRY_CI:-}" = 1
: "${PG_BIN:?Existing authenticated PostgreSQL18 tooling required}"
: "${PHP_BIN:?PHP with the locked application dependencies required}"
repo=$(cd "$(dirname "$0")/.." && pwd)
"$PG_BIN/postgres" --version | grep -E '^postgres \(PostgreSQL\) 18[.]'
umask 077
lab=$(mktemp -d /tmp/sites-release-registry-XXXXXXXX)
export RELEASE_REGISTRY_PGDATA="$lab/data" RELEASE_REGISTRY_PASSWORD_FILE="$lab/password"
export RELEASE_REGISTRY_LOGIN="$(id -un)" APP_ENV=testing
unset DB_URL DB_PASSWORD SITES_DB_PASSWORD_FILE
"$PHP_BIN" -r 'file_put_contents(getenv("RELEASE_REGISTRY_PASSWORD_FILE"),bin2hex(random_bytes(32)));'
"$PG_BIN/initdb" -D "$lab/data" --pwfile="$lab/password" --auth-host=scram-sha-256 --auth-local=reject --no-locale -E UTF8 > "$lab/initdb.log"
export RELEASE_REGISTRY_CLUSTER
RELEASE_REGISTRY_CLUSTER=$(LC_ALL=C "$PG_BIN/pg_controldata" "$lab/data" | awk '/Database system identifier:/ {print $NF}')
[[ "$RELEASE_REGISTRY_CLUSTER" =~ ^[0-9]+$ ]]
cleanup() {
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    "$PG_BIN/pg_ctl" -D "$lab/data" -m fast -w stop > "$lab/stop.log" 2>&1
  fi
  echo "Own disposable release registry cluster inactive; logs: $lab"
}
trap cleanup EXIT
"$PG_BIN/pg_ctl" -D "$lab/data" -l "$lab/postgres.log" -o "-p 55449 -c listen_addresses=127.0.0.1 -c unix_socket_directories=''" -w start
wrong_cluster=1
if [[ "$RELEASE_REGISTRY_CLUSTER" == "$wrong_cluster" ]]; then wrong_cluster=2; fi
if RELEASE_REGISTRY_CLUSTER="$wrong_cluster" "$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" > "$lab/denied.log" 2>&1; then
  echo 'Wrong registry cluster accepted' >&2; exit 1
fi
grep -qx 'RELEASE_REGISTRY_FAILED RuntimeException: Disposable SQL identity mismatch' "$lab/denied.log"
RELEASE_REGISTRY_PREFLIGHT_ONLY=1 "$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" | tee "$lab/preflight.log"
grep -q '^REGISTRY PREFLIGHT OK:' "$lab/preflight.log"
"$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" | tee "$lab/contract.log"
grep -q '^OK: [0-9]* persistent release registry assertions;' "$lab/contract.log"
