#!/usr/bin/env python3
"""F1-21 conservative safeguard against accidental operational CI workflows.
This is NOT a security certification; review workflow behavior manually too.
"""
import json
import re
from pathlib import Path

repo=Path(__file__).resolve().parent.parent
gates=json.loads((repo/"config/operational-readiness.json").read_text(encoding="utf-8"))
assert gates["schema"]=="imagine-sites.production-readiness.v1"
assert gates["status"]=="BLOCKED"
assert gates["non_operational"] is True
assert len(gates["gates"])>=10
assert not any(gates["gates"].values()), "A gate must never be approved solely by this CI test"

workflows=sorted((repo/".github/workflows").glob("*.yml"))
assert len(workflows)>=10, "Unexpected missing workflow inventory"
danger=[
 re.compile(r"\buses:\s*(?:cloudflare/wrangler-action|aws-actions/configure-aws-credentials|azure/login|google-github-actions/auth|appleboy/ssh-action)",re.I),
 re.compile(r"\bwrangler\s+(?:deploy|publish)\b",re.I),
 re.compile(r"\b(?:kubectl|helm|terraform|flyctl)\s+(?:apply|upgrade|deploy)\b",re.I),
 re.compile(r"\b(?:docker\s+push|rsync\s+-|ssh\s+[^\n]+@)\b",re.I),
 re.compile(r"\bid-token:\s*write\b",re.I),
 re.compile(r"\b(?:contents|actions|deployments):\s*write\b",re.I),
 re.compile(r"\bsecrets\.[A-Za-z_][A-Za-z0-9_]*\b",re.I),
]
for file in workflows:
 text=file.read_text(encoding="utf-8")
 assert re.search(r"(?m)^permissions:\s*\n\s+contents:\s*read\s*$",text),f"{file}: minimum read-only permissions not declared"
 for pattern in danger:
  assert not pattern.search(text),f"{file}: possible operational deployment/privileged action {pattern.pattern}"
 print("PASS conservative CI-only workflow scan:",file.name)

routes=(repo/"apps/sites/routes/web.php").read_text(encoding="utf-8")
for expected in ["PHP_SAPI !== 'cli'","environment('testing')","SITES_SYNTHETIC_HTTP","new DenyingReplayStore","new DenyingPortalProjectAuthorityGateway"]:
 assert expected in routes, f"Missing synthetic route safeguard: {expected}"
print("PASS in-process lab route remains test-only with DENY defaults")

for path in repo.rglob("*"):
 if not path.is_file(): continue
 if ".git" in path.parts: continue
 if path.name in {"wrangler.toml","wrangler.jsonc","wrangler.json","firebase.json","server.key","private.key"}:
  raise AssertionError(f"Unexpected live deployment or secret file: {path}")
 if path.suffix.lower() in {".p12",".pfx",".pem",".key"}:
  raise AssertionError(f"Potential credential file not allowed in lab repository: {path}")
print("PASS no conventional live-deploy configuration or private key files")
print(f"PASS {len(gates['gates'])} readiness gates remain BLOCKED; does not authorize real release")
