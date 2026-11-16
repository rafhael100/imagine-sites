#!/usr/bin/env python3
"""Explicit owner operation for the NEW isolated Sites staging project only.
Never opens public ports, enrolls Portal identities, charges or publishes.
"""
import argparse,datetime,hashlib,hmac,json,os,pathlib,subprocess,sys
ROOT=pathlib.Path('/srv/imagine/sites-p0-private')
REPO=pathlib.Path(__file__).resolve().parents[2]
COMPOSE=['docker','compose','--env-file',str(ROOT/'settings.env'),'-f',str(REPO/'ops/staging/compose.yaml')]
E=ROOT/'evidence'
def run(args,input=None):
 options={'input':input} if input is not None else {'stdin':subprocess.DEVNULL}
 r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False,**options)
 if r.returncode:raise RuntimeError('Operation failed; preserve isolated state for review')
 return r.stdout

def db(command):
 return COMPOSE+['exec','-T','db','sh','-c','PGPASSWORD="$(cat /run/secrets/owner_password)" '+command]

def existing_unchanged():
 before=json.loads((E/'existing-containers-before.json').read_text());after=[]
 for row in before:
  cid=row.split('|')[0]
  if len(cid)!=64 or any(c not in '0123456789abcdef' for c in cid):raise RuntimeError()
  after.append(run(['docker','inspect','--format','{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{.RestartCount}}',cid]).decode().strip())
 return sorted(before)==sorted(after)

def start():
 raw=run([sys.executable,str(REPO/'ops/staging/inventory.py'),'--probe-host']);inv=json.loads(raw)
 settings=(ROOT/'settings.env').read_text().splitlines()
 if inv.get('recommended_loopback_port')!=18090 or inv.get('blockers') or 'SITES_STAGING_PORT=18090' not in settings:raise RuntimeError('Fresh port plan mismatch')
 if any(line.strip() and not line.lstrip().startswith('#') for line in (ROOT/'secrets/portal-identities.map').read_text().splitlines()):raise RuntimeError('Portal principal requires separate review')
 run(COMPOSE+['config','--quiet'])
 # No implicit build/pull: images must have been explicitly reviewed/built first.
 run(COMPOSE+['up','-d','--wait','--pull','never','db'])
 owner=['run','--rm','--no-deps','--pull','never','--user','0:0','-v',str(ROOT/'secrets/owner-password')+':/run/secrets/migration_owner:ro','-e','DB_USERNAME=sites_bootstrap','-e','SITES_DB_PASSWORD_FILE=/run/secrets/migration_owner']
 run(COMPOSE+owner+['app','php','artisan','migrate','--force','--no-interaction'])
 run(COMPOSE+owner+['-e','SITES_NEW_DATABASE_PROVISION=new-isolated-sites-database','app','php','provision-runtime.php'])
 run(COMPOSE+['up','-d','--wait','--pull','never','app','proxy'])
 run(COMPOSE+['exec','-T','app','php','verify-runtime.php'])
 status=[]
 for path in ['/','/up','/_private/health','/api/sites/v1/create-project']:
  args=['curl','--connect-timeout','2','--max-time','10','--cacert',str(ROOT/'secrets/server-ca.pem'),'--config',str(ROOT/'curl-auth.conf'),'-sS','-o','/dev/null','-w','%{http_code}','https://localhost:18090'+path]
  status.append(int(run(args)))
 unauth=int(run(['curl','--connect-timeout','2','--max-time','10','--cacert',str(ROOT/'secrets/server-ca.pem'),'-sS','-o','/dev/null','-w','%{http_code}','https://localhost:18090/']))
 if status!=[200,200,404,404] or unauth!=401:raise RuntimeError('Private HTTP checks failed')
 result={'schema':'sites.staging.host-start.v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source_sha':run(['git','-C',str(REPO),'rev-parse','HEAD']).decode().strip(),'project':'imagine-sites-p0-staging','loopback_port':18090,'app_db_proxy_started':True,'https_ca_verified':True,'nominal_access_401_200':True,'private_routes_hidden':True,'runtime_sql_rls_verified':True,'existing_containers_unchanged':existing_unchanged(),'portal_principal_enrolled':False,'can_accept_orders':False,'can_publish':False}
 if not result['existing_containers_unchanged']:raise RuntimeError('Existing container baseline changed; review without modifying others')
 save('host-start.json',result);print(json.dumps(result,sort_keys=True))

def save(name,data):
 fd=os.open(E/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o400)
 with os.fdopen(fd,'w') as f:json.dump(data,f,sort_keys=True)

def restore():
 if not (E/'host-start.json').is_file() or (E/'backup-restore.json').exists():raise RuntimeError('Verified start and fresh restore evidence required')
 exists=run(db("psql -U sites_bootstrap -d sites_staging -Atc \"SELECT count(*) FROM pg_database WHERE datname='sites_restore_verification'\""))
 if exists.strip()!=b'0':raise RuntimeError('Restore target already exists; no overwrite')
 backup=E/'sites-initial-backup.enc'
 with open(backup,'xb') as out:
  os.chmod(backup,0o400)
  dump=subprocess.Popen(db('pg_dump -U sites_bootstrap -d sites_staging -Fc'),stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
  encrypt=subprocess.Popen(['openssl','enc','-aes-256-cbc','-pbkdf2','-salt','-pass','file:'+str(ROOT/'backup-encryption-key')],stdin=dump.stdout,stdout=out,stderr=subprocess.DEVNULL)
  dump.stdout.close();enc=encrypt.wait();src=dump.wait()
  if enc or src:raise RuntimeError('Backup failed')
 key=(ROOT/'backup-mac-key').read_bytes()
 mac=hmac.new(key,backup.read_bytes(),hashlib.sha256).hexdigest()
 save('backup-integrity.json',{'hmac_sha256':mac,'bytes':backup.stat().st_size})
 if not hmac.compare_digest(mac,hmac.new(key,backup.read_bytes(),hashlib.sha256).hexdigest()):raise RuntimeError('Backup integrity failed')
 run(db('createdb -U sites_bootstrap sites_restore_verification'))
 run(db('psql -U sites_bootstrap -d sites_staging -v ON_ERROR_STOP=1 -c "REVOKE ALL ON DATABASE sites_restore_verification FROM PUBLIC"'))
 decrypt=subprocess.Popen(['openssl','enc','-d','-aes-256-cbc','-pbkdf2','-pass','file:'+str(ROOT/'backup-encryption-key'),'-in',str(backup)],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
 restored=subprocess.Popen(db('pg_restore -U sites_bootstrap -d sites_restore_verification --exit-on-error'),stdin=decrypt.stdout,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 decrypt.stdout.close();dst=restored.wait();dec=decrypt.wait()
 if dst or dec:raise RuntimeError('Restore failed; no destructive cleanup')
 query="SELECT count(*),bool_and(c.relrowsecurity AND c.relforcerowsecurity) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='sites_core' AND c.relkind='r'"
 original=run(db('psql -U sites_bootstrap -d sites_staging -Atc "'+query+'"')).strip()
 restored_flags=run(db('psql -U sites_bootstrap -d sites_restore_verification -Atc "'+query+'"')).strip()
 if original!=restored_flags or not original.endswith(b'|t'):raise RuntimeError('Restored RLS mismatch')
 unchanged=existing_unchanged()
 result={'schema':'sites.staging.backup-restore.v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'encrypted_backup':True,'hmac_verified_before_decryption':True,'restored_to_new_database':True,'restored_rls_table_count':int(original.split(b'|')[0]),'restored_force_rls_verified':True,'offsite_backup_verified':False,'existing_containers_unchanged':unchanged,'can_accept_orders':False,'can_publish':False}
 if not unchanged:raise RuntimeError('Existing container baseline changed; owner review required')
 save('backup-restore.json',result);print(json.dumps(result,sort_keys=True))

def main():
 os.umask(0o077)
 if os.geteuid()!=0 or not ROOT.is_dir() or ROOT.is_symlink():raise RuntimeError('Private root scope required')
 a=argparse.ArgumentParser();a.add_argument('operation',choices=['start','restore']);v=a.parse_args()
 if v.operation=='start':start()
 else:restore()
if __name__=='__main__':
 try:main()
 except Exception:
  print('BLOCKED: isolated Sites operation failed. Preserve own state/evidence; do not modify other projects.');sys.exit(1)
