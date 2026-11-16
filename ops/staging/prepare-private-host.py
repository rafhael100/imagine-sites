#!/usr/bin/env python3
"""Prepare NEW Sites-only private files after a fresh local inventory. No service startup.
No Portal principal is issued, no operational authority or remote access is granted.
"""
import argparse,base64,importlib.util,json,os,pathlib,secrets,subprocess,sys
ROOT=pathlib.Path('/srv/imagine/sites-p0-private')
def command(args,input=None):
 return subprocess.run(args,input=input,capture_output=True,check=True).stdout

def write(path,data,group=0,mode=0o400):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 try:
  os.write(fd,data if isinstance(data,bytes) else data.encode());os.fchown(fd,0,group);os.fchmod(fd,mode)
 finally:os.close(fd)

def main():
 a=argparse.ArgumentParser();a.add_argument('--port',type=int,required=True);v=a.parse_args()
 if os.geteuid()!=0 or v.port not in range(18090,18100) or ROOT.exists() or ROOT.is_symlink():
  print('BLOCKED: new private Sites directory, root scope and reviewed port required.');return 1
 # Probe and snapshot are local observations, not Leaseweb identity or approval claims.
 path=pathlib.Path(__file__).parent/'inventory.py'
 raw=command([sys.executable,str(path),'--probe-host']);report=json.loads(raw)
 if report.get('recommended_loopback_port')!=v.port or not report.get('inventory_complete') or report.get('blockers'):
  print('BLOCKED: fresh inventory does not match reviewed plan.');return 1
 ROOT.mkdir(mode=0o700);vault=ROOT/'secrets';vault.mkdir(mode=0o700);evidence=ROOT/'evidence';evidence.mkdir(mode=0o700)
 write(evidence/'inventory-before.json',raw)
 ids=command(['docker','ps','-aq']).decode().splitlines();baseline=[]
 for cid in ids:
  baseline.append(command(['docker','inspect','--format','{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{.RestartCount}}',cid]).decode().strip())
 write(evidence/'existing-containers-before.json',json.dumps(sorted(baseline)))
 write(vault/'app-key','base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),33,0o440)
 write(vault/'runtime-password',secrets.token_hex(32),33,0o440)
 write(vault/'owner-password',secrets.token_hex(32))
 password=secrets.token_hex(24);write(ROOT/'access-password',password)
 hashed=command(['openssl','passwd','-6','-stdin'],password.encode()).decode().strip()
 write(vault/'access.htpasswd','rafhael-sites-staging:'+hashed+'\n',101,0o440)
 write(ROOT/'curl-auth.conf','user = "rafhael-sites-staging:'+password+'"\n')
 write(vault/'portal-identities.map','# No operational Portal identity enrolled. Default deny.\n',101,0o440)
 for name,cn in [('server-ca','Imagine Sites Staging Server CA'),('portal-ca','Imagine Sites Staging Unbound Client CA')]:
  command(['openssl','req','-x509','-newkey','rsa:3072','-nodes','-days','30','-subj','/CN='+cn,'-keyout',str(vault/(name+'.key')),'-out',str(vault/(name+'.pem'))])
  os.chmod(vault/(name+'.key'),0o400);os.chmod(vault/(name+'.pem'),0o444)
 command(['openssl','req','-newkey','rsa:3072','-nodes','-subj','/CN=sites-p0.staging.internal','-keyout',str(vault/'server.key'),'-out',str(ROOT/'server.csr')])
 write(ROOT/'server.ext','subjectAltName=DNS:localhost,DNS:sites-p0.staging.internal,DNS:proxy,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n')
 command(['openssl','x509','-req','-in',str(ROOT/'server.csr'),'-CA',str(vault/'server-ca.pem'),'-CAkey',str(vault/'server-ca.key'),'-CAcreateserial','-days','14','-extfile',str(ROOT/'server.ext'),'-out',str(vault/'server.pem')])
 os.chown(vault/'server.key',0,101);os.chmod(vault/'server.key',0o440);os.chmod(vault/'server.pem',0o444)
 write(ROOT/'settings.env',f'SITES_STAGING_PORT={v.port}\nSITES_PRIVATE_URL=https://localhost:{v.port}\nSITES_SECRETS_DIR={vault}\n')
 write(ROOT/'backup-encryption-key',secrets.token_hex(32));write(ROOT/'backup-mac-key',secrets.token_hex(32))
 print(json.dumps({'prepared':True,'project':'imagine-sites-p0-staging','port':v.port,'private_directory':str(ROOT),'existing_containers_snapshotted':len(baseline),'portal_principal_enrolled':False,'services_started':False,'can_accept_orders':False,'can_publish':False}))
 return 0
if __name__=='__main__':
 try:sys.exit(main())
 except Exception:
  print('BLOCKED: private preparation failed; preserve partial directory for owner review. No private context emitted.');sys.exit(1)
