#!/usr/bin/env python3
"""F1-21 conservative safeguard against accidental operational CI workflows.
This is NOT a security certification; review workflow behavior manually too.
"""
import json
import re
import tempfile
from pathlib import Path

repo=Path(__file__).resolve().parent.parent
gates=json.loads((repo/"config/operational-readiness.json").read_text(encoding="utf-8"))
assert gates["schema"]=="imagine-sites.production-readiness.v1"
assert gates["status"]=="BLOCKED"
assert gates["non_operational"] is True
assert len(gates["gates"])>=10
assert not any(gates["gates"].values()), "A gate must never be approved solely by this CI test"

# F1-242: GitHub Actions accepts BOTH .yml and .yaml; ignoring the latter
# permits a new workflow to evade the conservative CI permissions scan.
def discover_workflows(root: Path) -> list[Path]:
 files=sorted(set(root.glob("*.yml")) | set(root.glob("*.yaml")))
 for file in files:
  assert file.is_file() and not file.is_symlink(), "Workflow symlink or non-file denied"
 return files

workflows=discover_workflows(repo/".github/workflows")
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
def check_workflow(file: Path) -> None:
 text=file.read_text(encoding="utf-8")
 assert re.search(r"(?m)^permissions:\s*\n\s+contents:\s*read\s*$",text),f"{file}: minimum read-only permissions not declared"
 for pattern in danger:
  assert not pattern.search(text),f"{file}: possible operational deployment/privileged action {pattern.pattern}"

# F1-242 regression: an unsafe .yaml must NOT bypass discovery or checks.
# The fixture is a temporary file outside the repository, never a workflow.
with tempfile.TemporaryDirectory(prefix="sites-f242-scan-") as scratch:
 root=Path(scratch)
 plain="name: fictional\npermissions:\n  contents: read\n"
 (root/"safe.yml").write_text(plain,encoding="utf-8")
 (root/"safe.yaml").write_text(plain,encoding="utf-8")
 (root/"unsafe.yaml").write_text(
  plain+"jobs:\n  fake:\n    uses: cloudflare/wrangler-action@v3\n",encoding="utf-8")
 found=discover_workflows(root)
 assert {p.name for p in found}=={"safe.yml","safe.yaml","unsafe.yaml"}
 for path in found:
  if path.name!="unsafe.yaml":
   check_workflow(path)
  else:
   try:
    check_workflow(path)
   except AssertionError:
    pass
   else:
    raise AssertionError("F1-242 allowed deploy action in .yaml workflow")
 print("PASS F1-242: .yml AND .yaml are scanned; unsafe .yaml denied")

for file in workflows:
 check_workflow(file)
 print("PASS conservative CI-only workflow scan:",file.name)

routes=(repo/"apps/sites/routes/web.php").read_text(encoding="utf-8")
for expected in ["PHP_SAPI !== 'cli'","environment('testing')","SITES_SYNTHETIC_HTTP","new DenyingReplayStore","new DenyingPortalProjectAuthorityGateway"]:
 assert expected in routes, f"Missing synthetic route safeguard: {expected}"
print("PASS in-process lab route remains test-only with DENY defaults")

# F1-233: route registration checks are not sufficient when a test-generated
# Laravel route cache is reused by a different runtime. Require a GLOBAL,
# request-time middleware fence on all synthetic routes.
bootstrap=(repo/"apps/sites/bootstrap/app.php").read_text(encoding="utf-8")
route_fence=(repo/"apps/sites/app/Http/Middleware/SyntheticLabRouteFence.php").read_text(encoding="utf-8")
assert "$middleware->append(\\App\\Http\\Middleware\\SyntheticLabRouteFence::class)" in bootstrap, "Global lab route guard removed from bootstrap"
for fragment in [
    "_lab/sites/v1", "PHP_SAPI!=='cli'",
    "environment('testing')", "SITES_SYNTHETIC_HTTP",
    "REMOTE_ADDR", "127.0.0.1", "Not found',404",
]:
    assert fragment in route_fence, f"Missing cached-route request-time guard: {fragment}"
print("PASS cached synthetic routes need global request-time CLI/testing/loopback fence")

for path in repo.rglob("*"):
 if not path.is_file(): continue
 if ".git" in path.parts: continue
 if path.name in {"wrangler.toml","wrangler.jsonc","wrangler.json","firebase.json","server.key","private.key"}:
  raise AssertionError(f"Unexpected live deployment or secret file: {path}")
 if path.suffix.lower() in {".p12",".pfx",".pem",".key"}:
  raise AssertionError(f"Potential credential file not allowed in lab repository: {path}")
print("PASS no conventional live-deploy configuration or private key files")
print(f"PASS {len(gates['gates'])} readiness gates remain BLOCKED; does not authorize real release")
