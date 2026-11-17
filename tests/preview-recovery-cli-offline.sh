#!/bin/bash
set -euo pipefail
: "${PHP_BIN:?}" "${PHP_INI:?}" "${PG_BIN:?}"
cd "$(dirname "$0")/.."
base=(/usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C SITES_LOCAL_RECOVERY=1 PHP_BIN="$PHP_BIN" PHP_INI="$PHP_INI" PG_BIN="$PG_BIN")
script=tests/run-preview-recovery.sh
count=0
for key in PGHOSTADDR PGOPTIONS PHPRC PHP_INI_SCAN_DIR; do
  set +e
  output=$("${base[@]}" "$key=synthetic-unexpected" /bin/bash --noprofile --norc "$script" --clean --preflight-only 2>&1); code=$?
  set -e
  [[ $code == 2 && $output == 'ENV PREFLIGHT REFUSED' ]]
  count=$((count+1))
done
# Normal Bash exports PWD, SHLVL and _: they must not cause a false refusal.
output=$("${base[@]}" /bin/bash --noprofile --norc "$script" --clean --preflight-only)
[[ $output == 'PHP PREFLIGHT OK:'* ]]; count=$((count+1))
output=$("${base[@]}" PGOPTIONS=synthetic-unexpected /bin/bash --noprofile --norc "$script" --preflight-only)
[[ $output == 'PHP PREFLIGHT OK:'* ]]; count=$((count+1))
fixture=$(mktemp /tmp/sites487-offline-ini-XXXXXXXX)
trap 'rm -- "$fixture"' EXIT
printf 'memory_limit=512M\nunrecognized_lab_option=synthetic\n' > "$fixture"
set +e
output=$("${base[@]}" PHP_INI="$fixture" /bin/bash --noprofile --norc "$script" --clean --preflight-only 2>&1); code=$?
set -e
[[ $code == 2 && $output == 'PHP INI PREFLIGHT REFUSED' ]]; count=$((count+1))
printf 'memory_limit=512M\n' > "$fixture"
set +e
output=$("${base[@]}" PHP_INI="$fixture" /bin/bash --noprofile --norc "$script" --clean --preflight-only 2>&1); code=$?
set -e
[[ $code == 2 && $output == 'PHP PREFLIGHT REFUSED' ]]; count=$((count+1))
echo "CLI OFFLINE OK: $count cases; no PG tool invoked and no cluster started"
