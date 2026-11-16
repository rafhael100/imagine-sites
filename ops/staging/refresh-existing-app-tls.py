#!/usr/bin/env python3
"""Refresh ONLY existing Sites app config using the SAME image; never recreate DB/environment.
Snapshot/compare every other existing container. No pull/build/ports/volumes/DNS changes.
"""
import argparse,datetime,json,os,pathlib,subprocess,sys
REPO=pathlib.Path(__file__).resolve().parents[2]
def run(args,input=None):
 kw={'input':input} if input is not None else {'stdin':subprocess.DEVNULL}
 p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,**kw)
 if p.returncode:raise RuntimeError('Own app TLS operation failed')
 return p.stdout

def main():
 os.umask(0o077)
 a=argparse.ArgumentParser();a.add_argument('operation',choices=['snapshot','refresh']);a.add_argument('--root',default='/srv/imagine/sites-p0-private');v=a.parse_args()
 root=pathlib.Path(v.root)
 ci=os.environ.get('CI')=='true'
 if root.is_symlink() or (not ci and (str(root)!='/srv/imagine/sites-p0-private' or os.geteuid()!=0)) or (ci and not str(root).startswith('/tmp/')):raise RuntimeError('Scope denied')
 e=root/'evidence';e.mkdir(parents=True,exist_ok=True)
 secrets=pathlib.Path(os.environ['SITES_SECRETS_DIR']) if ci else root/'secrets'
 c=['docker','compose']+(['--env-file',str(root/'settings.env')] if (root/'settings.env').is_file() else [])+['-f',str(REPO/'ops/staging/compose.yaml')]
 def service(name):
  ids=run(c+['ps','-q',name]).decode().split()
  if len(ids)!=1:raise RuntimeError('Existing service required')
  labels=json.loads(run(['docker','inspect','--format','{{json .Config.Labels}}',ids[0]]))
  if labels.get('com.docker.compose.project')!='imagine-sites-p0-staging' or labels.get('com.docker.compose.service')!=name:raise RuntimeError('Scope mismatch')
  return ids[0]
 def state(cid):return run(['docker','inspect','--format','{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{.RestartCount}}',cid]).decode().strip()
 def save(name,data):
  fd=os.open(e/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o400)
  with os.fdopen(fd,'w') as f:json.dump(data,f,sort_keys=True)
 if v.operation=='snapshot':
  app=service('app');db=service('db');proxy=service('proxy')
  rows=[state(cid) for cid in run(['docker','ps','-aq','--no-trunc']).decode().split()]
  save('pg-tls-existing-before.json',{'app_id':app,'db_id':db,'proxy_id':proxy,'rows':rows,'app_image':run(['docker','inspect','--format','{{.Image}}',app]).decode().strip(),'at':datetime.datetime.now(datetime.timezone.utc).isoformat()})
  print(json.dumps({'snapshot_saved':True,'existing_count':len(rows),'no_service_modified':True}));return
 before=json.loads((e/'pg-tls-existing-before.json').read_text())
 if service('db')!=before['db_id'] or service('proxy')!=before['proxy_id']:raise RuntimeError('Existing infrastructure changed; inspect')
 run(c+['config','--quiet'])
 # Existing same image only; app config gains CA mount + verify-full. No DB/proxy up.
 run(c+['up','-d','--wait','--no-deps','--no-build','--pull','never','app'])
 app=service('app')
 if run(['docker','inspect','--format','{{.Image}}',app]).decode().strip()!=before['app_image']:raise RuntimeError('Unexpected image replacement')
 run(['docker','exec','-i',app,'sh','-c','umask 077; cat > /tmp/verify-database-tls.php'],(REPO/'ops/staging/verify-database-tls.php').read_bytes())
 run(['docker','exec','-i',app,'sh','-c','umask 077; cat > /tmp/sites-untrusted-ca.pem'],(secrets/'server-ca.pem').read_bytes())
 tls=json.loads(run(c+['exec','-T','app','php','/tmp/verify-database-tls.php']))
 if tls.get('status')!='verified':raise RuntimeError('App TLS not proven')
 run(c+['exec','-T','app','php','verify-runtime.php'])
 run(c+['exec','-T','proxy','nginx','-t']);run(c+['exec','-T','proxy','nginx','-s','reload'])
 rows=[row for row in before['rows'] if row.split('|')[0]!=before['app_id']]
 if any(state(row.split('|')[0])!=row for row in rows):raise RuntimeError('Another service changed; do not modify it')
 result={'schema':'sites.existing-app.verify-full.v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'port':18090 if not ci else None,'same_app_image':True,'app_configuration_refreshed':app!=before['app_id'],'other_existing_containers_unchanged':len(rows),'database_and_proxy_preserved':True,'runtime_verify_full':True,'ca_and_hostname_negatives':True,'can_accept_orders':False,'can_publish':False}
 save('pg-tls-app-result.json',result);print(json.dumps(result,sort_keys=True))
if __name__=='__main__':
 try:main()
 except Exception:
  print('BLOCKED: existing Sites app TLS refresh failed. Preserve evidence; never recreate DB or alter other projects.');sys.exit(1)
