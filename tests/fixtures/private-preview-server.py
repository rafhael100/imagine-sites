"""CI-only authenticated viewer for artifacts read from the actual persisted demo workspace.
No Portal contract/financial identity and no operational Sites route is represented here.
"""
import base64,hmac,http.server,json,os
from pathlib import Path
assert os.environ.get('CI')=='true' and os.environ.get('SITES_PRIVATE_PREVIEW_PG_CI')=='1'
root=Path(os.environ['SITES_PREVIEW_EVIDENCE_DIR'])
credential=json.loads(Path(os.environ['SITES_PREVIEW_CI_VAULT']).read_text())
expected='Basic '+base64.b64encode((credential['user']+':'+credential['password']).encode()).decode()
class Handler(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_GET(self):
  if not hmac.compare_digest(self.headers.get('Authorization',''),expected):
   self.send_response(401);self.send_header('WWW-Authenticate','Basic realm="synthetic-ci-demo"');self.end_headers();return
  product={'/onepage':'onepage','/landing-page':'landing_page'}.get(self.path)
  if not product:self.send_error(404);return
  body=(root/(product+'.html')).read_bytes()
  self.send_response(200);self.send_header('Content-Type','text/html; charset=utf-8');self.send_header('Cache-Control','no-store');self.send_header('X-Robots-Tag','noindex, nofollow');self.end_headers();self.wfile.write(body)
http.server.ThreadingHTTPServer(('127.0.0.1',18990),Handler).serve_forever()
