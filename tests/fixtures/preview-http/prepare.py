"""Generate one-run synthetic credentials/certificates. Never operational custody."""
import os,pathlib,json,secrets,subprocess,base64
assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('SITES_PREVIEW_HTTP_CI')=='1'
p=pathlib.Path(os.environ['PREVIEW_CI_MATERIAL']);p.mkdir(mode=0o700)
def run(args): subprocess.run(args,cwd=p,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=Preview CI CA','-keyout','ca.key','-out','ca.pem'])
for name,san in [('db','DNS:db'),('server','IP:127.0.0.1')]:
 run(['openssl','req','-newkey','rsa:2048','-nodes','-subj','/CN='+('db' if name=='db' else '127.0.0.1'),'-keyout',name+'.key','-out',name+'.csr'])
 (p/(name+'.ext')).write_text('subjectAltName='+san+'\nextendedKeyUsage=serverAuth\n')
 run(['openssl','x509','-req','-in',name+'.csr','-CA','ca.pem','-CAkey','ca.key','-CAcreateserial','-days','1','-extfile',name+'.ext','-out',name+'.pem'])
passwords={n:secrets.token_hex(24) for n in ['author','editor','other']};dbpw=secrets.token_hex(24)
(p/'db-password').write_text(dbpw);(p/'app_key').write_text('base64:'+base64.b64encode(secrets.token_bytes(32)).decode())
principals={}
for n in passwords:
 principals[n]={'company_id':('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' if n=='other' else 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),'sql_login':'sites_preview_'+n,'sql_password':dbpw,'expires_at':int(__import__('time').time())+3600,'revoked':False}
(p/'preview_principals').write_text(json.dumps({'schema':'SITES_DEMO_PRINCIPALS_V1','principals':principals}))
(p/'browser.json').write_text(json.dumps(passwords))
lines=[]
for n,pw in passwords.items():
 h=subprocess.check_output(['openssl','passwd','-apr1','-stdin'],input=pw+'\n',text=True).strip();lines.append(n+':'+h)
(p/'access_passwords').write_text('\n'.join(lines)+'\n');(p/'portal_identities').write_text('# No Portal service identity authorized\n')
for f in p.iterdir():f.chmod(0o600)
# Public CI CA/cert files; no private key is uploaded.
for name in ['ca.pem','db.pem','server.pem','access_passwords','portal_identities']: (p/name).chmod(0o644)
for name in ['app_key','preview_principals']:os.chown(p/name,33,33);(p/name).chmod(0o400)
os.chown(p/'db.key',999,999);(p/'db.key').chmod(0o600)
p.chmod(0o755)
