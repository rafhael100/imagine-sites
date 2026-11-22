#!/usr/bin/env bash
# Internal caller: pinned Ubuntu 24.04 GitHub-hosted run admitted by outer workflow.
# Usage only after exact outer admission: ONEPAGE_TERMINAL_RUNTIME_DIR=/absolute/verified runner.sh
# Bundle: PGDG dpkg-deb extraction in root/, reviewed php.ini, SHA256SUMS.
# Workflow first attests ownership/calculated subset/paths of SHA256SUMS against external trust.
set -Eeuo pipefail
shopt -s inherit_errexit
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
self=$(/usr/bin/realpath -e -- "$0")
die() { printf 'ONEPAGE_TERMINAL_RUNNER_REFUSED %s\n' "$1" >&2; exit 1; }

if [[ ${1-} != --inside && ${1-} != --net-bootstrap && ${1-} != --signer-bootstrap ]]; then
    [[ $# = 0 && $EUID != 0 && ! -v DOCKER_HOST && ! -v DOCKER_CONTEXT ]] || die CALLER
    [[ $(grep -cx 'ID=ubuntu' /etc/os-release) = 1 &&
       $(grep -cx 'VERSION_ID="24.04"' /etc/os-release) = 1 &&
       $(uname -m) = x86_64 ]] || die PLATFORM
    dir=${self%/*}
    driver=$dir/entrypoint.php
    [[ -f $dir/scenario.php && ! -L $dir/scenario.php && -f $dir/TlsAdmission.php &&
       -f $dir/Stage3ReceiptChannel.php ]] || die SCENARIO_UNAVAILABLE
    set -- "${ONEPAGE_TERMINAL_RUNTIME_DIR:?}" "${ONEPAGE_TERMINAL_SITES_ROOT:?}" "$driver"
    for x in "$@"; do [[ $x = /* && $(realpath -e -- "$x") = "$x" ]] || die PATH; done
    exec sudo -n /usr/bin/env -i PATH="$PATH" LC_ALL=C /usr/bin/bash "$self" \
      --signer-bootstrap "$EUID" "$(id -g)" "$(readlink /proc/self/ns/net)" "$@"
fi
if [[ $1 = --signer-bootstrap ]]; then
    [[ $EUID = 0 && $# = 7 && $(readlink /proc/self/ns/net) = "$4" ]] || die SIGNER_SCOPE
    shift
    exec /usr/bin/python3 -I -B - "$self" "$@" <<'SIGNER'
import base64, importlib.util, json, os, pathlib, re, signal, socket, subprocess, sys
script, uid, gid, outside, bundle, sites, entry = sys.argv[1:]
module = pathlib.Path(script).with_name('owned_receipt.py')
spec = importlib.util.spec_from_file_location('owned_receipt', module)
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)
def require(ok):
    if not ok: raise RuntimeError('SIGNER_REFUSED')
def receive(sock):
    chunks = bytearray()
    while not chunks.endswith(b'\n') and len(chunks) < 4096:
        b = sock.recv(4096-len(chunks))
        if not b: break
        chunks.extend(b)
    require(bool(chunks) and chunks.endswith(b'\n') and chunks.count(b'\n') == 1)
    return json.loads(chunks)
parent, peer = socket.socketpair(socket.AF_UNIX,socket.SOCK_STREAM)
parent.settimeout(45)
child = subprocess.Popen(['/usr/bin/unshare','--net','--','/usr/bin/bash',script,
    '--net-bootstrap',uid,gid,outside,bundle,sites,entry,str(peer.fileno())],
    pass_fds=(peer.fileno(),),stdin=subprocess.DEVNULL)
peer.close()
def terminated(sig, frame): raise RuntimeError('SIGNER_INTERRUPTED')
signal.signal(signal.SIGTERM,terminated)
signal.signal(signal.SIGINT,terminated)
ok = False
try:
    message = receive(parent)
    require(type(message) is dict and set(message) == {'body','nonce'} and child.poll() is None
            and os.readlink('/proc/self/ns/net') == outside
            and os.readlink(f'/proc/{child.pid}/ns/net') != outside)
    raw = base64.b64decode(message['body'],validate=True)
    nonce = message['nonce']
    receipt = json.loads(raw)
    require(type(nonce) is str and re.fullmatch('[a-f0-9]{64}',nonce) and receipt.get('nonce') == nonce
            and receipt.get('schema') == 'sites-onepage-terminal-tls-witness.v1'
            and receipt.get('netns') == os.readlink(f'/proc/{child.pid}/ns/net'))
    # Secret memfd and OpenSSL only exist here, in the original netns as root.
    a,b = m.sign_frames(raw,nonce)
    parent.sendall(json.dumps([base64.b64encode(a).decode(),base64.b64encode(b).decode()],
                              separators=(',',':')).encode()+b'\n')
    parent.close()
    ok = child.wait(timeout=120) == 0
except Exception:
    print('ONEPAGE_TERMINAL_SIGNER_REFUSED',file=sys.stderr,flush=True)
finally:
    parent.close()
    if child.poll() is None:
        child.terminate()
        try: child.wait(timeout=45)
        except subprocess.TimeoutExpired:
            child.kill()
            child.wait(timeout=5)
sys.exit(0 if ok else 1)
SIGNER
fi
phase=$1; uid=$2; gid=$3; outside=$4; shift 4
[[ $uid =~ ^[1-9][0-9]*$ && $gid =~ ^[1-9][0-9]*$ ]] || die ARGS
if [[ $phase = --net-bootstrap ]]; then
    [[ $# = 4 ]] || die ARGS
else
    [[ $phase = --inside && $# = 5 && $3 =~ ^[1-9][0-9]{0,5}$ && $4 =~ ^[1-9][0-9]{0,5}$ &&
       $3 -ge 3 && $4 -ge 3 && $3 != "$4" ]] || die OBSERVER_FDS
fi
[[ $(readlink /proc/self/ns/net) != "$outside" ]] || die NETWORK_NAMESPACE
[[ $(ip -o link show | awk -F': ' '{print $2}') = lo ]] || die NETWORK_DEVICES
[[ -z $(ip -4 route show table main) && -z $(ip -6 route show table main) ]] || die ROUTES
if [[ $phase = --net-bootstrap ]]; then
    [[ $EUID = 0 ]] || die BOOTSTRAP_UID
    ip link set dev lo up
    # Keep only the root observer and launcher in this already-created network namespace.
    # All PG/PHP execution remains under the exact unprivileged UID/GID.
    exec /usr/bin/python3 -I -B - "$self" "$uid" "$gid" "$outside" "$@" <<'PY'
# Stage 3 splice: exact #589 privilege drop, owned direct-child check and teardown.
import base64, fcntl, importlib.util, json, socket, os, pathlib, re, select, signal, stat, subprocess, sys
script, uid, gid, outside, bundle, sites, driver, signer_fd = sys.argv[1:]
script = pathlib.Path(script)
bin_dir = pathlib.Path(bundle) / 'root/usr/lib/postgresql/18/bin'
base = bin_dir / 'postgres'
port = 55449
signer = socket.socket(fileno=int(signer_fd))
signer.set_inheritable(False)
signer.settimeout(10)
drop = ['/usr/bin/setpriv','--reuid',uid,'--regid',gid,'--clear-groups','--no-new-privs',
        '--bounding-set=-all','--inh-caps=-all','--ambient-caps=-all']
sterile = {'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C'}
def need(ok, phase):
    if not ok: raise RuntimeError(phase)
def read_uid_mode(path, kind, mode):
    s = path.lstat()
    need(kind(s.st_mode) and (s.st_mode & 0o7777) == mode
         and s.st_uid == int(uid) and s.st_gid == int(gid), 'SCRATCH')
def fixed_bytes(cmd, env, bound, seconds=6):
    r = subprocess.run(drop+cmd, env=env, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=seconds)
    need(r.returncode == 0 and 0 < len(r.stdout) <= bound, 'FIXED_PROCESS')
    return r.stdout
def fd_pipe():
    r,w=os.pipe2(os.O_CLOEXEC)
    a,b=os.fstat(r),os.fstat(w)
    for fd,s,mode in ((r,a,os.O_RDONLY),(w,b,os.O_WRONLY)):
        need(fd>=3 and stat.S_ISFIFO(s.st_mode) and s.st_uid==0
             and fcntl.fcntl(fd,fcntl.F_GETFL)&os.O_ACCMODE==mode
             and os.readlink(f'/proc/self/fd/{fd}')==f'pipe:[{s.st_ino}]','PIPE')
    need((a.st_dev,a.st_ino)==(b.st_dev,b.st_ino),'PIPE')
    return r,w
def recv_line(fd, size, seconds):
    need(bool(select.select((fd,),(),(),seconds)[0]),'PHASE_TIMEOUT')
    data=os.read(fd,size+1)
    need(0<len(data)<=size and data.endswith(b'\n') and data.count(b'\n')==1,'PHASE_FRAME')
    return data
def send(fd, data):
    need(len(data)<=os.fpathconf(fd,'PC_PIPE_BUF') and os.write(fd,data)==len(data),'PHASE_WRITE')
def finish(p, max_wait):
    try: return p.wait(timeout=max_wait)
    except subprocess.TimeoutExpired:
        p.terminate()
        try: p.wait(timeout=32)
        except subprocess.TimeoutExpired:
            p.kill()
            p.wait(timeout=3)
        raise RuntimeError('TERMINAL_TIMEOUT')
try:
    root_cap = next(line.split()[1] for line in pathlib.Path('/proc/self/status').read_text().splitlines()
                    if line.startswith('CapEff:'))
    need(os.geteuid()==0 and int(root_cap,16)&(1<<19)!=0,'OBSERVER_CAPABILITY')
except (OSError,ValueError,StopIteration,RuntimeError):
    sys.exit('ONEPAGE_TERMINAL_NAMESPACE_WITNESS_REFUSED OBSERVER_CAPABILITY')

# Installed side-by-side ONLY through independently exact-pinned outer review;
# these are the previously reviewed modules, not a module path from any request.
modpath=script.with_name('owned_receipt.py')
spec=importlib.util.spec_from_file_location('owned_receipt', modpath)
m=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=m
spec.loader.exec_module(m)
run=pathlib.Path(subprocess.check_output(['/usr/bin/mktemp','-d','/tmp/sites-release-registry-XXXXXXXX'],
                 env=sterile,stdin=subprocess.DEVNULL,timeout=5).decode('ascii').strip())
child=php=None
fds=set()
stage='SPAWN'
ok=False
try:
    need(re.fullmatch(r'/tmp/sites-release-registry-[A-Za-z0-9]{8,32}',str(run)) is not None,'SCRATCH')
    os.chown(run,int(uid),int(gid))
    read_uid_mode(run,stat.S_ISDIR,0o700)
    data_dir=run/'data'
    ask_r,ask_w=fd_pipe(); ack_r,ack_w=fd_pipe()
    fds.update((ask_r,ask_w,ack_r,ack_w))
    def close_fd(fd):
        os.close(fd); fds.remove(fd)
    command=drop+['/usr/bin/bash',str(script),'--inside',uid,gid,outside,
                  bundle,driver,str(ask_w),str(ack_r),str(run)]
    child=subprocess.Popen(command,stdin=subprocess.DEVNULL,pass_fds=(ask_w,ack_r),env=sterile)
    close_fd(ask_w); close_fd(ack_r)
    def forward(sig, _):
        for p in (php,child):
            if p is not None and p.poll() is None:
                p.send_signal(sig)
        raise SystemExit(130 if sig==signal.SIGINT else 143)
    signal.signal(signal.SIGTERM,forward)
    signal.signal(signal.SIGINT,forward)
    stage='BEFORE_CREATE_DATABASE'
    msg=recv_line(ask_r,10,40)
    need(re.fullmatch(rb'[1-9][0-9]{0,8}\n',msg) is not None,'PROCESS_REQUEST')
    pgpid=int(msg)
    proc=pathlib.Path('/proc')/str(pgpid)
    def live_start():
        line=(proc/'stat').read_text(encoding='ascii')
        fields=line.rsplit(') ',1)[-1].split()
        need(child.poll() is None and len(fields)>19 and fields[0] not in ('Z','X','x')
             and fields[1]==str(child.pid) and fields[19].isdigit(),'CHILD')
        return fields[19]
    def pid_fields():
        read_uid_mode(run,stat.S_ISDIR,0o700)
        read_uid_mode(data_dir,stat.S_ISDIR,0o700)
        pid_file=data_dir/'postmaster.pid'
        # PostgreSQL 18 writes postmaster.pid as 0600 in this fresh initdb.
        read_uid_mode(pid_file,stat.S_ISREG,0o600)
        rows=pid_file.read_text(encoding='ascii').splitlines()
        need(len(rows)>=6 and rows[0]==str(pgpid) and rows[1]==str(data_dir)
             and re.fullmatch(r'[1-9][0-9]+',rows[2]) is not None and rows[3]==str(port)
             and rows[4]=='' and rows[5]=='127.0.0.1','PID_FILE')
        return int(rows[2])
    birth=live_start()
    own_ns=os.readlink('/proc/self/ns/net')
    postmaster_epoch=pid_fields()
    def assert_owned(owned):
        need(live_start()==birth,'CHILD_CHANGED')
        status=dict(line.split(':',1) for line in (proc/'status').read_text(encoding='ascii').splitlines() if ':' in line)
        need(status['Uid'].split()==[uid]*4 and status['Gid'].split()==[gid]*4
             and status['NoNewPrivs'].strip()=='1'
             and all(status[k].strip()=='0000000000000000' for k in ('CapEff','CapPrm','CapInh','CapBnd','CapAmb')),
             'WORKER_SEAL')
        need(base.resolve(strict=True)==base and os.readlink(proc/'exe')==str(base),'POSTGRES_EXE')
        need(re.fullmatch(r'net:\[[1-9][0-9]*\]',own_ns) is not None and own_ns!=outside
             and os.readlink('/proc/self/ns/net')==os.readlink(proc/'ns/net')==owned.namespace==own_ns,
             'NAMESPACE_MISMATCH')
        cmd=(proc/'cmdline').read_bytes().split(b'\0')
        need(cmd==[os.fsencode(x) for x in (base,'-D',data_dir,'-p',str(port),'-c','timezone=UTC',
              '-c','listen_addresses=127.0.0.1','-c','unix_socket_directories=',
              '-c','ssl=on','-c',f'ssl_cert_file={run}/tls/server.pem','-c',f'ssl_key_file={run}/tls/server.key','')],'POSTGRES_CMDLINE')
        need(pid_fields()==postmaster_epoch and live_start()==birth,'PROCESS_CHANGED')
        return True
    # Bootstrap data path selected and chowned by the supervisor before the
    # unprivileged child starts. No path/port/role/cluster evidence in requests.
    pg_env=sterile|{'HOME':str(run),'LD_LIBRARY_PATH':bundle+'/root/usr/lib/x86_64-linux-gnu'}
    disk=fixed_bytes([str(bin_dir/'pg_controldata'),str(data_dir)],pg_env,32768)
    ids=re.findall(rb'^Database system identifier:\s+([1-9][0-9]{14,19})$',disk,re.M)
    need(len(ids)==1,'DISK_IDENTIFIER')
    import hashlib
    read_uid_mode(run/'tls',stat.S_ISDIR,0o700)
    read_uid_mode(run/'tls'/'ca.pem',stat.S_ISREG,0o600)
    cert=(run/'tls'/'ca.pem').read_bytes()
    need(128<=len(cert)<=8192,'CA_BYTES')
    owned=m.OwnedPostmaster(pgpid,own_ns,str(data_dir),port,ids[0].decode(),postmaster_epoch,
                            hashlib.sha256(cert).hexdigest())
    def sign_outside(body,nonce):
        q={'body':base64.b64encode(body).decode(),'nonce':nonce}
        signer.sendall(json.dumps(q,separators=(',',':')).encode()+b'\n')
        raw=bytearray()
        while not raw.endswith(b'\n') and len(raw)<4096:
            chunk=signer.recv(4096-len(raw))
            if not chunk: break
            raw.extend(chunk)
        need(raw.endswith(b'\n') and raw.count(b'\n')==1,'SIGNER_FRAME')
        encoded=json.loads(raw)
        need(type(encoded) is list and len(encoded)==2,'SIGNER_FRAME')
        signer.close()
        return tuple(base64.b64decode(x,validate=True) for x in encoded)
    def maintenance_read(query):
        need(query==m.MAINTENANCE_SQL,'MAINTENANCE_QUERY')
        read_uid_mode(run/'pgpass',stat.S_ISREG,0o600)
        env=pg_env|{'PGPASSFILE':str(run/'pgpass'),'PGSSLMODE':'verify-full','PGSSLROOTCERT':str(run/'tls'/'ca.pem'),'PGCONNECT_TIMEOUT':'3',
                    'PGOPTIONS':'-c default_transaction_read_only=on -c statement_timeout=3000'}
        return json.loads(fixed_bytes([str(bin_dir/'psql'),'-X','-qAt','-w',
            '-h','127.0.0.1','-p',str(port),'-U',m.ADMIN,'-d','postgres','-v','ON_ERROR_STOP=1',
            '-c',query],env,4096))
    need(assert_owned(owned),'POSTMASTER')
    inode=m.inspect_listener(proc,owned)
    pre=maintenance_read(m.MAINTENANCE_SQL)
    m.check_maintenance(owned,pre)
    need(type(pre['oids']) is dict and set(pre['oids'])=={'postgres'},'DB_OID')
    need(assert_owned(owned) and m.inspect_listener(proc,owned)==inode,'PROCESS_CHANGED')
    print('ONEPAGE_TERMINAL_ROOT_PRESCHEMA_OK',flush=True)
    close_fd(ask_r)
    ctl_r,ctl_w=fd_pipe(); body_r,body_w=fd_pipe()
    fds.update((ctl_r,ctl_w,body_r,body_w))
    read_uid_mode(run/'password',stat.S_ISREG,0o600)
    child_env=sterile|{'HOME':str(run),'TMPDIR':str(run),'PHP_INI_SCAN_DIR':''}
    php=subprocess.Popen(drop+['/usr/bin/timeout','--foreground','--kill-after=3s','75s',
        '/usr/bin/php8.3','-c',bundle+'/php.ini','-d','display_errors=0','-d','log_errors=0',
        '-f',driver,'--',str(run),outside,str(ctl_r),str(body_r),sites],
        stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,pass_fds=(ctl_r,body_r),env=child_env)
    close_fd(ctl_r); close_fd(body_r)
    # Emitter owns/removes both writes even on denial. Validate exact original
    # cluster + the single Portal SQL OID, and send a signed pre-schema witness.
    fds.remove(ctl_w); fds.remove(body_w)
    m.ReceiptEmitter().send(owned,assert_owned,maintenance_read,ctl_w,body_w,sign_outside)
    stage='PHP_TERMINAL'
    need(finish(php,81)==0,'PHP_EXIT')
    approvals = (b'ONEPAGE_TERMINAL_PDO_ADMITTED_OK\n'
                 b'ONEPAGE_TERMINAL_ACCEPT_FIRST_LATER_VETO_OK\n')
    need(php.stdout is not None and php.stdout.read(len(approvals)+1)==approvals,'PHP_MARKERS')
    php.stdout.close()
    need(assert_owned(owned) and m.inspect_listener(proc,owned)==inode,'POSTMASTER_AFTER_PHP')
    sys.stdout.write(approvals.decode('ascii'))
    sys.stdout.flush()
    send(ack_w,b'SLOTS_OK\n')
    close_fd(ack_w)
    stage='RUNNER_CLEANUP'
    need(finish(child,46)==0 and not os.path.lexists(run),'RUNNER_EXIT')
    ok=True
except Exception:
    print('ONEPAGE_TERMINAL_NAMESPACE_WITNESS_REFUSED '+stage,file=sys.stderr,flush=True)
finally:
    for fd in fds:
        os.close(fd)
    # Keep the original Bash EXIT cleanup as owner of the PG child. Never
    # report success after abnormal exit or remove an ambiguous directory.
    for p in (php,child):
        if p is not None and p.poll() is None:
            p.terminate()
            try: finish(p,35)
            except Exception: pass
    if child is None:
        try: run.rmdir()
        except OSError: pass
if ok:
    print('ONEPAGE_TERMINAL_VALIDATION_AND_CLEANUP_OK',flush=True)
sys.exit(0 if ok else 1)
PY
fi
[[ $EUID = "$uid" && $(id -g) = "$gid" &&
   $(awk '/^CapEff:/{print $2}' /proc/self/status) = 0000000000000000 ]] || die DROPPED_UID
[[ $(ip -o -4 addr show dev lo scope host | awk '{print $4}') = 127.0.0.1/8 ]] || die LOOPBACK
bundle=$1; driver=$2; ask_fd=$3; ack_fd=$4; run=$5
(cd "$bundle" && sha256sum --check --strict --status SHA256SUMS) || die RUNTIME_HASH
B=$bundle/root/usr/lib/postgresql/18/bin
PGLIB=$bundle/root/usr/lib/x86_64-linux-gnu
pg() { LD_LIBRARY_PATH="$PGLIB" "$B/$1" "${@:2}"; }
PHP=/usr/bin/php8.3
export PHP_INI_SCAN_DIR=
[[ -x $PHP ]] || die PHP_EXECUTABLE
for x in initdb pg_ctl psql postgres pg_controldata pg_isready; do [[ -x $B/$x ]] || die EXECUTABLE; done
[[ $(pg postgres --version) = 'postgres (PostgreSQL) 18.7'* ]] || die PG_VERSION
[[ $("$PHP" -c "$bundle/php.ini" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,"|",(in_array("pgsql",PDO::getAvailableDrivers(),true)&&function_exists("sodium_crypto_sign_verify_detached")&&function_exists("posix_geteuid"))?"yes":"no";') = 8.3'|'yes ]] || die PHP_VERSION_PDO
[[ $run =~ ^/tmp/sites-release-registry-[A-Za-z0-9]{8,32}$ && ! -L $run && -O $run && -d $run ]] || die SCRATCH
export HOME=$run TMPDIR=$run PHP_INI_SCAN_DIR=
data=$run/data; mkdir -m 700 "$run/tls"
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
    [[ $run =~ ^/tmp/sites-release-registry-[A-Za-z0-9]+$ && ! -L $run ]] || die CLEANUP_PATH
    rm -rf -- "$run" || die CLEANUP_REMOVE
    printf 'ONEPAGE_TERMINAL_CLEANUP_OK\n'
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
openssl rand -hex 32 | tr -d '\n' >"$run/password"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Sites Terminal Fixture CA' \
  -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -keyout "$run/tls/ca.key" -out "$run/tls/ca.pem" >"$run/tls/generate.log" 2>&1 || die TLS_CA
openssl req -new -newkey rsa:2048 -nodes -subj '/CN=localhost' \
  -keyout "$run/tls/server.key" -out "$run/tls/server.csr" >>"$run/tls/generate.log" 2>&1 || die TLS_KEY
printf '%s\n' 'subjectAltName=DNS:localhost,IP:127.0.0.1' 'extendedKeyUsage=serverAuth' \
  'basicConstraints=critical,CA:FALSE' >"$run/tls/ext"
openssl x509 -req -days 1 -in "$run/tls/server.csr" -CA "$run/tls/ca.pem" -CAkey "$run/tls/ca.key" \
  -CAcreateserial -extfile "$run/tls/ext" -out "$run/tls/server.pem" >>"$run/tls/generate.log" 2>&1 || die TLS_SIGN
chmod 0600 "$run/tls/ca.pem" "$run/tls/server.key"
rm -- "$run/tls/ca.key"
pg initdb -D "$data" -U sites_terminal_fixture_bootstrap --encoding=UTF8 --no-locale \
    --auth-local=reject --auth-host=scram-sha-256 --pwfile="$run/password" >"$run/init.log" 2>&1 || die INITDB
# Place name/port in this namespace only. Test inputs may not override startup.
port=55449
(
    # Do not leak witness pipes to the PostgreSQL process or its backends.
    exec {ask_fd}>&- {ack_fd}<&-
    export LD_LIBRARY_PATH="$PGLIB"
    exec "$B/postgres" -D "$data" -p "$port" -c timezone=UTC -c listen_addresses=127.0.0.1 \
      -c unix_socket_directories= -c ssl=on -c "ssl_cert_file=$run/tls/server.pem" -c "ssl_key_file=$run/tls/server.key"
) >"$run/postgres.log" 2>&1 &
pg_pid=$!
ready=false
for ((attempt=0;attempt<100;attempt++)); do
    kill -0 "$pg_pid" 2>/dev/null || die PG_START
    if pg pg_isready -q -t 1 -h 127.0.0.1 -p "$port"; then ready=true; break; fi
    sleep .2
done
$ready || die PG_START
printf '127.0.0.1:%s:*:sites_terminal_fixture_bootstrap:%s\n' "$port" "$(cat "$run/password")" >"$run/pgpass"
sql() {
    PGPASSFILE="$run/pgpass" PGCONNECT_TIMEOUT=5 PGSSLMODE=verify-full PGSSLROOTCERT="$run/tls/ca.pem" pg psql -X -qAt -w -h 127.0.0.1 -p "$port" -U sites_terminal_fixture_bootstrap -d postgres -v ON_ERROR_STOP=1 "$@"
}
[[ $(sql -c "SELECT host(inet_server_addr())||'|'||current_user||'|'||current_database()||'|'||current_setting('listen_addresses')") = '127.0.0.1|sites_terminal_fixture_bootstrap|postgres|127.0.0.1' ]] || die PG_IDENTITY
[[ $(sql -c "SELECT current_setting('data_directory')") = "$data" ]] || die PGDATA
[[ $(sql -c "SELECT count(*) FROM pg_available_extensions WHERE name='pgcrypto'") = 1 ]] || die PGCRYPTO
disk_id=$(pg pg_controldata "$data" | awk '/^Database system identifier:/{print $NF}')
pid=$(sed -n '1p' "$data/postmaster.pid")
pid_epoch=$(sed -n '3p' "$data/postmaster.pid")
[[ $disk_id =~ ^[0-9]{15,22}$ && $pid =~ ^[1-9][0-9]*$ && $pid_epoch =~ ^[1-9][0-9]+$ ]] || die DISK_ID
[[ "$pid" = "$pg_pid" ]] || die PG_CHILD_PID
printf '%s\n' "$pid" >&"$ask_fd" || die PG_NAMESPACE_REQUEST
exec {ask_fd}>&-
IFS= read -r -t 105 -u "$ack_fd" observed && [[ "$observed" = SLOTS_OK ]] || die SLOTS_ACK
exec {ack_fd}<&-
