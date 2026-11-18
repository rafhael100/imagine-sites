#!/usr/bin/env bash
set -euo pipefail
# Own disposable CI cluster only. Never attach to an existing service or target.
test "${GITHUB_ACTIONS:-}" = true
test "${SITES_RELEASE_REGISTRY_CI:-}" = 1
if [[ "${SITES_PUBLICATION_REQUEST_READER_CI:-}" == 1 ]]; then
  test "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" = 1
fi
if [[ "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" == 1 ]]; then
  test "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" = 1
fi
if [[ "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_SNAPSHOT_INSPECTION_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_SNAPSHOT_INSPECTION_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_ARTIFACT_REGISTRATION_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_ARTIFACT_REGISTRATION_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_ARTIFACT_INSPECTION_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_ARTIFACT_INSPECTION_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_ARTIFACT_PROVENANCE_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_ARTIFACT_PROVENANCE_CI:-}" == 1 ]]; then
  test "${SITES_BRIEF_SNAPSHOTS_CI:-}" = 1
fi
if [[ "${SITES_BRIEF_SNAPSHOTS_CI:-}" == 1 ]]; then
  test "${SITES_REGISTRY_INTAKE_PERMISSIONS_CI:-}" = 1
fi
if [[ "${SITES_REGISTRY_INTAKE_PERMISSIONS_CI:-}" == 1 ]]; then
  for flag in SITES_PUBLICATION_JOURNAL_CI SITES_PUBLICATION_INSPECTION_CI SITES_RELEASE_REGISTRATION_CI \
    SITES_EDITORIAL_TRANSITION_CI SITES_RELEASE_BINDING_CI SITES_PUBLICATION_RECONCILIATION_CI \
    SITES_PUBLICATION_REQUEST_CI SITES_EDITORIAL_INSPECTION_CI SITES_RELEASE_BINDING_INSPECTION_CI; do
    test "${!flag:-}" = 1
  done
fi
: "${PG_BIN:?Existing authenticated PostgreSQL18 tooling required}"
: "${PHP_BIN:?PHP with the locked application dependencies required}"
repo=$(cd "$(dirname "$0")/.." && pwd)
"$PG_BIN/postgres" --version | grep -E '^postgres \(PostgreSQL\) 18[.]'
umask 077
lab=$(mktemp -d /tmp/sites-release-registry-XXXXXXXX)
export RELEASE_REGISTRY_PGDATA="$lab/data" RELEASE_REGISTRY_PASSWORD_FILE="$lab/password"
export RELEASE_REGISTRY_LAB_DIR="$lab" RELEASE_REGISTRY_CA_FILE="$lab/tls/ca.pem"
export RELEASE_REGISTRY_LOGIN="$(id -un)" APP_ENV=testing SITES_RELEASE_PACKAGE_CI=1
unset DB_URL DB_PASSWORD SITES_DB_PASSWORD_FILE
command -v openssl > /dev/null
mkdir -m 0700 "$lab/tls"
# One-run CA and peer certificate: both existing 127.0.0.1 connections and the
# real package CLI's localhost hostname are verified, never sslmode=disable.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Sites Registry Disposable CA' \
  -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -keyout "$lab/tls/ca.key" -out "$lab/tls/ca.pem" > /dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -subj '/CN=localhost' \
  -keyout "$lab/tls/server.key" -out "$lab/tls/server.csr" > /dev/null 2>&1
cat > "$lab/tls/server.ext" <<'CERTIFICATE'
subjectAltName=DNS:localhost,IP:127.0.0.1
extendedKeyUsage=serverAuth
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
CERTIFICATE
openssl x509 -req -days 1 -in "$lab/tls/server.csr" -CA "$lab/tls/ca.pem" \
  -CAkey "$lab/tls/ca.key" -CAcreateserial -extfile "$lab/tls/server.ext" \
  -out "$lab/tls/server.pem" > /dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Wrong Disposable CA' \
  -keyout "$lab/tls/wrong-ca.key" -out "$lab/tls/wrong-ca.pem" > /dev/null 2>&1
chmod 0600 "$lab/tls/"*.key
"$PHP_BIN" -r 'file_put_contents(getenv("RELEASE_REGISTRY_PASSWORD_FILE"),bin2hex(random_bytes(32)));'
"$PG_BIN/initdb" -D "$lab/data" --pwfile="$lab/password" --auth-host=scram-sha-256 --auth-local=reject --no-locale -E UTF8 > "$lab/initdb.log"
export RELEASE_REGISTRY_CLUSTER
RELEASE_REGISTRY_CLUSTER=$(LC_ALL=C "$PG_BIN/pg_controldata" "$lab/data" | awk '/Database system identifier:/ {print $NF}')
[[ "$RELEASE_REGISTRY_CLUSTER" =~ ^[0-9]+$ ]]
cleanup() {
  local result=$?
  trap - EXIT
  local failed=0
  if "$PG_BIN/pg_ctl" -D "$lab/data" status > /dev/null 2>&1; then
    "$PG_BIN/pg_ctl" -D "$lab/data" -m fast -w stop > "$lab/stop.log" 2>&1 || failed=1
  fi
  if [[ "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" == 1 && -f "$lab/project-intake-recovery-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1 \
      python3 "$repo/tests/fixtures/project-intake-recovery/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" == 1 && -f "$lab/project-intake-receiver-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 \
      python3 "$repo/tests/fixtures/project-intake-receiver/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_BRIEF_SNAPSHOT_INSPECTION_CI:-}" == 1 && -f "$lab/brief-snapshot-inspection-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 \
      python3 "$repo/tests/fixtures/brief-snapshot-inspection/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI:-}" == 1 && -f "$lab/brief-snapshot-receiver-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI=1 \
      python3 "$repo/tests/fixtures/brief-snapshot-receiver/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_BRIEF_ARTIFACT_REGISTRATION_CI:-}" == 1 && -f "$lab/brief-artifact-registration-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_REGISTRATION_CI=1 \
      python3 "$repo/tests/fixtures/brief-artifact-registration/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_BRIEF_ARTIFACT_INSPECTION_CI:-}" == 1 && -f "$lab/brief-artifact-inspection-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_INSPECTION_CI=1 \
      python3 "$repo/tests/fixtures/brief-artifact-inspection/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_RELEASE_BINDING_INSPECTION_CI:-}" == 1 && -f "$lab/release-binding-inspection-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_INSPECTION_CI=1 \
      python3 "$repo/tests/fixtures/release-binding-inspection/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_EDITORIAL_INSPECTION_CI:-}" == 1 && -f "$lab/editorial-inspection-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_INSPECTION_CI=1 \
      python3 "$repo/tests/fixtures/editorial-inspection/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_PUBLICATION_REQUEST_CI:-}" == 1 && -f "$lab/publication-request-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_REQUEST_CI=1 \
      python3 "$repo/tests/fixtures/publication-request/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_PUBLICATION_RECONCILIATION_CI:-}" == 1 && -f "$lab/publication-reconciliation-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_RECONCILIATION_CI=1 \
      python3 "$repo/tests/fixtures/publication-reconciliation/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_RELEASE_BINDING_CI:-}" == 1 && -f "$lab/release-binding-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_CI=1 \
      python3 "$repo/tests/fixtures/release-binding/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_EDITORIAL_TRANSITION_CI:-}" == 1 && -f "$lab/editorial-transition-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_TRANSITION_CI=1 \
      python3 "$repo/tests/fixtures/editorial-transition/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_RELEASE_REGISTRATION_CI:-}" == 1 && -f "$lab/release-registration-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_REGISTRATION_CI=1 \
      python3 "$repo/tests/fixtures/release-registration/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "${SITES_PUBLICATION_INSPECTION_CI:-}" == 1 && -f "$lab/publication-inspection-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_JOURNAL_CI=1 \
      SITES_PUBLICATION_INSPECTION_CI=1 python3 "$repo/tests/fixtures/publication-inspection/cli-paths.py" \
      cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ -f "$lab/cli-paths.json" ]]; then
    sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_PACKAGE_CI=1 python3 \
      "$repo/tests/fixtures/release-registry/cli-paths.py" cleanup "$lab" "$(id -u)" "$(id -g)" || failed=1
  fi
  if [[ "$failed" != 0 ]]; then echo 'Registry fixture cleanup failed; owned state retained' >&2; exit 1; fi
  echo "Own disposable release registry cluster inactive; logs: $lab"
  exit "$result"
}
trap cleanup EXIT
"$PG_BIN/pg_ctl" -D "$lab/data" -l "$lab/postgres.log" \
  -o "-p 55449 -c listen_addresses=127.0.0.1 -c unix_socket_directories='' -c ssl=on -c ssl_cert_file=$lab/tls/server.pem -c ssl_key_file=$lab/tls/server.key" -w start
if RELEASE_REGISTRY_CA_FILE="$lab/tls/wrong-ca.pem" "$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" > "$lab/denied-ca.log" 2>&1; then
  echo 'Untrusted registry CA accepted' >&2; exit 1
fi
if ! grep -q '^RELEASE_REGISTRY_FAILED PDOException:' "$lab/denied-ca.log" || ! grep -q 'certificate verify failed' "$lab/denied-ca.log"; then
  echo 'TLS negative test did not reach certificate verification; private diagnostic withheld' >&2; exit 1
fi
echo 'REGISTRY TLS NEGATIVE OK: untrusted CA refused before SQL writes'
wrong_cluster=1
if [[ "$RELEASE_REGISTRY_CLUSTER" == "$wrong_cluster" ]]; then wrong_cluster=2; fi
if RELEASE_REGISTRY_CLUSTER="$wrong_cluster" "$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" > "$lab/denied.log" 2>&1; then
  echo 'Wrong registry cluster accepted' >&2; exit 1
fi
grep -qx 'RELEASE_REGISTRY_FAILED RuntimeException: Disposable SQL identity mismatch' "$lab/denied.log"
RELEASE_REGISTRY_PREFLIGHT_ONLY=1 "$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" | tee "$lab/preflight.log"
grep -q '^REGISTRY PREFLIGHT OK:' "$lab/preflight.log"
sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_PACKAGE_CI=1 python3 \
  "$repo/tests/fixtures/release-registry/cli-paths.py" prepare "$lab" "$(id -u)" "$(id -g)"
sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_PACKAGE_CI=1 python3 \
  "$repo/tests/fixtures/release-registry/cli-paths.py" verify-mismatch "$lab" "$(id -u)" "$(id -g)"
if [[ "${SITES_PUBLICATION_INSPECTION_CI:-}" == 1 ]]; then
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_JOURNAL_CI=1 \
    SITES_PUBLICATION_INSPECTION_CI=1 python3 "$repo/tests/fixtures/publication-inspection/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_JOURNAL_CI=1 \
    SITES_PUBLICATION_INSPECTION_CI=1 python3 "$repo/tests/fixtures/publication-inspection/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_RELEASE_REGISTRATION_CI:-}" == 1 ]]; then
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_REGISTRATION_CI=1 \
    python3 "$repo/tests/fixtures/release-registration/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_REGISTRATION_CI=1 \
    python3 "$repo/tests/fixtures/release-registration/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_EDITORIAL_TRANSITION_CI:-}" == 1 ]]; then
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_TRANSITION_CI=1 \
    python3 "$repo/tests/fixtures/editorial-transition/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_TRANSITION_CI=1 \
    python3 "$repo/tests/fixtures/editorial-transition/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_RELEASE_BINDING_CI:-}" == 1 ]]; then
  test "${SITES_EDITORIAL_TRANSITION_CI:-}" = 1
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_CI=1 \
    python3 "$repo/tests/fixtures/release-binding/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_CI=1 \
    python3 "$repo/tests/fixtures/release-binding/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_PUBLICATION_RECONCILIATION_CI:-}" == 1 ]]; then
  test "${SITES_RELEASE_BINDING_CI:-}" = 1
  test "${SITES_EDITORIAL_TRANSITION_CI:-}" = 1
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_RECONCILIATION_CI=1 \
    python3 "$repo/tests/fixtures/publication-reconciliation/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_RECONCILIATION_CI=1 \
    python3 "$repo/tests/fixtures/publication-reconciliation/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_PUBLICATION_REQUEST_CI:-}" == 1 ]]; then
  test "${SITES_PUBLICATION_RECONCILIATION_CI:-}" = 1
  test "${SITES_RELEASE_BINDING_CI:-}" = 1
  test "${SITES_EDITORIAL_TRANSITION_CI:-}" = 1
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_REQUEST_CI=1 \
    python3 "$repo/tests/fixtures/publication-request/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PUBLICATION_REQUEST_CI=1 \
    python3 "$repo/tests/fixtures/publication-request/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_EDITORIAL_INSPECTION_CI:-}" == 1 ]]; then
  test "${SITES_PUBLICATION_REQUEST_CI:-}" = 1
  test "${SITES_PUBLICATION_RECONCILIATION_CI:-}" = 1
  test "${SITES_RELEASE_BINDING_CI:-}" = 1
  test "${SITES_EDITORIAL_TRANSITION_CI:-}" = 1
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/editorial-inspection/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_EDITORIAL_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/editorial-inspection/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_RELEASE_BINDING_INSPECTION_CI:-}" == 1 ]]; then
  test "${SITES_EDITORIAL_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_REQUEST_CI:-}" = 1
  test "${SITES_PUBLICATION_RECONCILIATION_CI:-}" = 1
  test "${SITES_RELEASE_BINDING_CI:-}" = 1
  test "${SITES_EDITORIAL_TRANSITION_CI:-}" = 1
  test "${SITES_RELEASE_REGISTRATION_CI:-}" = 1
  test "${SITES_PUBLICATION_INSPECTION_CI:-}" = 1
  test "${SITES_PUBLICATION_JOURNAL_CI:-}" = 1
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/release-binding-inspection/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_RELEASE_BINDING_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/release-binding-inspection/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_BRIEF_ARTIFACT_INSPECTION_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/brief-artifact-inspection/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/brief-artifact-inspection/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_BRIEF_ARTIFACT_REGISTRATION_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_REGISTRATION_CI=1 \
    python3 "$repo/tests/fixtures/brief-artifact-registration/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_ARTIFACT_REGISTRATION_CI=1 \
    python3 "$repo/tests/fixtures/brief-artifact-registration/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI=1 \
    python3 "$repo/tests/fixtures/brief-snapshot-receiver/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI=1 \
    python3 "$repo/tests/fixtures/brief-snapshot-receiver/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_BRIEF_SNAPSHOT_INSPECTION_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/brief-snapshot-inspection/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 \
    python3 "$repo/tests/fixtures/brief-snapshot-inspection/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 \
    python3 "$repo/tests/fixtures/project-intake-receiver/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_BRIEF_SNAPSHOT_INSPECTION_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 \
    python3 "$repo/tests/fixtures/project-intake-receiver/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
if [[ "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" == 1 ]]; then
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1 \
    python3 "$repo/tests/fixtures/project-intake-recovery/cli-paths.py" \
    prepare "$lab" "$(id -u)" "$(id -g)"
  sudo -n env GITHUB_ACTIONS=true SITES_RELEASE_REGISTRY_CI=1 SITES_PROJECT_INTAKE_RECEIVER_CLI_CI=1 SITES_PROJECT_INTAKE_RECOVERY_CLI_CI=1 \
    python3 "$repo/tests/fixtures/project-intake-recovery/cli-paths.py" \
    verify-mismatch "$lab" "$(id -u)" "$(id -g)"
fi
"$PHP_BIN" "$repo/tests/release-registry-pg-contract.php" | tee "$lab/contract.log"
grep -q '^OK: [1-9][0-9]* persistent release registry assertions;' "$lab/contract.log"
grep -q '^TECHNICAL PACKAGE CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
grep -q '^TECHNICAL PACKAGE CLI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
if [[ "${SITES_PUBLICATION_JOURNAL_CI:-}" == 1 ]]; then
  grep -q '^PUBLICATION JOURNAL CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_PUBLICATION_INSPECTION_CI:-}" == 1 ]]; then
  grep -q '^PUBLICATION INSPECTION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_RELEASE_REGISTRATION_CI:-}" == 1 ]]; then
  grep -q '^RELEASE REGISTRATION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
  grep -q '^LOCAL RENDER PROVENANCE REGISTRY CI PASS: 4 new assertions;' "$lab/contract.log"
fi
if [[ "${SITES_EDITORIAL_TRANSITION_CI:-}" == 1 ]]; then
  grep -q '^EDITORIAL TRANSITION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_RELEASE_BINDING_CI:-}" == 1 ]]; then
  grep -q '^RELEASE BINDING CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_PUBLICATION_RECONCILIATION_CI:-}" == 1 ]]; then
  grep -q '^PUBLICATION RECONCILIATION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_PUBLICATION_REQUEST_CI:-}" == 1 ]]; then
  grep -q '^PUBLICATION REQUEST CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_EDITORIAL_INSPECTION_CI:-}" == 1 ]]; then
  grep -q '^EDITORIAL INSPECTION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_RELEASE_BINDING_INSPECTION_CI:-}" == 1 ]]; then
  grep -q '^RELEASE BINDING INSPECTION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_REGISTRY_INTAKE_PERMISSIONS_CI:-}" == 1 ]]; then
  grep -q '^REGISTRY INTAKE PERMISSIONS CI PASS: [1-9][0-9]* assertions; 905 existing assertions retained[.]' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_SNAPSHOTS_CI:-}" == 1 ]]; then
  grep -q '^NATIVE BRIEF SCHEMA REGISTRY CI PASS: 1 new assertion;' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_ARTIFACT_PROVENANCE_CI:-}" == 1 ]]; then
  grep -q '^BRIEF ARTIFACT PREDECESSOR CI PASS: 923 assertions;' "$lab/contract.log"
  grep -q '^BRIEF ARTIFACT PROVENANCE CI PASS: [1-9][0-9]* new assertions; 923 existing assertions retained[.]' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_ARTIFACT_INSPECTION_CI:-}" == 1 ]]; then
  grep -q '^BRIEF ARTIFACT INSPECTION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_ARTIFACT_REGISTRATION_CI:-}" == 1 ]]; then
  grep -q '^BRIEF ARTIFACT REGISTRATION CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI:-}" == 1 ]]; then
  grep -q '^BRIEF SNAPSHOT RECEIVER CI PASS: [1-9][0-9]* assertions;' "$lab/contract.log"
fi
if [[ "${SITES_BRIEF_SNAPSHOT_INSPECTION_CI:-}" == 1 ]]; then
  grep -q '^BRIEF SNAPSHOT INSPECTION CI PASS: 26 assertions;' "$lab/contract.log"
  if [[ "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" != 1 ]]; then
    grep -q '^OK: 1149 persistent release registry assertions;' "$lab/contract.log"
  fi
fi
if [[ "${SITES_PROJECT_INTAKE_RECEIVER_CLI_CI:-}" == 1 ]]; then
  grep -q '^PROJECT INTAKE RECEIVER CLI CI PASS: 29 assertions;' "$lab/contract.log"
  if [[ "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" != 1 ]]; then
    grep -q '^OK: 1178 persistent release registry assertions;' "$lab/contract.log"
  fi
fi
if [[ "${SITES_PROJECT_INTAKE_RECOVERY_CLI_CI:-}" == 1 ]]; then
  grep -q '^PROJECT INTAKE RECOVERY CLI CI PASS: 30 assertions;' "$lab/contract.log"
  if [[ "${SITES_PUBLICATION_REQUEST_READER_CI:-}" != 1 ]]; then
    grep -q '^OK: 1208 persistent release registry assertions;' "$lab/contract.log"
  fi
fi
if [[ "${SITES_PUBLICATION_REQUEST_READER_CI:-}" == 1 ]]; then
  grep -q '^PUBLICATION REQUEST READER CI PASS: 33 assertions; 1208 existing assertions retained;' "$lab/contract.log"
  grep -q '^OK: 1241 persistent release registry assertions;' "$lab/contract.log"
fi
