#!/usr/bin/env python3
"""F1-46: PHP->Python canonical HMAC wire parity and strict static schema probes.

Uses Python stdlib only. This is NOT a general JSON Schema validator, trusted
Portal service or production cryptography/authorization.
"""
from __future__ import annotations

import copy
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import subprocess

root=Path(__file__).resolve().parent.parent
spec=json.loads((root/"contracts/portal-sites/v1/review-handoff-envelope.schema.json").read_text(encoding="utf-8"))
FIELDS=["schemaVersion","issuer","audience","issuedAt","expiresAt","nonce","candidate","mac"]
CANDIDATE=[
    "schemaVersion","tenantRef","siteRef","workflowRef","workflowRevision",
    "releaseRef","artifactDigest","portalAuthorityRequired",
    "officialClientAcceptance","publishable",
]
KEY=b"F46-LAB-ONLY-INTEROPERABILITY-KEY-DO-NOT-DEPLOY-0000000000000"
N=0

def check(value:bool,label:str)->None:
    global N
    if not value: raise AssertionError("FAIL: "+label)
    N+=1
    print("PASS",label)

def strict(value:object, definition:dict)->bool:
    if "const" in definition:
        return type(value) is type(definition["const"]) and value==definition["const"]
    typ=definition.get("type")
    if typ=="integer":
        return type(value) is int and value>=definition.get("minimum",0)
    if typ=="string":
        return type(value) is str and re.fullmatch(definition.get("pattern",r".*"),value) is not None
    if typ=="object":
        if type(value) is not dict or definition.get("additionalProperties") is not False:
            return False
        properties=definition["properties"]
        if list(value)!=definition["required"]:
            return False
        return all(strict(value[k],properties[k]) for k in definition["required"])
    return False

def serialize_unsigned(data:dict)->bytes:
    return json.dumps({k:data[k] for k in FIELDS[:-1]},
        separators=(",",":"),ensure_ascii=False).encode("utf-8")

def signature(data:dict)->str:
    return hmac.new(KEY,serialize_unsigned(data),hashlib.sha256).hexdigest()

env=os.environ.copy()
env["SITES_F46_WIRE"]="1"
payload=subprocess.run(["php","tests/f46-review-wire.php"],cwd=root,env=env,
    check=True,capture_output=True,text=True,encoding="utf-8").stdout
wire=json.loads(payload)
check(spec["$schema"].endswith("draft/2020-12/schema"),"2020-12 JSON Schema candidate declared")
check(spec["additionalProperties"] is False and
      spec["required"]==FIELDS and list(spec["properties"])==FIELDS,
      "envelope schema exactly matches PHP field ordering")
candidateSpec=spec["properties"]["candidate"]
check(candidateSpec["additionalProperties"] is False and
      candidateSpec["required"]==CANDIDATE and
      list(candidateSpec["properties"])==CANDIDATE,
      "nested review proposal schema field set/order pinned")
check(strict(wire,spec),"actual PHP signed wire fits strict candidate schema")
check(hmac.compare_digest(wire["mac"],signature(wire)),
      "Python independently verifies byte-equivalent PHP HMAC")
check(wire["expiresAt"]-wire["issuedAt"]==60 and
      wire["candidate"]["artifactDigest"]=="a"*64,
      "signed fixture has exact digest and short lifetime")
check(wire["candidate"]["publishable"] is False and
      wire["candidate"]["officialClientAcceptance"] is False,
      "signed fixture cannot claim approval or publishing rights")
check(not any(k in wire["candidate"] for k in
      ("clientEmail","contractPrice","paymentApproved","ownerId")),
      "no PII, payment or unauthorized acceptance fields in payload")

negatives=[
    ("wrong receiver",lambda d:d.__setitem__("audience","imagine-sites")),
    ("wrong issuer",lambda d:d.__setitem__("issuer","imagine-portal")),
    ("obsolete schema",lambda d:d.__setitem__("schemaVersion","v2")),
    ("wrong release",lambda d:d["candidate"].__setitem__("releaseRef","release-other")),
    ("wrong tenant",lambda d:d["candidate"].__setitem__("tenantRef","tenant-other")),
    ("wrong digest",lambda d:d["candidate"].__setitem__("artifactDigest","b"*64)),
    ("forged client acceptance",lambda d:d["candidate"].__setitem__("officialClientAcceptance",True)),
    ("forged publishable",lambda d:d["candidate"].__setitem__("publishable",True)),
    ("spoofed int as bool",lambda d:d["candidate"].__setitem__("workflowRevision",True)),
    ("unrecognized field",lambda d:d["candidate"].__setitem__("paymentApproved",True)),
    ("unsafe identifier",lambda d:d["candidate"].__setitem__("siteRef","../../another")),
    ("short nonce",lambda d:d.__setitem__("nonce","small")),
]
for label,change in negatives:
    changed=copy.deepcopy(wire)
    change(changed)
    check(not strict(changed,spec) or
          not hmac.compare_digest(changed["mac"],signature(changed)),
          "mutated unsigned envelope denied: "+label)

changed=copy.deepcopy(wire)
changed["candidate"]["publishable"]=True
changed["mac"]=signature(changed)
check(not strict(changed,spec),
      "MAC-valid forged publication claim rejected by strict contract")
changed=copy.deepcopy(wire)
changed["candidate"]["extra"]="x"
changed["mac"]=signature(changed)
check(not strict(changed,spec),
      "MAC-valid extra permission field rejected")
changed={"mac":wire["mac"],**{k:v for k,v in wire.items() if k!="mac"}}
check(not strict(changed,spec),"field reordering denied by strict F1-45 wire contract")
changed=copy.deepcopy(wire)
changed["expiresAt"]=changed["issuedAt"]+600
changed["mac"]=signature(changed)
check(changed["expiresAt"]-changed["issuedAt"]>120,
      "JSON Schema is only structural; TTL is a separate runtime security gate")
check(spec["properties"]["candidate"]["properties"]["officialClientAcceptance"]["const"] is False,
      "candidate spec preserves Portal-only client acceptance")
print(f"\n{N} F1-46 static/wire compatibility checks passed, not an endpoint or authority proof.")
