#!/bin/bash
set -euo pipefail
[[ ${SITES_LOCAL_RECOVERY:-} == 1 ]] || exit 2
: "${PG_BIN:?existing PG18 directory}" "${PHP_BIN:?existing PHP CLI}" "${PHP_INI:?reviewed synthetic PHP ini}"
# Never carry ambient dotenv, PG*, PHP scan paths, service files or loader options.
if [[ ${1:-} != --clean ]]; then
  exec /usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C SITES_LOCAL_RECOVERY=1 \
    PG_BIN="$PG_BIN" PHP_BIN="$PHP_BIN" PHP_INI="$PHP_INI" \
    RECOVERY_LIBRARY_PATH="${RECOVERY_LIBRARY_PATH:-}" /bin/bash --noprofile --norc "$0" --clean "${1:-}"
fi
# Internal entry is not a trust token: reject every inherited export outside this list.
while IFS= read -r name; do
  case "$name" in
    PATH|LC_ALL|SITES_LOCAL_RECOVERY|PG_BIN|PHP_BIN|PHP_INI|RECOVERY_LIBRARY_PATH|PWD|SHLVL|_) ;;
    *) echo 'ENV PREFLIGHT REFUSED' >&2; exit 2 ;;
  esac
done < <(compgen -e)
[[ ${PATH:-} == /usr/bin:/bin && ${LC_ALL:-} == C ]] || { echo 'ENV PREFLIGHT REFUSED' >&2; exit 2; }
[[ ${2:-} == '' || ${2:-} == --preflight-only ]] || exit 2
cd "$(dirname "$0")/.."
PG_BIN=$(readlink -e "$PG_BIN"); PHP_BIN=$(readlink -e "$PHP_BIN"); PHP_INI=$(readlink -e "$PHP_INI")
[[ -d $PG_BIN && -x $PHP_BIN && -f $PHP_INI ]] || { echo 'LAB INPUTS FAILED' >&2; exit 2; }
# Review a deliberately narrow INI grammar BEFORE invoking PHP (no arbitrary directives).
while IFS= read -r line || [[ -n $line ]]; do
  [[ $line =~ ^[[:space:]]*$ || $line =~ ^[[:space:]]*\; ]] && continue
  if [[ $line =~ ^extension_dir=(/[-a-zA-Z0-9_./]+)$ ]]; then
    [[ $(readlink -e "${BASH_REMATCH[1]}") == "${BASH_REMATCH[1]}" && -d ${BASH_REMATCH[1]} ]] || { echo 'PHP INI PREFLIGHT REFUSED' >&2; exit 2; }
  elif [[ $line =~ ^extension=(pdo|pdo_pgsql|mbstring|dom|xml|xmlreader|xmlwriter|simplexml|tokenizer|ctype|fileinfo|curl|zip|intl|phar|posix|iconv|sodium|openssl)$ || $line =~ ^memory_limit=(256M|512M|1024M)$ ]]; then :
  else echo 'PHP INI PREFLIGHT REFUSED' >&2; exit 2
  fi
done < "$PHP_INI"
export PG_BIN PHP_INI_SCAN_DIR=''
# Optional explicit, reviewed local runtime libraries; never inherit LD_LIBRARY_PATH.
if [[ -n ${RECOVERY_LIBRARY_PATH:-} ]]; then
  [[ $(readlink -e "$RECOVERY_LIBRARY_PATH") == "$RECOVERY_LIBRARY_PATH" && -d $RECOVERY_LIBRARY_PATH ]] || exit 2
  export LD_LIBRARY_PATH="$RECOVERY_LIBRARY_PATH"
fi
php=("$PHP_BIN" -c "$PHP_INI" -d auto_prepend_file= -d auto_append_file= -d display_errors=0 -d log_errors=0)
"${php[@]}" tests/fixtures/preview-recovery/php-preflight.php "$PHP_INI"
[[ ${2:-} != --preflight-only ]] || exit 0
[[ -f apps/sites/vendor/autoload.php ]] || { echo 'Laravel dependencies missing' >&2; exit 2; }
build=''
for tool in postgres initdb pg_ctl pg_controldata pg_dump pg_restore; do
  [[ $(readlink -e "$PG_BIN/$tool") == "$PG_BIN/$tool" && -x $PG_BIN/$tool ]] || exit 2
  version=$("$PG_BIN/$tool" --version); version=${version#* (PostgreSQL) }
  [[ $version == 18.* ]] || exit 2
  [[ -z $build || $version == "$build" ]] || exit 2
  build=$version
done
umask 077
export RECOVERY_ROOT
RECOVERY_ROOT=$(mktemp -d /tmp/sites487-recovery-XXXXXXXX)
export HOME="$RECOVERY_ROOT"
declare -A pins=()
private_file() { [[ -f $1 && ! -L $1 && $(stat -c '%u:%a:%h' "$1") == "$(id -u):600:1" ]]; }
cluster_id() { LC_ALL=C "$PG_BIN/pg_controldata" "$1" | awk -F': *' '/Database system identifier/{print $2}'; }
cleanup() {
  local original=$? failures=0 side dir pid port
  trap - EXIT
  [[ $(readlink -e "$RECOVERY_ROOT") == "$RECOVERY_ROOT" && ! -L $RECOVERY_ROOT && $(stat -c '%u:%a' "$RECOVERY_ROOT") == "$(id -u):700" ]] || exit 1
  for side in source destination; do
    dir="$RECOVERY_ROOT/$side"
    [[ -n ${pins[$side]:-} ]] || continue
    if [[ $(readlink -e "$dir") != "$dir" || -L $dir || $(stat -c '%u:%a' "$dir") != "$(id -u):700" || $(cluster_id "$dir") != "${pins[$side]}" ]]; then failures=$((failures+1)); continue; fi
    if [[ -f $dir/postmaster.pid ]]; then
      pid=$(sed -n '1p' "$dir/postmaster.pid"); port=55450; [[ $side == destination ]] && port=55451
      if [[ ! $pid =~ ^[1-9][0-9]*$ || $(sed -n '2p' "$dir/postmaster.pid") != "$dir" || $(sed -n '4p' "$dir/postmaster.pid") != "$port" || $(readlink -e "/proc/$pid/exe") != "$PG_BIN/postgres" ]]; then failures=$((failures+1)); continue; fi
      if ! "$PG_BIN/pg_ctl" -D "$dir" -m fast -w stop >/dev/null 2>&1; then failures=$((failures+1)); continue; fi
    fi
    [[ ! -e $dir/postmaster.pid ]] || failures=$((failures+1))
  done
  # Remove only exact transient files after BOTH clusters have been checked inactive.
  if (( failures == 0 )); then
    for side in password-source password-destination password-runtime-source password-runtime-destination pgpass-source pgpass-destination preview.dump; do
      if [[ -e $RECOVERY_ROOT/$side ]]; then
        if private_file "$RECOVERY_ROOT/$side"; then rm -- "$RECOVERY_ROOT/$side" || failures=$((failures+1)); else failures=$((failures+1)); fi
      fi
    done
  fi
  printf 'CLEANUP: failures=%s; private cluster directories retained, no raw tool/server logs\n' "$failures"
  (( failures == 0 )) || exit 1
  exit "$original"
}
trap cleanup EXIT
for side in source destination runtime-source runtime-destination; do "${php[@]}" -r 'echo bin2hex(random_bytes(32));' > "$RECOVERY_ROOT/password-$side"; done
for side in source destination; do
  "$PG_BIN/initdb" -D "$RECOVERY_ROOT/$side" -U "recovery_$side" --pwfile="$RECOVERY_ROOT/password-$side" --auth-host=scram-sha-256 --auth-local=reject --no-locale --encoding=UTF8 >/dev/null 2>&1
  pins[$side]=$(cluster_id "$RECOVERY_ROOT/$side")
  [[ ${pins[$side]} =~ ^[1-9][0-9]+$ ]] || exit 2
done
export RECOVERY_SOURCE_CLUSTER="${pins[source]}" RECOVERY_DESTINATION_CLUSTER="${pins[destination]}"
[[ $RECOVERY_SOURCE_CLUSTER != "$RECOVERY_DESTINATION_CLUSTER" ]] || exit 2
for side in source destination; do
  port=55450; [[ $side == destination ]] && port=55451
  "$PG_BIN/pg_ctl" -D "$RECOVERY_ROOT/$side" -l /dev/null -o "-p $port -c listen_addresses=127.0.0.1 -c unix_socket_directories=''" -w start >/dev/null 2>&1
done
"${php[@]}" tests/preview-recovery.php | tee "$RECOVERY_ROOT/result.log"
grep -q '^RECOVERY OK:' "$RECOVERY_ROOT/result.log"
echo "Sanitized synthetic evidence at $RECOVERY_ROOT; not operational backup."
