#!/usr/bin/env bash
set -euo pipefail
# Dedicated synthetic pair: Portal owns its Docker service; this runner owns
# only a fresh Sites TLS cluster and the declared receiver/recovery CLI paths.
test "${GITHUB_ACTIONS:-}" = true
test "${SITES_PORTAL_INTAKE_RECOVERY_CI:-}" = 1
test "$(id -u)" -gt 0
: "${PG_BIN:?Authenticated PostgreSQL 18 tooling required}"
: "${PHP_BIN:?PHP with both locked application dependency sets required}"
: "${SITES_PORTAL_RECOVERY_SOURCE:?Exact Sites source root required}"
: "${SITES_PORTAL_RECOVERY_PORTAL_SOURCE:?Exact pinned Portal source root required}"
: "${SITES_PORTAL_RECOVERY_HEAD:?Exact Sites source commit required}"
test "${PORTAL_NATIVE_ORDER_TEST:-}" = DISPOSABLE_PG18
test "${PORTAL_NATIVE_ORDER_TEST_PORT:-}" = 5432
test "${PORTAL_NATIVE_ORDER_TEST_DATA:-}" = /var/lib/postgresql/18/docker
[[ "${PORTAL_NATIVE_ORDER_TEST_SERVER_ADDRESS:-}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
[[ "$SITES_PORTAL_RECOVERY_HEAD" =~ ^[0-9a-f]{40}$ ]]
[[ "$PG_BIN" = /* && "$PHP_BIN" = /* ]]

repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
portal=$(cd -- "$SITES_PORTAL_RECOVERY_PORTAL_SOURCE" && pwd -P)
test "$SITES_PORTAL_RECOVERY_SOURCE" = "$repo"
test "$SITES_PORTAL_RECOVERY_PORTAL_SOURCE" = "$portal"
test "$repo" != "$portal"
test "$(git -C "$repo" rev-parse --show-toplevel)" = "$repo"
test "$(git -C "$portal" rev-parse --show-toplevel)" = "$portal"
sources_unchanged() {
  test "$(git -C "$repo" rev-parse HEAD)" = "$SITES_PORTAL_RECOVERY_HEAD" || return 1
  test "$(git -C "$portal" rev-parse HEAD)" = d1aaab523848baf80325a023f3ebbf4998a3607f || return 1
  git -C "$repo" diff --quiet HEAD -- || return 1
  git -C "$portal" diff --quiet HEAD -- || return 1
  local app forbidden
  for app in "$repo/apps/sites" "$portal/apps/portal"; do
    for forbidden in "$app/.env" "$app/.env.testing" "$app/bootstrap/cache/config.php"; do
      if [[ -e "$forbidden" || -L "$forbidden" ]]; then return 1; fi
    done
    test -r "$app/vendor/autoload.php" || return 1
  done
}
sources_unchanged
test -f "$portal/apps/portal/phpunit.xml"
test -f "$portal/apps/portal/vendor/bin/phpunit"
test -f "$repo/tests/fixtures/portal-intake-recovery/sites-bridge.php"
test -f "$repo/tests/fixtures/portal-intake-recovery/NativePortalIntakeRecoveryTest.php"
for binary in openssl python3 sudo timeout awk grep; do command -v "$binary" > /dev/null; done
for binary in postgres initdb pg_ctl pg_controldata; do test -x "$PG_BIN/$binary"; done
test -x "$PHP_BIN"
"$PG_BIN/postgres" --version | grep -E '^postgres \(PostgreSQL\) 18[.]'

# Only flags required by the two unchanged path helpers and their CLIs. The
# registry/BAP contract, its package helper and its predecessor chain do not run.
export PG_BIN PHP_BIN SITES_PORTAL_RECOVERY_SOURCE SITES_PORTAL_RECOVERY_PORTAL_SOURCE SITES_PORTAL_RECOVERY_HEAD
export APP_ENV=testing APP_DEBUG=false SITES_RELEASE_REGISTRY_CI=1
export SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1
unset DB_URL DB_PASSWORD SITES_DB_PASSWORD_FILE
umask 077
lab=$(mktemp -d /tmp/sites-release-registry-XXXXXXXX)
fixture_uid=$(id -u)
fixture_gid=$(id -g)
export RELEASE_REGISTRY_LAB_DIR="$lab" RELEASE_REGISTRY_PGDATA="$lab/data"
export RELEASE_REGISTRY_PASSWORD_FILE="$lab/password" RELEASE_REGISTRY_CA_FILE="$lab/tls/ca.pem"
export RELEASE_REGISTRY_LOGIN="$(id -un)"
schema_started=0
schema_reversed=0

require_marker() {
  local log=$1 marker=$2
  if ! grep -Fxq "$marker" "$log"; then
    printf 'PORTAL INTAKE RECOVERY RUNNER FAILED: missing success marker: %s\n' "$marker" >&2
    return 1
  fi
  printf '%s\n' "$marker"
}

bridge() {
  local mode=$1 log=$2
  if (cd -- "$repo/apps/sites" && APP_BASE_PATH="$repo/apps/sites" \
      timeout --signal=TERM --kill-after=5s 120s "$PHP_BIN" \
      "$repo/tests/fixtures/portal-intake-recovery/sites-bridge.php" "$mode") > "$log" 2>&1; then
    return 0
  fi
  # The bridge owns this bounded diagnostic format; PDO/process output stays in
  # the private laboratory and is never copied to CI logs or an artifact.
  grep -m 1 '^PORTAL INTAKE RECOVERY SITES FAILED: ' "$log" >&2 || true
  printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: Sites bridge did not complete' >&2
  return 1
}

helper18() {
  local mode=$1 log=$2 marker
  case "$mode" in
    prepare) marker='PROJECT INTAKE RECEIVER CLI PATHS READY:' ;;
    verify-mismatch) marker='PROJECT INTAKE RECEIVER CLI PATHS NEGATIVE OK:' ;;
    cleanup) marker='PROJECT INTAKE RECEIVER CLI PATHS CLEANUP OK:' ;;
    *) return 1 ;;
  esac
  if ! timeout --signal=TERM --kill-after=5s 30s sudo -n env GITHUB_ACTIONS=true \
      SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 \
      python3 "$repo/tests/fixtures/project-intake-receiver/cli-paths.py" \
      "$mode" "$lab" "$fixture_uid" "$fixture_gid" > "$log" 2>&1; then
    printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: receiver path custody refused' >&2
    return 1
  fi
  grep -m 1 "^$marker" "$log"
}

helper19() {
  local mode=$1 log=$2 marker
  case "$mode" in
    prepare) marker='PROJECT INTAKE RECOVERY CLI PATHS READY:' ;;
    verify-mismatch) marker='PROJECT INTAKE RECOVERY CLI PATHS NEGATIVE OK:' ;;
    cleanup) marker='PROJECT INTAKE RECOVERY CLI PATHS CLEANUP OK:' ;;
    *) return 1 ;;
  esac
  if ! timeout --signal=TERM --kill-after=5s 30s sudo -n env GITHUB_ACTIONS=true \
      SITES_RELEASE_REGISTRY_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1 \
      python3 "$repo/tests/fixtures/project-intake-recovery/cli-paths.py" \
      "$mode" "$lab" "$fixture_uid" "$fixture_gid" > "$log" 2>&1; then
    printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: recovery path custody refused' >&2
    return 1
  fi
  grep -m 1 "^$marker" "$log"
}

cleanup() {
  local original=$? failed=0
  trap - EXIT INT TERM
  set +e
  if [[ "$schema_started" == 1 && "$schema_reversed" != 1 ]]; then
    if bridge schema-down "$lab/schema-down-cleanup.log"; then
      if ! grep -Ex 'PORTAL INTAKE RECOVERY SITES SCHEMA DOWN PASS: (([0-9]|1[0-5]) tracked migrations; three schemas absent|no owned schema setup to reverse)' \
          "$lab/schema-down-cleanup.log"; then failed=1; fi
    else
      failed=1
    fi
  fi
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    "$PG_BIN/pg_ctl" -D "$lab/data" -m fast -t 30 -w stop > "$lab/stop.log" 2>&1 || failed=1
  fi
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    failed=1
    printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: own Sites cluster remains active' >&2
  else
    printf '%s\n' 'PORTAL INTAKE RECOVERY SITES CLUSTER INACTIVE'
  fi
  # Helpers inventory all recorded paths before unlink; unknown/substituted
  # resources remain untouched. The safe /run/secrets parent is retained.
  if [[ -e "$lab/project-intake-recovery-paths.json" || -L "$lab/project-intake-recovery-paths.json" ]]; then
    helper19 cleanup "$lab/recovery-cleanup.log" || failed=1
  fi
  if [[ -e "$lab/project-intake-receiver-paths.json" || -L "$lab/project-intake-receiver-paths.json" ]]; then
    helper18 cleanup "$lab/receiver-cleanup.log" || failed=1
  fi
  if [[ "$failed" != 0 ]]; then
    printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: incomplete cleanup; owned diagnostic state retained' >&2
  fi
  if [[ "$original" != 0 ]]; then exit "$original"; fi
  exit "$failed"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -m 0700 "$lab/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Sites Portal Recovery Disposable CA' \
  -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -keyout "$lab/tls/ca.key" -out "$lab/tls/ca.pem" > "$lab/ca.log" 2>&1
openssl req -new -newkey rsa:2048 -nodes -subj '/CN=localhost' \
  -keyout "$lab/tls/server.key" -out "$lab/tls/server.csr" > "$lab/csr.log" 2>&1
cat > "$lab/tls/server.ext" <<'CERTIFICATE'
subjectAltName=DNS:localhost,IP:127.0.0.1
extendedKeyUsage=serverAuth
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
CERTIFICATE
openssl x509 -req -days 1 -in "$lab/tls/server.csr" -CA "$lab/tls/ca.pem" \
  -CAkey "$lab/tls/ca.key" -CAcreateserial -extfile "$lab/tls/server.ext" \
  -out "$lab/tls/server.pem" > "$lab/certificate.log" 2>&1
chmod 0600 "$lab/tls/"*.key
"$PHP_BIN" -r '$p=getenv("RELEASE_REGISTRY_PASSWORD_FILE");$f=fopen($p,"xb");if($f===false||fwrite($f,bin2hex(random_bytes(32)))!==64||!fflush($f)||!fsync($f)||!fclose($f)){exit(1);}' \
  > "$lab/password-creation.log" 2>&1
"$PG_BIN/initdb" -D "$lab/data" --pwfile="$lab/password" --auth-host=scram-sha-256 \
  --auth-local=reject --no-locale -E UTF8 > "$lab/initdb.log" 2>&1
export RELEASE_REGISTRY_CLUSTER
RELEASE_REGISTRY_CLUSTER=$(LC_ALL=C "$PG_BIN/pg_controldata" "$lab/data" | awk '/Database system identifier:/ {print $NF}')
[[ "$RELEASE_REGISTRY_CLUSTER" =~ ^[0-9]+$ ]]
"$PG_BIN/pg_ctl" -D "$lab/data" -l "$lab/postgres.log" -t 30 \
  -o "-p 55449 -c listen_addresses=127.0.0.1 -c unix_socket_directories='' -c ssl=on -c ssl_cert_file=$lab/tls/server.pem -c ssl_key_file=$lab/tls/server.key" \
  -w start > "$lab/start.log" 2>&1
bridge preflight "$lab/preflight.log"
require_marker "$lab/preflight.log" 'PORTAL INTAKE RECOVERY SITES PREFLIGHT PASS'

# The unchanged path helpers require this parent. Create only when absent,
# validate through pinned descriptors, never chmod/adopt/replace an unsafe one.
sudo -n env GITHUB_ACTIONS=true SITES_PORTAL_INTAKE_RECOVERY_CI=1 python3 - <<'PYTHON'
import os
import pathlib
import stat
import sys

def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid)

try:
    if (os.geteuid() != 0 or os.environ.get('GITHUB_ACTIONS') != 'true'
            or os.environ.get('SITES_PORTAL_INTAKE_RECOVERY_CI') != '1'):
        raise RuntimeError('disposable root preparation required')
    parent = pathlib.Path('/run')
    before = parent.lstat()
    if (parent.resolve() != parent or not stat.S_ISDIR(before.st_mode)
            or before.st_uid != 0 or before.st_mode & 0o022):
        raise RuntimeError('unsafe runtime parent')
    directory = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if identity(os.fstat(directory)) != identity(before):
            raise RuntimeError('runtime parent changed')
        try:
            child = os.stat('secrets', dir_fd=directory, follow_symlinks=False)
        except FileNotFoundError:
            os.umask(0o022)
            os.mkdir('secrets', 0o755, dir_fd=directory)
            child = os.stat('secrets', dir_fd=directory, follow_symlinks=False)
        if (not stat.S_ISDIR(child.st_mode) or child.st_uid != 0
                or child.st_mode & 0o022 or pathlib.Path('/run/secrets').resolve() != pathlib.Path('/run/secrets')):
            raise RuntimeError('unsafe secrets parent')
        opened = os.open('secrets', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
        try:
            if (identity(os.fstat(opened)) != identity(child)
                    or identity(os.stat('secrets', dir_fd=directory, follow_symlinks=False)) != identity(child)
                    or identity(parent.lstat()) != identity(before)):
                raise RuntimeError('runtime parent changed')
        finally:
            os.close(opened)
    finally:
        os.close(directory)
except Exception:
    print('PORTAL INTAKE RECOVERY RUNNER FAILED: unsafe private input parent', file=sys.stderr)
    sys.exit(1)
print('PORTAL INTAKE RECOVERY PRIVATE INPUT PARENT READY')
PYTHON

helper18 prepare "$lab/receiver-prepare.log"
helper18 verify-mismatch "$lab/receiver-negative.log"
helper19 prepare "$lab/recovery-prepare.log"
helper19 verify-mismatch "$lab/recovery-negative.log"

schema_started=1
bridge schema-up "$lab/schema-up.log"
require_marker "$lab/schema-up.log" 'PORTAL INTAKE RECOVERY SITES SCHEMA UP PASS: 15 tracked migrations'

# Portal loads only its own vendor and config. It invokes the Sites bridge in a
# separate process; no shared App/Tests autoloader or shared database is used.
if ! (cd -- "$portal/apps/portal" && APP_BASE_PATH="$portal/apps/portal" \
    timeout --signal=TERM --kill-after=5s 300s "$PHP_BIN" "$portal/apps/portal/vendor/bin/phpunit" \
    --configuration "$portal/apps/portal/phpunit.xml" --do-not-cache-result --colors=never \
    --fail-on-risky --fail-on-warning --fail-on-skipped \
    "$repo/tests/fixtures/portal-intake-recovery/NativePortalIntakeRecoveryTest.php") > "$lab/portal-phpunit.log" 2>&1; then
  grep -m 1 '^PORTAL INTAKE RECOVERY SITES FAILED: ' "$lab/portal-phpunit.log" >&2 || true
  grep -m 1 '^PORTAL INTAKE RECOVERY PORTAL FAILED' "$lab/portal-phpunit.log" >&2 || true
  printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: pinned Portal proof did not complete' >&2
  exit 1
fi
if ! grep -Ex 'PORTAL INTAKE RECOVERY BRIDGE PASS: [1-9][0-9]* checks' "$lab/portal-phpunit.log" ||
    ! grep -Ex 'PORTAL INTAKE RECOVERY PORTAL PASS: [1-9][0-9]* checks' "$lab/portal-phpunit.log" ||
    ! grep -Ex 'OK \(1 test, [1-9][0-9]* assertions\)' "$lab/portal-phpunit.log"; then
  printf '%s\n' 'PORTAL INTAKE RECOVERY RUNNER FAILED: paired assertion markers or PHPUnit completion missing' >&2
  exit 1
fi
sources_unchanged
bridge verify-final "$lab/verify-final.log"
require_marker "$lab/verify-final.log" 'PORTAL INTAKE RECOVERY SITES FINAL PASS'
bridge schema-down "$lab/schema-down.log"
require_marker "$lab/schema-down.log" 'PORTAL INTAKE RECOVERY SITES SCHEMA DOWN PASS: 15 tracked migrations; three schemas absent'
schema_reversed=1
printf '%s\n' 'PORTAL INTAKE RECOVERY PAIRED CONTRACT PASS: pinned native Portal consumer and recovered private Sites receipt'
