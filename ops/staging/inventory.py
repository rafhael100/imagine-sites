#!/usr/bin/env python3
"""Local, read-only inventory and port recommendation. Never deploys or reserves."""
import argparse, datetime, ipaddress, hashlib, importlib.util, json, os, pathlib, shutil, socket, subprocess
PROJECT='imagine-sites-p0-staging'
EXCLUDED={18081,18088,18942}
spec=importlib.util.spec_from_file_location('impact',pathlib.Path(__file__).parents[1]/'homologation/leaseweb-host-impact.py')
impact=importlib.util.module_from_spec(spec);spec.loader.exec_module(impact)
def run(args):
 try:
  r=subprocess.run(args,capture_output=True,text=True,timeout=15)
  if r.returncode or len(r.stdout)>2000000:return None
  return r.stdout
 except (OSError,subprocess.TimeoutExpired):return None

def occupied_ports(rows,listeners):
 if not isinstance(rows,list) or not isinstance(listeners,set) or any(type(p) is not int or not 1<=p<=65535 for p in listeners):return None
 ports=set(listeners)
 for row in rows:
  if not isinstance(row,dict):return None
  for target,bindings in row.items():
   if bindings is None:continue
   if not isinstance(bindings,list):return None
   for bind in bindings:
    if not isinstance(bind,dict) or not isinstance(bind.get('HostPort'),str) or not bind['HostPort'].isdigit() or not isinstance(bind.get('HostIp'),str):return None
    try:ipaddress.ip_address(bind['HostIp'])
    except ValueError:return None
    p=int(bind['HostPort'])
    if not 1<=p<=65535:return None
    ports.add(p)
 return ports

def choose(rows,listeners,networks,volumes,containers,memory_mib,disk_gib,candidates,bind_probe,cpu_count,load1):
 ports=occupied_ports(rows,listeners)
 valid_names=lambda x:isinstance(x,list) and all(isinstance(n,str) and n and len(n)<=200 for n in x)
 metadata=ports is not None and all(valid_names(x) for x in [networks,volumes,containers])
 collisions=metadata and any(n==PROJECT or n.startswith(PROJECT+'_') or n.startswith(PROJECT+'-') for seq in [networks,volumes,containers] for n in seq)
 cpu_ok=type(cpu_count) is int and cpu_count>=2 and isinstance(load1,(int,float)) and 0<=load1<=cpu_count*0.75
 enough=cpu_ok and isinstance(memory_mib,int) and memory_mib>=3072 and isinstance(disk_gib,int) and disk_gib>=15
 selected=None
 if metadata and not collisions and enough:
  for p in candidates:
   if isinstance(p,int) and 1024<=p<=65535 and p not in ports and p not in EXCLUDED and bind_probe(p):selected=p;break
 blockers=[]
 if not metadata:blockers.append('inventory_incomplete')
 if collisions:blockers.append('project_namespace_already_exists_requires_owner_review')
 if not enough:blockers.append('insufficient_or_unknown_resource_headroom')
 if selected is None:blockers.append('no_exclusive_port_observed')
 fingerprint=hashlib.sha256(json.dumps([rows,sorted(listeners) if isinstance(listeners,set) and all(type(p) is int for p in listeners) else None,networks,volumes,containers],sort_keys=True).encode()).hexdigest()
 return {'schema':'sites.staging.inventory.v1','observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'project':PROJECT,'recommended_loopback_port':selected,'port_reserved':False,
  'inventory_complete':metadata,'namespace_collision':bool(collisions),'resource_headroom_observed':enough,
  'cpu_count':cpu_count,'load1':load1,'resource_limits_mib':1920,'available_memory_mib':memory_mib,'available_disk_gib':disk_gib,
  'counts':{'containers':len(containers) if valid_names(containers) else None,'networks':len(networks) if valid_names(networks) else None,'volumes':len(volumes) if valid_names(volumes) else None},
  'inventory_sha256':fingerprint,'services_modified':False,'can_deploy':False,'can_publish':False,'blockers':blockers,
  'notice':'Observation is not reservation, host identity proof or operational approval. Recheck immediately before startup.'}

def docker_ports():
 docker=shutil.which('docker')
 ids=run([docker,'ps','-a','--format','{{.ID}}']) if docker else None
 if ids is None:return None
 rows=[]
 for cid in ids.splitlines():
  if len(cid)!=12 or any(c not in '0123456789abcdef' for c in cid):return None
  raw=run([docker,'inspect','--format','{{json .HostConfig.PortBindings}}',cid])
  try:rows.append(json.loads(raw) or {})
  except (TypeError,ValueError):return None
 return rows

def bindable(port):
 try:
  with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as s:s.bind(('127.0.0.1',port))
  return True
 except OSError:return False

def main():
 a=argparse.ArgumentParser();a.add_argument('--probe-host',action='store_true');args=a.parse_args()
 if not args.probe_host:
  print(json.dumps({'status':'blocked','reason':'explicit_local_host_probe_required','can_deploy':False}));return 1
 docker=shutil.which('docker')
 def names(kind,fmt):
  v=run([docker,kind,'ls','--format',fmt]) if docker else None
  return v.splitlines() if v is not None else None
 nets=names('network','{{.Name}}');vols=names('volume','{{.Name}}')
 raw=run([docker,'ps','-a','--format','{{.Names}}']) if docker else None
 containers=raw.splitlines() if raw is not None else None
 try:memory=int(next(x.split()[1] for x in pathlib.Path('/proc/meminfo').read_text().splitlines() if x.startswith('MemAvailable:')))//1024
 except (OSError,StopIteration,ValueError):memory=None
 disk=int(shutil.disk_usage('/srv/imagine' if pathlib.Path('/srv/imagine').is_dir() else '/').free/1024**3)
 r=choose(docker_ports(),impact.listener_ports(),nets,vols,containers,memory,disk,range(18090,18100),bindable,os.cpu_count(),os.getloadavg()[0])
 print(json.dumps(r,sort_keys=True));return 0 if r['recommended_loopback_port'] else 1
if __name__=='__main__':raise SystemExit(main())
