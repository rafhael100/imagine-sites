#!/usr/bin/env python3
"""Static F1-18 contract checks; no network or extra dependencies."""
import json
from pathlib import Path
root=Path(__file__).resolve().parent.parent / "contracts/portal-sites/v1"
def load(name): return json.loads((root/name).read_text(encoding="utf-8"))
signed=load("candidate-envelope.schema.json")
command=load("create-project.schema.json")
api=load("openapi.candidate.json")
events=load("asyncapi.candidate.json")
count=0
def test(cond,msg):
 global count
 if not cond: raise AssertionError("FAIL "+msg)
 count+=1
 print("PASS "+msg)
fields=["schemaVersion","issuer","audience","issuedAt","expiresAt","nonce","payload","mac"]
test(signed["required"]==fields and list(signed["properties"])==fields,"signed envelope has exact F1-11 field order")
test(signed["additionalProperties"] is False,"no unexpected envelope fields")
test(signed["properties"]["payload"]["$ref"]=="./create-project.schema.json","payload references CreateProject source")
test(command["required"]==["schemaVersion","operationId","tenantRef","orderRef","contractRef","contractRevision","productCode","idempotencyKey"],"CreateProject v1 contract stable")
test(signed["properties"]["issuer"]["const"]=="imagine-portal","only Portal issuer")
test(signed["properties"]["audience"]["const"]=="imagine-sites","only Sites audience")
test(api["openapi"]=="3.1.0" and api["servers"]==[],"OpenAPI with zero operational servers")
test(api["security"]==[{"MutualTLS":[]}],"all operations require candidate transport identity")
test(api["components"]["securitySchemes"]["MutualTLS"]["type"]=="mutualTLS","mTLS design proposed, pending AppSec")
paths=api["paths"]
test(len(paths)==6,"six distinct control-plane resources")
test("/v1/releases/{releaseRef}/acceptances" in paths,"explicit approval resource")
test("/v1/releases/{releaseRef}/publication-requests" in paths,"separate publish command")
test("/v1/publication-operations/{operationRef}" in paths,"technical ACK/observed status separate")
test("/v1/authorizations/revoke" in paths,"revocation resource versioned")
ops=[(k,v) for path in paths.values() for k,v in path.items()]
test(all(v.get("security",api["security"])!=[] for _,v in ops),"no anonymous operations")
writes=[v for k,v in ops if k=="post"]
test(all(set(map(str,v["responses"].keys())) >= {"202","401","403","409","422","503"} for v in writes),"mutations document denial and conflict codes")
test(all("NOT" in v["responses"]["202"]["description"] for v in writes),"202 is never a completed effect")
test(all(v["requestBody"]["required"] for v in writes),"all write operations demand body")
test(events["asyncapi"]=="3.0.0" and events["servers"]=={},"no operational event broker")
test(len(events["channels"])==len(events["operations"])==5,"five versioned event types")
e=events["components"]["messages"]["SiteEvent"]["payload"]
test(e["additionalProperties"] is False and "payload" not in e["properties"],"no inline customer personal payload")
test("resourceRevision" in e["required"],"events contain revision")
types=[v["address"] for v in events["channels"].values()]
test(e["properties"]["eventType"]["enum"]==types,"event type enumeration matches channels")
test("sites.delivery.eligible.v1" in types and "sites.authorization.revoked.v1" in types,"delivery and revocation distinct")
print(f"\\n{count} static candidate-contract checks passed: not an API integration or security certification.")
