#!/usr/bin/env python3
"""Operator-invoked, no-network, transient pure PHP tests. Never spoof CI flags.
Uses only an already cached image. Never runs migrations or any Docker Compose up.
"""
import argparse
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--php-image',required=True);a=p.parse_args()
# Exact content-addressed tag of an already existing local image only, no registry pull.
if not re.fullmatch(r'imagine-crm-private-journey:[a-f0-9]{40}',a.php_image):raise SystemExit('Only an existing exact-SHA local PHP image is accepted.')
if not (ROOT/'tests/run.php').is_file() or not (ROOT/'AGENTS.md').is_file():raise SystemExit('Reviewed repository required.')
for flag in ['GITHUB_ACTIONS','SITES_SYNTHETIC_HTTP']:
    if os.getenv(flag):raise SystemExit('Do not impersonate CI on a VPS.')

def capture(cmd):
    r=subprocess.run(cmd,capture_output=True,text=True,check=True,timeout=30)
    return r.stdout.strip()

def test():
    capture(['docker','image','inspect','--format','{{.Id}}',a.php_image])
    before=capture(['docker','ps','--format','{{.ID}}']).splitlines()
    # Only public source is mounted. No credential/state directory, socket, network or port.
    command=['docker','run','--rm','--pull=never','--network','none','--read-only','--cap-drop','ALL',
        '--security-opt','no-new-privileges:true','--user','33:33','--pids-limit','64','--memory','128m','--cpus','1',
        '--mount','type=bind,src='+str(ROOT)+',dst=/work,readonly','--workdir','/work','--entrypoint','php',a.php_image,'tests/run.php']
    result=subprocess.run(command,capture_output=True,text=True,timeout=120,check=False,stdin=subprocess.DEVNULL)
    # Never relay container error payload or arbitrary PHP output.
    after=capture(['docker','ps','--format','{{.ID}}']).splitlines()
    if sorted(before)!=sorted(after):raise RuntimeError('Existing container inventory changed during observation; independent verification required.')
    if result.returncode!=0:raise RuntimeError('Domain test did not pass; no production action attempted.')
    count=sum(line.startswith('PASS ') for line in result.stdout.splitlines())
    if count<1:raise RuntimeError('Domain test produced no assertion evidence.')
    print('PRIVATE VPS DOMAIN PASS: '+str(count)+' assertions; cached PHP image; no network, published port, secret, DB, migration or existing service change. NO-GO preserved.')
try:test()
except Exception:print('PRIVATE VPS DOMAIN BLOCKED: unable to complete isolated domain rehearsal; no release authorization.',file=sys.stderr);raise SystemExit(1)
