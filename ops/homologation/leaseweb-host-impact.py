#!/usr/bin/env python3
"""Read-only host impact evidence, NEVER admission/deployment/publication approval.
No .env, Docker environment/commands, credential, archive or DB content is read.
"""
from __future__ import annotations
import argparse
import datetime
import json
import os
from pathlib import Path
import platform
import re
import shutil
import socket
import stat
import subprocess

PORT = 18081

def command(args):
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=15, check=False)
        if result.returncode or len(result.stdout)>1000000:return None
        return result.stdout
    except (OSError, subprocess.TimeoutExpired):return None

def classify_bindings(rows):
    """Only finite metadata counts, never names, labels, images or environment."""
    occupied=set(); public=0; databases=0
    if not isinstance(rows,list):return {'observed':False}
    for bindings in rows:
        if not isinstance(bindings,dict):return {'observed':False}
        for target,hosts in bindings.items():
            if hosts is None:continue
            if not isinstance(hosts,list):return {'observed':False}
            for host in hosts:
                if not isinstance(host,dict):return {'observed':False}
                ip=host.get('HostIp');port=host.get('HostPort')
                if not isinstance(port,str) or not port.isdecimal() or not 1<=int(port)<=65535 or not isinstance(ip,str):return {'observed':False}
                occupied.add(int(port))
                if ip not in ('127.0.0.1','::1'):public+=1
                if target.split('/')[0] in ('5432','6379') and ip not in ('127.0.0.1','::1'):databases+=1
    return {'observed':True,'containers_observed':len(rows),'public_binding_count':public,
            'public_database_binding_count':databases,'candidate_port_in_use':PORT in occupied,
            'candidate_port':PORT,'existing_services_modified':False}

def docker_bindings():
    docker=shutil.which('docker')
    ids=command([docker,'ps','--format','{{.ID}}']) if docker else None
    if ids is None:return None
    rows=[]
    for cid in ids.splitlines():
        if len(cid)!=12 or any(c not in '0123456789abcdef' for c in cid):return None
        # Narrow format: Docker's Config.Env, Cmd, labels, mounts and secrets never returned.
        raw=command([docker,'inspect','--format','{{json .NetworkSettings.Ports}}',cid])
        if raw is None:return None
        try:rows.append(json.loads(raw))
        except (TypeError,ValueError):return None
    return rows

def listener_ports(paths=None):
    try:
        ports=set()
        for path in paths or [Path('/proc/net/tcp'),Path('/proc/net/tcp6')]:
            lines=path.read_text().splitlines()[1:]
            for row in lines:
                fields=row.split()
                if len(fields)<4:raise ValueError()
                if fields[3]=='0A':ports.add(int(fields[1].rsplit(':',1)[1],16))
        return ports
    except (OSError,ValueError,IndexError):return None

def backup_metadata(path):
    """Bounded lstat/scandir metadata only; no file opens, symlink traversal or names."""
    result={'observed':False,'contents_read':False,'restoration_proven':False}
    try:
        st=path.lstat()
        if stat.S_ISLNK(st.st_mode) or not stat.S_ISDIR(st.st_mode):return result
        stack=[(path,0)];count=0;latest=0;truncated=False
        while stack:
            directory,depth=stack.pop()
            with os.scandir(directory) as entries:
                for entry in entries:
                    count+=1
                    if count>500:truncated=True;break
                    info=entry.stat(follow_symlinks=False)
                    if stat.S_ISLNK(info.st_mode):continue
                    if stat.S_ISREG(info.st_mode):latest=max(latest,info.st_mtime)
                    elif stat.S_ISDIR(info.st_mode) and depth<2:stack.append((Path(entry.path),depth+1))
            if truncated:break
        result.update(observed=True,entries_observed=min(count,500),bounded_scan_truncated=truncated,
            latest_file_age_hours=round(max(0,datetime.datetime.now().timestamp()-latest)/3600,1) if latest else None)
    except (OSError,ValueError):pass
    return result

def report(rows,ports,backups=None):
    bindings=classify_bindings(rows)
    port_blocked=ports is None or PORT in ports or bindings.get('candidate_port_in_use',True)
    blockers=['operational_psp_iam_consent_revocation_unverified','sites_pg18_rls_restore_unverified',
        'sites_mtls_domain_publisher_rollback_unverified','independent_security_review_pending']
    if port_blocked:blockers.insert(0,'candidate_loopback_port_18081_occupied_or_unverified')
    if not bindings.get('observed'):blockers.append('docker_bindings_unverified')
    if bindings.get('public_database_binding_count',0):blockers.append('public_database_binding_detected')
    return {'schema':'sites.leaseweb.host-impact.v1','status':'blocked','can_deploy':False,'can_publish':False,
        'candidate_port':PORT,'candidate_port_available_observed':not port_blocked,
        'docker':bindings,'listener_metadata_observed':ports is not None,
        'backups_metadata':backups or [],'backup_contents_read':False,'services_changed':False,
        'blockers':blockers,'note':'Observed metadata is not operational authorization; an unused port is not reserved.'}

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--backup-dir',type=Path,action='append',default=[]);a=p.parse_args()
    result=report(docker_bindings(),listener_ports(),[backup_metadata(path) for path in a.backup_dir])
    try:r=platform.freedesktop_os_release();result['ubuntu_24_04']=r.get('ID')=='ubuntu' and r.get('VERSION_ID')=='24.04'
    except OSError:result['ubuntu_24_04']=None
    docker=shutil.which('docker')
    images=command([docker,'ps','--format','{{.Image}}']) if docker else None
    result['postgres_official_image_major_versions']=sorted({int(x) for x in re.findall(r'(?m)^postgres:([0-9]+)(?:[.\-][^\n]*)?$',images or '')})
    result['postgres_image_metadata_observed']=images is not None
    result['resources']={'cpu_count':os.cpu_count(),'disk_available_gib':int(shutil.disk_usage('/').free/1024**3)}
    print(json.dumps(result,sort_keys=True,separators=(',',':')));return 1
if __name__=='__main__':raise SystemExit(main())
