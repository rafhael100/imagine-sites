"""Private synthetic one-DB briefing v2 receipt. Not a runner or DB installer."""
import json
import os
import pathlib
import re
import secrets
import stat
import subprocess
import time
from dataclasses import dataclass

DATABASES = ('postgres',)
ADMIN = 'sites_terminal_fixture_bootstrap'
# Bind read-only verified psql to the already-qualified maintenance database in
# the supervising namespace. The trusted caller must provide its transport.
MAINTENANCE_SQL = """SELECT pg_catalog.json_build_object(
 'db',pg_catalog.current_database(),'login',session_user::text,'actor',current_user::text,
 'server_host',pg_catalog.host(pg_catalog.inet_server_addr()),
 'client_host',pg_catalog.host(pg_catalog.inet_client_addr()),
 'port',pg_catalog.inet_server_port(),
 'listen',pg_catalog.current_setting('listen_addresses'),
 'dir',pg_catalog.current_setting('data_directory'),
 'version',pg_catalog.current_setting('server_version_num'),
 'start',extract(epoch FROM pg_catalog.pg_postmaster_start_time())::text,
 'system_id',(SELECT system_identifier::text FROM pg_catalog.pg_control_system()),
 'recovery',pg_catalog.pg_is_in_recovery(),
 'tls',(SELECT ssl FROM pg_catalog.pg_stat_ssl WHERE pid=pg_catalog.pg_backend_pid()),
 'ssl_on',pg_catalog.current_setting('ssl'),
 'oids',(SELECT pg_catalog.json_object_agg(datname,oid::text)
         FROM pg_catalog.pg_database WHERE datname IN
           ('postgres')))"""
# OpenSSL Ed25519 public DER SPKI prefix (SEQUENCE algID 1.3.101.112 + 32-byte BIT STRING).
ED25519_SPKI = bytes.fromhex('302a300506032b6570032100')

class Refused(RuntimeError):
    pass

def need(ok, stage):
    if not ok:
        raise Refused('STAGE3_SUPERVISOR_REFUSED:' + stage)

@dataclass(frozen=True)
class OwnedPostmaster:
    """Trusted #589-style observer evidence, never populated from the app/pipe."""
    pid: int
    namespace: str
    data_dir: str
    port: int
    # Independently read from owned PGDATA with pg_controldata by outer runner.
    disk_system_id: str
    # Existing supervisor's owned postmaster.pid line 3 (not an SQL echo).
    postmaster_epoch: int
    # From the supervisor-owned freshly issued private CA, before opening psql.
    ca_sha256: str

def bounded_proc(path):
    with path.open('r', encoding='ascii') as proc:
        text = proc.read(262145)
    need(len(text) <= 262144, 'PROC_BOUND')
    return text

def tcp_listeners(text):
    lines = text.splitlines()
    need(bool(lines) and 'local_address' in lines[0] and 'rem_address' in lines[0].replace('remote_address','rem_address'),
         'PROC_HEADER')
    out = []
    for row in lines[1:]:
        fields = row.split()
        need(len(fields) >= 10 and re.fullmatch('[0-9A-F]{2}', fields[3]) is not None,'PROC_TCP_ROW')
        if fields[3] != '0A':
            continue
        need(re.fullmatch('[0-9A-F]{8,32}:[0-9A-F]{4}', fields[1]) is not None
             and re.fullmatch('[1-9][0-9]*', fields[9]) is not None, 'PROC_LISTEN_ROW')
        out.append((fields[1], fields[9]))
    return out

def match_listener(ipv4, ipv6, fd_links, port):
    v4 = tcp_listeners(ipv4)
    v6 = tcp_listeners(ipv6)
    # Fixed linux/amd64 proc ABI; 127.0.0.1 appears as 0100007F.
    need(not v6 and len(v4) == 1 and v4[0][0] == f'0100007F:{port:04X}', 'LISTENER_SCOPE')
    inode = v4[0][1]
    need('socket:[' + inode + ']' in fd_links, 'LISTENER_OWNER')
    return inode

def inspect_listener(proc, owned):
    need(os.readlink('/proc/self/ns/net') == owned.namespace
         and os.readlink(proc/'ns/net') == owned.namespace,'NAMESPACE')
    fd_links = set()
    # Bounded, entirely kernel procfs. A transiently closed miss is not proof
    # of ownership, so only retain descriptors whose link was actually read.
    for count, fd in enumerate((proc/'fd').iterdir(),1):
        need(count <= 1024 and fd.name.isdecimal(),'PROC_FD_BOUND')
        try:
            fd_links.add(os.readlink(fd))
        except FileNotFoundError:
            continue
    return match_listener(bounded_proc(proc/'net/tcp'), bounded_proc(proc/'net/tcp6'), fd_links, owned.port)

def check_maintenance(owned, facts):
    exact = {
        'db':'postgres','login':ADMIN,'actor':ADMIN,'server_host':'127.0.0.1',
        'client_host':'127.0.0.1','port':owned.port,'listen':'127.0.0.1',
        'dir':owned.data_dir,'version':'180007','system_id':owned.disk_system_id,'recovery':False,'tls':True,'ssl_on':'on',
    }
    need(type(facts) is dict and set(facts) == set(exact)|{'start','oids'},'MAINTENANCE_SHAPE')
    need(all(type(facts[k]) is type(v) and facts[k] == v for k,v in exact.items()),'MAINTENANCE_ID')
    start = facts['start']
    need(isinstance(start,str) and re.fullmatch(r'[1-9][0-9]*\.[0-9]{6}',start) is not None
         and abs(int(start.split('.',1)[0])-owned.postmaster_epoch)<=1,'MAINTENANCE_START')
    return start

def canonical_receipt(owned, facts, nonce, issued):
    start=check_maintenance(owned,facts)
    oids=facts['oids']
    need(type(oids) is dict and set(oids)==set(DATABASES),'MAINTENANCE_OIDS')
    for n in DATABASES:
        need(isinstance(oids[n],str) and re.fullmatch(r'[1-9][0-9]{0,9}',oids[n]) is not None,'MAINTENANCE_OIDS')
    need(len(set(oids.values()))==1,'MAINTENANCE_OIDS')
    value = {
        'schema':'sites-onepage-terminal-tls-witness.v1','nonce':nonce,'issued_at':issued,
        'netns':owned.namespace,'postmaster_pid':owned.pid,'data_dir':owned.data_dir,
        'port':owned.port,'ca_sha256':owned.ca_sha256,'pg_version':'180007','start':start,'system_id':owned.disk_system_id,
        'oids':{n:oids[n] for n in DATABASES},
    }
    return json.dumps(value,separators=(',',':'),ensure_ascii=True).encode('ascii')

def openssl(*args, fds=()):
    result = subprocess.run(['/usr/bin/openssl',*args],check=False,stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,pass_fds=fds,timeout=5,
        env={'PATH':'/usr/bin:/bin','LC_ALL':'C','OPENSSL_CONF':'/dev/null'})
    need(result.returncode==0 and len(result.stdout)<=256,'SIGNER')
    return result.stdout

def memfd(label, data):
    fd = os.memfd_create(label,os.MFD_CLOEXEC|os.MFD_ALLOW_SEALING)
    try:
        import fcntl
        need(os.write(fd,data)==len(data),'SIGNER_MEMORY')
        os.lseek(fd,0,os.SEEK_SET)
        fcntl.fcntl(fd,fcntl.F_ADD_SEALS,
            fcntl.F_SEAL_WRITE|fcntl.F_SEAL_GROW|fcntl.F_SEAL_SHRINK|fcntl.F_SEAL_SEAL)
    except Exception:
        os.close(fd)
        raise
    return fd

def detached_frames(body, nonce, public, signature):
    need(1 <= len(body) <= 2048 and len(public)==32 and len(signature)==64
         and isinstance(nonce,str) and re.fullmatch('[0-9a-f]{64}',nonce) is not None,'FRAME')
    return public+nonce.encode('ascii'), len(body).to_bytes(2,'big')+body+signature

def sign_frames(body, nonce):
    key = openssl('genpkey','-algorithm','ED25519','-outform','DER')
    need(32<=len(key)<=96,'SIGNER_KEY')
    kfd=memfd('stage3-signing-key',key)
    try:
        mfd=memfd('stage3-compact-witness',body)
        try:
            pub=openssl('pkey','-in',f'/proc/self/fd/{kfd}','-inform','DER','-pubout','-outform','DER',fds=(kfd,))
            need(len(pub)==len(ED25519_SPKI)+32 and pub.startswith(ED25519_SPKI),'SIGNER_PUBLIC')
            sig=openssl('pkeyutl','-sign','-rawin','-inkey',f'/proc/self/fd/{kfd}',
                '-keyform','DER','-in',f'/proc/self/fd/{mfd}',fds=(kfd,mfd))
            return detached_frames(body,nonce,pub[len(ED25519_SPKI):],sig)
        finally:
            os.close(mfd)
    finally:
        os.close(kfd)

class ReceiptEmitter:
    """One instance per independent outer attestation and fixed trusted PHP child."""
    def __init__(self):
        self.used = False

    def send(self, owned, assert_owned, maintenance_read, control_fd, envelope_fd, sign_outside):
        """assert_owned reuses exact #589 live process check; maintenance_read is trusted psql.
        Both callables and both fresh anonymous write FDs MUST be owned by the supervisor.
        Only the predetermined PHP child gets separate read ends; close ends before DB.
        """
        need(not self.used,'REPLAY')
        self.used = True
        need(type(control_fd) is int and type(envelope_fd) is int
             and min(control_fd,envelope_fd)>=3 and control_fd!=envelope_fd,'PIPE_SCOPE')
        try:
            need(os.geteuid()==0,'OBSERVER_ROLE')
            pipes=[os.fstat(fd) for fd in (control_fd,envelope_fd)]
            need(all(stat.S_ISFIFO(p.st_mode) and p.st_uid==os.geteuid()
                     and os.readlink('/proc/self/fd/'+str(fd))==f'pipe:[{p.st_ino}]'
                     for fd,p in zip((control_fd,envelope_fd),pipes))
                 and len({(p.st_dev,p.st_ino) for p in pipes})==2,'PIPE_OWNERSHIP')
            need(type(owned) is OwnedPostmaster and type(owned.pid) is int and owned.pid>1
                and type(owned.port) is int and owned.port==55449
                and re.fullmatch(r'net:\[[1-9][0-9]*\]',owned.namespace) is not None
                and re.fullmatch('/tmp/sites-release-registry-[a-zA-Z0-9]{8,32}/data',owned.data_dir) is not None
                and re.fullmatch('[1-9][0-9]{14,19}',owned.disk_system_id) is not None
                and re.fullmatch('[a-f0-9]{64}',owned.ca_sha256) is not None
                and type(owned.postmaster_epoch) is int and owned.postmaster_epoch>0,'PROCESS_SCOPE')
            proc=pathlib.Path('/proc')/str(owned.pid)
            need(assert_owned(owned) is True,'PROCESS_WITNESS')
            inode=inspect_listener(proc,owned)
            facts=maintenance_read(MAINTENANCE_SQL)
            nonce=secrets.token_hex(32)
            body=canonical_receipt(owned,facts,nonce,int(time.time()))
            control, envelope = sign_outside(body,nonce)
            need(assert_owned(owned) is True and inspect_listener(proc,owned)==inode,'PROCESS_CHANGED')
            for fd,frame in ((control_fd,control),(envelope_fd,envelope)):
                # Each whole frame is smaller than Linux PIPE_BUF; no wait or partial acceptance.
                need(len(frame)<=os.fpathconf(fd,'PC_PIPE_BUF'),'PIPE_BOUND')
                os.set_blocking(fd,False)
                need(os.write(fd,frame)==len(frame),'PIPE_WRITE')
        except Refused:
            raise
        except (OSError,ValueError,TypeError,subprocess.SubprocessError):
            raise Refused('STAGE3_SUPERVISOR_REFUSED:WITNESS_IO') from None
        finally:
            for fd in {control_fd,envelope_fd}:
                try: os.close(fd)
                except OSError: pass
