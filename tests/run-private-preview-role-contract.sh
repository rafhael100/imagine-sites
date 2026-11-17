#!/usr/bin/env bash
set -euo pipefail
# No installation/download and no existing database. Run only on a host allowing local PG.
: "${PG_BIN:?Set PG_BIN to existing PostgreSQL18 binaries}"
: "${PHP_BIN:?Set PHP_BIN to existing PHP8.3+ with locked Sites dependencies}"
repo=$(cd "$(dirname "$0")/.." && pwd)
"$PG_BIN/postgres" --version | grep -E '^postgres \(PostgreSQL\) 18[.]'
umask 077
lab=$(mktemp -d /tmp/sites486-role-contract-XXXXXXXX)
export ROLE_CONTRACT_PGDATA="$lab/data" ROLE_CONTRACT_PASSWORD_FILE="$lab/password"
export ROLE_CONTRACT_LOGIN="$(id -un)" SITES_LOCAL_ROLE_CONTRACT=1 APP_ENV=testing
unset DB_URL DB_PASSWORD SITES_DB_PASSWORD_FILE
"$PHP_BIN" -r 'file_put_contents(getenv("ROLE_CONTRACT_PASSWORD_FILE"),bin2hex(random_bytes(32)));'
"$PG_BIN/initdb" -D "$lab/data" --pwfile="$lab/password" --auth-host=scram-sha-256 --auth-local=reject --no-locale -E UTF8 > "$lab/initdb.log"
export ROLE_CONTRACT_CLUSTER
ROLE_CONTRACT_CLUSTER=$(LC_ALL=C "$PG_BIN/pg_controldata" "$lab/data" | awk '/Database system identifier:/ {print $NF}')
[[ "$ROLE_CONTRACT_CLUSTER" =~ ^[0-9]+$ ]]
cleanup() {
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    "$PG_BIN/pg_ctl" -D "$lab/data" -m fast -w stop > "$lab/stop.log" 2>&1
  fi
  echo "Own disposable cluster inactive; local logs: $lab"
}
trap cleanup EXIT
# No remote listener. A busy port fails; never stop or reuse the occupying service.
"$PG_BIN/pg_ctl" -D "$lab/data" -l "$lab/postgres.log" -o "-p 55448 -c listen_addresses=127.0.0.1 -c unix_socket_directories=''" -w start
if ROLE_CONTRACT_CLUSTER=0 "$PHP_BIN" "$repo/tests/private-preview-role-contract.php" > "$lab/denied.log" 2>&1; then
  echo 'Incorrect cluster identity was accepted' >&2; exit 1
fi
grep -q 'Disposable SQL identity mismatch' "$lab/denied.log"
# Still-empty namespace and absent fixture roles prove wrong identity stopped before any writes.
ROLE_CONTRACT_PREFLIGHT_ONLY=1 "$PHP_BIN" "$repo/tests/private-preview-role-contract.php" | tee "$lab/preflight.log"
grep -q '^PREFLIGHT OK:' "$lab/preflight.log"
"$PHP_BIN" "$repo/tests/private-preview-role-contract.php" | tee "$lab/contract.log"
grep -q '^OK: [0-9]* synthetic ownership/ACL/RLS/profile assertions;' "$lab/contract.log"
