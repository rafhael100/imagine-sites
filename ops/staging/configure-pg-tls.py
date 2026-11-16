#!/usr/bin/env python3
"""Add TLS to the EXISTING Sites PG18 via SIGHUP. No db/container creation or restart.
Prepare local certs first; enable only in exact Sites Compose project. No secret output.
"""
import argparse,datetime,json,os,pathlib,subprocess,sys
REPO=pathlib.Path(__file__).resolve().parents[2]
class TLS:
 def __init__(self,secrets,envfile=None):
  self.secrets=pathlib.Path(secrets).resolve()
  ci=os.environ.get('CI')=='true'
  if (not ci and str(self.secrets)!='/srv/imagine/sites-p0-private/secrets') or (ci and not str(self.secrets).startswith('/tmp/')):raise RuntimeError('Scope denied')
  if not self.secrets.is_dir() or self.secrets.is_symlink():raise RuntimeError('Secret directory required')
  self.compose=['docker','compose']+(['--env-file',str(envfile)] if envfile else [])+['-f',str(REPO/'ops/staging/compose.yaml')]
 def run(self,args,input=None,allow_failure=False,env=None):
  kw={'input':input} if input is not None else {'stdin':subprocess.DEVNULL}
  p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,**kw)
  if p.returncode and not allow_failure:raise RuntimeError('Private TLS operation failed')
  return p
 def prepare(self):
  files=['database-ca.key','database-ca.pem','database-server.key','database-server.pem']
  if any((self.secrets/f).exists() for f in files):raise RuntimeError('TLS material already exists; inspect, never overwrite')
  def ssl(*args):self.run(['openssl',*args])
  d=self.secrets
  ssl('req','-x509','-newkey','rsa:2048','-nodes','-days','30','-subj','/CN=Imagine-Sites-PG18-Staging-CA','-keyout',str(d/files[0]),'-out',str(d/files[1]))
  ssl('req','-newkey','rsa:2048','-nodes','-subj','/CN=db','-keyout',str(d/files[2]),'-out',str(d/'database-server.csr'))
  ext=d/'database-server.ext';ext.write_text('subjectAltName=DNS:db,DNS:localhost,DNS:sites-db.p0.staging.internal,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n')
  ssl('x509','-req','-in',str(d/'database-server.csr'),'-CA',str(d/files[1]),'-CAkey',str(d/files[0]),'-CAcreateserial','-days','14','-extfile',str(ext),'-out',str(d/files[3]))
  for name in files:os.chmod(d/name,0o444 if name.endswith('.pem') else 0o400)
  return {'material_prepared':True,'private_ca_mounted':False}
 def owner(self,sql,dsn=None,fail=False):
  # Docker imports named env value from client env; password is never an argv/log value.
  env=dict(os.environ,PGPASSWORD=(self.secrets/'owner-password').read_text().strip())
  args=['docker','exec','-i','-e','PGPASSWORD',self.db,'psql','-X','-v','ON_ERROR_STOP=1','-U','sites_bootstrap','-d',dsn or 'sites_staging','-At']
  return self.run(args,(sql+'\n').encode(),allow_failure=fail,env=env)
 def enable(self):
  ids=self.run(self.compose+['ps','-q','db']).stdout.decode().split()
  if len(ids)!=1:raise RuntimeError('Existing database required')
  self.db=ids[0]
  meta=json.loads(self.run(['docker','inspect','--format','{{json .Config.Labels}}',self.db]).stdout)
  if meta.get('com.docker.compose.project')!='imagine-sites-p0-staging' or meta.get('com.docker.compose.service')!='db':raise RuntimeError('Database scope mismatch')
  settings=self.owner("SELECT name||'|'||context FROM pg_settings WHERE name IN ('ssl','ssl_cert_file','ssl_key_file') ORDER BY name").stdout.decode().splitlines()
  if len(settings)!=3 or any(not row.endswith('|sighup') for row in settings):raise RuntimeError('Reload-only TLS unavailable; no restart attempted')
  data=self.owner('SHOW data_directory').stdout.decode().strip()
  if data!='/var/lib/postgresql/18/docker':raise RuntimeError('Unexpected own PGDATA')
  self.certdir=data+'/sites-private-tls'
  uid=self.run(['docker','exec',self.db,'id','-u','postgres']).stdout.decode().strip()
  gid=self.run(['docker','exec',self.db,'id','-g','postgres']).stdout.decode().strip()
  if not uid.isdigit() or not gid.isdigit():raise RuntimeError('Invalid postgres ownership')
  self.run(['docker','exec','--user','0:0',self.db,'mkdir','-m','0700',self.certdir])
  for src,dst in [('database-server.pem','server.pem'),('database-server.key','server.key'),('database-ca.pem','ca.pem'),('server-ca.pem','wrong-ca.pem')]:
   self.run(['docker','cp',str(self.secrets/src),self.db+':'+self.certdir+'/'+dst])
  self.run(['docker','exec','--user','0:0',self.db,'chown','-R',uid+':'+gid,self.certdir])
  self.run(['docker','exec','--user','0:0',self.db,'chmod','0600',self.certdir+'/server.key'])
  hba=self.owner('SHOW hba_file').stdout.decode().strip()
  if hba!=data+'/pg_hba.conf':raise RuntimeError('Unexpected HBA path')
  raw=self.run(['docker','exec',self.db,'cat',hba]).stdout
  # Preserve private rollback copy; original contains only own Sites auth policy.
  backup=self.secrets/'database-hba-before.conf'
  with open(backup,'xb') as f:f.write(raw)
  os.chmod(backup,0o400)
  target=self.secrets/'database-hba-tls.conf'
  with open(target,'xb') as f:f.write(b'# Sites TLS: reject unencrypted TCP; Unix operator auth unchanged\nhostnossl all all 0.0.0.0/0 reject\nhostnossl all all ::/0 reject\n'+raw)
  self.run(['docker','cp',str(target),self.db+':'+hba])
  self.run(['docker','exec','--user','0:0',self.db,'chown',uid+':'+gid,hba])
  sql="ALTER SYSTEM SET ssl_cert_file = '"+self.certdir+"/server.pem';\nALTER SYSTEM SET ssl_key_file = '"+self.certdir+"/server.key';\nALTER SYSTEM SET ssl_min_protocol_version = 'TLSv1.2';\nALTER SYSTEM SET ssl = 'on';\nSELECT pg_reload_conf();"
  if b't' not in self.owner(sql).stdout:raise RuntimeError('Reload rejected')
  # Short bounded retries permit SIGHUP processing, never change configured port/service.
  import time
  good=None
  for attempt in range(20):
   good=self.owner('SELECT ssl,version FROM pg_stat_ssl WHERE pid=pg_backend_pid()',self.dsn(),fail=True)
   if good.returncode==0:break
   time.sleep(.1)
  if good.returncode or good.stdout.strip() not in (b't|TLSv1.2',b't|TLSv1.3'):raise RuntimeError('Verified TLS connection absent')
  wronghost=self.owner('SELECT 1',self.dsn('wrong.invalid'),fail=True)
  wrongca=self.owner('SELECT 1',self.dsn(ca='wrong-ca.pem'),fail=True)
  plain=self.owner('SELECT 1','host=127.0.0.1 dbname=sites_staging sslmode=disable',fail=True)
  if not wronghost.returncode or b'does not match host name' not in wronghost.stderr:raise RuntimeError('Hostname negative not proven')
  if not wrongca.returncode or b'certificate verify failed' not in wrongca.stderr:raise RuntimeError('CA negative not proven')
  if not plain.returncode or b'no encryption' not in plain.stderr:raise RuntimeError('Plaintext denial not proven')
  return {'database_container_preserved':True,'tls_reload_only':True,'verify_full_trusted':True,'hostname_mismatch_denied':True,'untrusted_ca_denied':True,'plaintext_tcp_denied':True,'minimum_tls':'TLSv1.2','can_accept_orders':False,'can_publish':False}
 def dsn(self,host='localhost',ca='ca.pem'):
  return 'host='+host+' hostaddr=127.0.0.1 dbname=sites_staging sslmode=verify-full sslrootcert='+self.certdir+'/'+ca

def main():
 os.umask(0o077)
 p=argparse.ArgumentParser();p.add_argument('operation',choices=['prepare','enable']);p.add_argument('--secrets-dir',required=True);p.add_argument('--env-file');a=p.parse_args()
 t=TLS(a.secrets_dir,a.env_file);result=t.prepare() if a.operation=='prepare' else t.enable()
 print(json.dumps({'schema':'sites.pg18.inplace-tls.v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),**result},sort_keys=True))
if __name__=='__main__':
 try:main()
 except Exception:
  print('BLOCKED: own Sites TLS operation failed; preserve state, inspect without changing other projects.');sys.exit(1)
