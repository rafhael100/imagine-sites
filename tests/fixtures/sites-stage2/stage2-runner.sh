#!/usr/bin/env bash
# Internal caller: pinned Ubuntu 24.04 GitHub-hosted run admitted by outer workflow.
# Usage: STAGE2_RUNTIME_DIR=/absolute/verified stage2-runner.sh PORTAL_ROOT SITES_ROOT
# Bundle: PGDG dpkg-deb extraction in root/, reviewed php.ini, SHA256SUMS.
# Workflow first attests ownership/calculated subset/paths of SHA256SUMS against external trust.
set -Eeuo pipefail
shopt -s inherit_errexit
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
self=$(/usr/bin/realpath -e -- "$0")
die() { printf 'STAGE2_RUNNER_REFUSED %s\n' "$1" >&2; exit 1; }

if [[ ${1-} != --inside && ${1-} != --net-bootstrap ]]; then
    [[ $# = 2 && $EUID != 0 && ! -v DOCKER_HOST && ! -v DOCKER_CONTEXT ]] || die CALLER
    [[ $(/usr/bin/grep -cx 'ID=ubuntu' /etc/os-release) = 1 &&
       $(/usr/bin/grep -cx 'VERSION_ID="24.04"' /etc/os-release) = 1 &&
       $(/usr/bin/uname -m) = x86_64 ]] || die PLATFORM
    driver=${self%/*}/stage2-driver.php
    set -- "${STAGE2_RUNTIME_DIR:?missing verified bundle}" "$1" "$2" "$driver"
    for x in "$@"; do [[ $x = /* && $(realpath -e -- "$x") = "$x" ]] || die PATH; done
    exec sudo -n /usr/bin/env -i PATH="$PATH" LC_ALL=C /usr/bin/unshare --net -- \
        /usr/bin/bash "$self" --net-bootstrap "$EUID" "$(id -g)" "$(readlink /proc/self/ns/net)" "$@"
fi
phase=$1; uid=$2; gid=$3; outside=$4; shift 4
[[ $# = 4 && $uid =~ ^[1-9][0-9]*$ && $gid =~ ^[1-9][0-9]*$ ]] || die ARGS
[[ $(readlink /proc/self/ns/net) != "$outside" ]] || die NETWORK_NAMESPACE
[[ $(ip -o link show | awk -F': ' '{print $2}') = lo ]] || die NETWORK_DEVICES
[[ -z $(ip -4 route show table main) && -z $(ip -6 route show table main) ]] || die ROUTES
if [[ $phase = --net-bootstrap ]]; then
    [[ $EUID = 0 ]] || die BOOTSTRAP_UID
    ip link set dev lo up
    exec /usr/bin/setpriv --reuid "$uid" --regid "$gid" --clear-groups --no-new-privs \
        --bounding-set=-all --inh-caps=-all --ambient-caps=-all \
        /usr/bin/bash "$self" --inside "$uid" "$gid" "$outside" "$@"
fi
[[ $EUID = "$uid" && $(id -g) = "$gid" &&
   $(awk '/^CapEff:/{print $2}' /proc/self/status) = 0000000000000000 ]] || die DROPPED_UID
[[ $(ip -o -4 addr show dev lo scope host | awk '{print $4}') = 127.0.0.1/8 ]] || die LOOPBACK
bundle=$1; portal=$2; sites=$3; driver=$4
(cd "$bundle" && sha256sum --check --strict --status SHA256SUMS) || die RUNTIME_HASH
B=$bundle/root/usr/lib/postgresql/18/bin
PGLIB=$bundle/root/usr/lib/x86_64-linux-gnu
pg() { LD_LIBRARY_PATH="$PGLIB" "$B/$1" "${@:2}"; }
PHP=/usr/bin/php8.3
export PHP_INI_SCAN_DIR=
[[ -x $PHP ]] || die PHP_EXECUTABLE
for x in initdb pg_ctl psql postgres pg_controldata; do [[ -x $B/$x ]] || die EXECUTABLE; done
[[ $(pg postgres --version) = 'postgres (PostgreSQL) 18.7'* ]] || die PG_VERSION
[[ $("$PHP" -c "$bundle/php.ini" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,"|",in_array("pgsql",PDO::getAvailableDrivers(),true)?"yes":"no";') = 8.3'|'yes ]] || die PHP_VERSION_PDO
run=$(mktemp -d /tmp/sites-stage2-XXXXXXXX)
export HOME=$run TMPDIR=$run PHP_INI_SCAN_DIR=
data=$run/pgdata; mkdir -m 700 "$run/socket"
owned_started=false
cleanup() {
    rc=$?; trap - EXIT; trap '' INT TERM
    if $owned_started && [[ -f $data/postmaster.pid && ! -L $data/postmaster.pid ]]; then
        if ! pg pg_ctl -D "$data" -m immediate -w -t 15 stop >"$run/stop.log" 2>&1; then
            printf 'STAGE2_RUNNER_REFUSED CLEANUP\n' >&2
            exit 1
        fi
    fi
    [[ $run =~ ^/tmp/sites-stage2-[A-Za-z0-9]+$ && ! -L $run ]] || die CLEANUP_PATH
    rm -rf -- "$run"
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
openssl rand -hex 32 >"$run/admin.password"
pg initdb -D "$data" -U postgres --encoding=UTF8 --no-locale \
    --auth-local=scram-sha-256 --auth-host=scram-sha-256 --pwfile="$run/admin.password" >"$run/init.log" 2>&1 || die INITDB
# Place name/port in this namespace only. Test inputs may not override startup.
port=55482
owned_started=true
pg pg_ctl -D "$data" -l "$run/postgres.log" -w -t 20 \
    -o "-p $port -c timezone=UTC -c listen_addresses=127.0.0.1 -c unix_socket_directories=$run/socket -c unix_socket_permissions=0700" start \
    >"$run/start.log" 2>&1 || die PG_START
printf '127.0.0.1:%s:*:postgres:%s\n' "$port" "$(cat "$run/admin.password")" >"$run/pgpass"
sql() {
    PGPASSFILE="$run/pgpass" PGCONNECT_TIMEOUT=5 pg psql -X -qAt -w -h 127.0.0.1 -p "$port" -U postgres -d postgres -v ON_ERROR_STOP=1 "$@"
}
[[ $(sql -c "SELECT host(inet_server_addr())||'|'||current_user||'|'||current_database()||'|'||current_setting('listen_addresses')") = '127.0.0.1|postgres|postgres|127.0.0.1' ]] || die PG_IDENTITY
[[ $(sql -c "SELECT current_setting('data_directory')") = "$data" ]] || die PGDATA
[[ $(sql -c "SELECT count(*) FROM pg_available_extensions WHERE name='pgcrypto'") = 1 ]] || die PGCRYPTO
disk_id=$(pg pg_controldata "$data" | awk '/^Database system identifier:/{print $NF}')
pid=$(sed -n '1p' "$data/postmaster.pid")
pid_epoch=$(sed -n '3p' "$data/postmaster.pid")
[[ $disk_id =~ ^[0-9]{15,22}$ && $pid =~ ^[1-9][0-9]*$ && $pid_epoch =~ ^[1-9][0-9]+$ ]] || die DISK_ID
[[ $(readlink "/proc/$pid/ns/net") = $(readlink /proc/self/ns/net) ]] || die PG_NAMESPACE
[[ $(sql -c 'SELECT (pg_control_system()).system_identifier::text') = "$disk_id" ]] || die CLUSTER_ID
sql -c 'CREATE DATABASE portal_sites_stage2_ci TEMPLATE template0' >/dev/null || die PORTAL_DB
sql -c 'CREATE DATABASE sites_portal_stage2_ci TEMPLATE template0' >/dev/null || die SITES_DB
sql -c "SELECT json_build_object(
  'port',inet_server_port(),'dir',current_setting('data_directory'),
  'system_id',(pg_control_system()).system_identifier::text,
  'start',extract(epoch FROM pg_postmaster_start_time())::text,
  'oids',(SELECT json_object_agg(datname,oid::text) FROM pg_database
          WHERE datname IN ('portal_sites_stage2_ci','sites_portal_stage2_ci')))" >"$run/witness.json" || die WITNESS
/usr/bin/python3 -I -B - "$run/witness.json" "$data" "$port" "$disk_id" "$pid_epoch" <<'PY' || die WITNESS_FIELDS
import json, re, sys
from pathlib import Path
a = json.loads(Path(sys.argv[1]).read_bytes())
assert set(a) == {"port","dir","system_id","start","oids"}
assert a["port"] == int(sys.argv[3]) and a["dir"] == sys.argv[2]
assert a["system_id"] == sys.argv[4] and isinstance(a["start"],str) and re.fullmatch(r"[0-9]+[.][0-9]{6}",a["start"])
assert abs(int(a["start"].split(".")[0])-int(sys.argv[5])) <= 1
assert set(a["oids"]) == {"portal_sites_stage2_ci","sites_portal_stage2_ci"}
assert all(x.isdigit() for x in a["oids"].values()) and len(set(a["oids"].values())) == 2
PY
printf 'STAGE2_RUNNER_READY\n'
timeout --foreground --kill-after=5s 120s "$PHP" -c "$bundle/php.ini" "$driver" "$portal" "$sites" "$run/witness.json" || die DRIVER
