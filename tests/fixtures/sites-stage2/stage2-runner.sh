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
[[ $uid =~ ^[1-9][0-9]*$ && $gid =~ ^[1-9][0-9]*$ ]] || die ARGS
if [[ $phase = --net-bootstrap ]]; then
    [[ $# = 4 ]] || die ARGS
else
    [[ $phase = --inside && $# = 6 && $5 =~ ^(0|[1-9][0-9]*)$ && $6 =~ ^(0|[1-9][0-9]*)$ &&
       $5 -ge 3 && $6 -ge 3 && $5 != "$6" ]] || die OBSERVER_FDS
fi
[[ $(readlink /proc/self/ns/net) != "$outside" ]] || die NETWORK_NAMESPACE
[[ $(ip -o link show | awk -F': ' '{print $2}') = lo ]] || die NETWORK_DEVICES
[[ -z $(ip -4 route show table main) && -z $(ip -6 route show table main) ]] || die ROUTES
if [[ $phase = --net-bootstrap ]]; then
    [[ $EUID = 0 ]] || die BOOTSTRAP_UID
    ip link set dev lo up
    # Keep only a read-only root witness in this already-created network namespace.
    # All PG/PHP execution remains under the exact unprivileged UID/GID.
    exec /usr/bin/python3 -I -B - "$self" "$uid" "$gid" "$outside" "$@" <<'PY'
import os, pathlib, re, select, signal, subprocess, sys
script, uid, gid, outside, bundle, portal, sites, driver = sys.argv[1:]
base = pathlib.Path(bundle) / 'root/usr/lib/postgresql/18/bin/postgres'
try:
    root_cap = next(line.split()[1] for line in pathlib.Path('/proc/self/status').read_text().splitlines()
                    if line.startswith('CapEff:'))
    if os.geteuid() != 0 or int(root_cap,16) & (1<<19) == 0:  # CAP_SYS_PTRACE
        sys.exit('STAGE2_NAMESPACE_WITNESS_REFUSED OBSERVER_CAPABILITY')
except (OSError,ValueError,StopIteration):
    sys.exit('STAGE2_NAMESPACE_WITNESS_REFUSED OBSERVER_STATUS')
ask_r, ask_w = os.pipe()
ack_r, ack_w = os.pipe()
command = [
    '/usr/bin/setpriv', '--reuid', uid, '--regid', gid, '--clear-groups', '--no-new-privs',
    '--bounding-set=-all', '--inh-caps=-all', '--ambient-caps=-all',
    '/usr/bin/bash', script, '--inside', uid, gid, outside, bundle, portal, sites, driver,
    str(ask_w), str(ack_r),
]
try:
    child = subprocess.Popen(command, stdin=subprocess.DEVNULL, pass_fds=(ask_w, ack_r))
except OSError:
    sys.exit('STAGE2_NAMESPACE_WITNESS_REFUSED OBSERVER_SPAWN')
os.close(ask_w); os.close(ack_r)
def forward(sig, _):
    if child.poll() is None:
        try: child.send_signal(sig)
        except ProcessLookupError: pass
signal.signal(signal.SIGTERM, forward)
signal.signal(signal.SIGINT, forward)
def demand(value, code):
    if not value: raise ValueError(code)
def live_start(pid):
    line = (pid / 'stat').read_text(encoding='ascii')
    fields = line.rsplit(') ', 1)[-1].split()
    demand(len(fields) > 19 and fields[0] not in ('Z','X','x')
           and fields[1] == str(child.pid) and fields[19].isdigit(),'CHILD')
    return fields[19]
ok = False
try:
    demand(os.geteuid() == 0,'OBSERVER_IDENTITY')
    ready, _, _ = select.select((ask_r,), (), (), 40)
    demand(bool(ready),'WITNESS_TIMEOUT')
    data = os.read(ask_r,32)
    demand(re.fullmatch(rb'[1-9][0-9]{0,8}\n',data) is not None,'WITNESS_REQUEST')
    pid = pathlib.Path('/proc') / data.strip().decode('ascii')
    start = live_start(pid)
    status = dict(line.split(':',1) for line in (pid/'status').read_text(encoding='ascii').splitlines() if ':' in line)
    demand(status['Uid'].split()==[uid]*4 and status['Gid'].split()==[gid]*4
           and status['NoNewPrivs'].strip()=='1'
           and all(status[k].strip()=='0000000000000000' for k in ('CapEff','CapPrm','CapInh','CapBnd','CapAmb')),
           'WORKER_SEAL')
    demand(base.resolve(strict=True)==base and os.readlink(pid/'exe')==str(base),'POSTGRES_EXE')
    own_ns = os.readlink('/proc/self/ns/net')
    target_ns = os.readlink(pid/'ns/net')
    demand(re.fullmatch(r'net:\[[1-9][0-9]*\]',own_ns) is not None
           and own_ns!=outside and target_ns==own_ns,'NAMESPACE_MISMATCH')
    demand(live_start(pid)==start,'CHILD_CHANGED')
    os.write(ack_w,b'OBSERVED\n')
    ok = True
except ValueError as e:
    # Only fixed internal gate codes can reach this output.
    code = e.args[0] if e.args and e.args[0] in {
        'OBSERVER_IDENTITY','WITNESS_TIMEOUT','WITNESS_REQUEST','CHILD',
        'WORKER_SEAL','POSTGRES_EXE','NAMESPACE_MISMATCH','CHILD_CHANGED',
    } else 'OBSERVER_SHAPE'
    print('STAGE2_NAMESPACE_WITNESS_REFUSED '+code,file=sys.stderr,flush=True)
except (OSError,KeyError,IndexError):
    print('STAGE2_NAMESPACE_WITNESS_REFUSED PROC_READ',file=sys.stderr,flush=True)
finally:
    os.close(ask_r); os.close(ack_w)
rc=child.wait()
sys.exit(rc if rc>0 else (1 if rc or not ok else 0))
PY
fi
[[ $EUID = "$uid" && $(id -g) = "$gid" &&
   $(awk '/^CapEff:/{print $2}' /proc/self/status) = 0000000000000000 ]] || die DROPPED_UID
[[ $(ip -o -4 addr show dev lo scope host | awk '{print $4}') = 127.0.0.1/8 ]] || die LOOPBACK
bundle=$1; portal=$2; sites=$3; driver=$4; ask_fd=$5; ack_fd=$6
(cd "$bundle" && sha256sum --check --strict --status SHA256SUMS) || die RUNTIME_HASH
B=$bundle/root/usr/lib/postgresql/18/bin
PGLIB=$bundle/root/usr/lib/x86_64-linux-gnu
pg() { LD_LIBRARY_PATH="$PGLIB" "$B/$1" "${@:2}"; }
PHP=/usr/bin/php8.3
export PHP_INI_SCAN_DIR=
[[ -x $PHP ]] || die PHP_EXECUTABLE
for x in initdb pg_ctl psql postgres pg_controldata pg_isready; do [[ -x $B/$x ]] || die EXECUTABLE; done
[[ $(pg postgres --version) = 'postgres (PostgreSQL) 18.7'* ]] || die PG_VERSION
[[ $("$PHP" -c "$bundle/php.ini" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,"|",in_array("pgsql",PDO::getAvailableDrivers(),true)?"yes":"no";') = 8.3'|'yes ]] || die PHP_VERSION_PDO
run=$(mktemp -d /tmp/sites-stage2-XXXXXXXX)
export HOME=$run TMPDIR=$run PHP_INI_SCAN_DIR=
data=$run/pgdata; mkdir -m 700 "$run/socket"
pg_pid=''
cleanup() {
    rc=$?; trap - EXIT; trap '' INT TERM
    if [[ -n "$pg_pid" ]] && kill -0 "$pg_pid" 2>/dev/null; then
        [[ $(/usr/bin/ps -o ppid= -p "$pg_pid" | tr -d '[:space:]') = "$BASHPID" ]] || die CLEANUP_CHILD
        if [[ -f $data/postmaster.pid && ! -L $data/postmaster.pid && $(sed -n '1p' "$data/postmaster.pid") = "$pg_pid" ]]; then
            pg pg_ctl -D "$data" -m immediate -w -t 15 stop >"$run/stop.log" 2>&1 || die CLEANUP
        else
            # Partial launch only: signal no process but the exact owned direct child.
            kill -TERM "$pg_pid" || die CLEANUP_SIGNAL
        fi
        for ((attempt=0;attempt<150;attempt++)); do
            kill -0 "$pg_pid" 2>/dev/null || break
            sleep .1
        done
        ! kill -0 "$pg_pid" 2>/dev/null || die CLEANUP_ALIVE
    fi
    if [[ -n "$pg_pid" ]]; then wait "$pg_pid" 2>/dev/null || :; fi
    [[ $run =~ ^/tmp/sites-stage2-[A-Za-z0-9]+$ && ! -L $run ]] || die CLEANUP_PATH
    rm -rf -- "$run" || die CLEANUP_REMOVE
    printf 'STAGE2_RUNNER_CLEANUP_OK\n'
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
(
    # Do not leak witness pipes to the PostgreSQL process or its backends.
    exec {ask_fd}>&- {ack_fd}<&-
    export LD_LIBRARY_PATH="$PGLIB"
    exec "$B/postgres" -D "$data" -p "$port" -c timezone=UTC -c listen_addresses=127.0.0.1 \
      -c "unix_socket_directories=$run/socket" -c unix_socket_permissions=0700
) >"$run/postgres.log" 2>&1 &
pg_pid=$!
ready=false
for ((attempt=0;attempt<100;attempt++)); do
    kill -0 "$pg_pid" 2>/dev/null || die PG_START
    if pg pg_isready -q -t 1 -h 127.0.0.1 -p "$port"; then ready=true; break; fi
    sleep .2
done
$ready || die PG_START
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
[[ "$pid" = "$pg_pid" ]] || die PG_CHILD_PID
printf '%s\n' "$pid" >&"$ask_fd" || die PG_NAMESPACE_REQUEST
IFS= read -r -t 8 -u "$ack_fd" observed && [[ "$observed" = OBSERVED ]] || die PG_NAMESPACE
exec {ask_fd}>&- {ack_fd}<&-
printf 'STAGE2_LIVE_PG_NAMESPACE_OK\n'
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
