#!/usr/bin/env python3
"""F1-292: offline drift gate for documented v3-only Portal -> Sites contract.

No requests, schema downloads, credentials, client data, or activated API.
The schema checks below are structural; runtime PHP tests enforce semantics.
"""
from __future__ import annotations

import json
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parent.parent
CONTRACT=ROOT/"contracts/portal-sites/v1"
SOURCE=ROOT/"src/Security/SyntheticCommercialEpochSignedCommand.php"

def require(ok:bool,why:str)->None:
    if not ok:
        raise AssertionError("F1-292: "+why)

def read(name:str)->dict:
    return json.loads((CONTRACT/name).read_text(encoding="utf-8"))

v3=read("commercial-epoch-envelope.v3.candidate.schema.json")
v1=read("candidate-envelope.schema.json")
openapi=read("openapi.v3.candidate.json")
old=read("openapi.candidate.json")
command=read("create-project.schema.json")
required=[
    "schemaVersion","issuer","audience","issuedAt","expiresAt",
    "nonce","payload","keyEpoch","commercialEpoch","mac",
]
src=SOURCE.read_text(encoding="utf-8")
match=re.search(r"private const FIELDS=\[\s*(.*?)\];",src,re.S)
require(match is not None,"v3 runtime FIELDS must be present")
runtime_fields=re.findall(r"'([^']+)'",match.group(1))
require(runtime_fields==required,"documented v3 key order must equal PHP runtime")
require(v3.get("$schema")=="https://json-schema.org/draft/2020-12/schema",
        "JSON Schema 2020-12 is explicit")
require(v3.get("type")=="object" and v3.get("additionalProperties") is False,
        "envelope must be a strict object")
require(v3.get("required")==required and list(v3["properties"])==required,
        "v3 declared key list/order must match signer")
require(v3["properties"]["schemaVersion"]=={"const":"3"},
        "v3 must not admit v1 or v2")
require(v3["properties"]["issuer"]=={"const":"imagine-portal"} and
        v3["properties"]["audience"]=={"const":"imagine-sites"},
        "no caller-defined issuer or audience")
for key in ("issuedAt","expiresAt","keyEpoch","commercialEpoch"):
    require(v3["properties"][key]=={"type":"integer","minimum":1},
            key+" must be positive integer")
require(v3["properties"]["nonce"]==v1["properties"]["nonce"],
        "nonce grammar must match signed runtime v1 foundation")
require(v3["properties"]["mac"]==v1["properties"]["mac"],
        "lowercase hex SHA256 MAC remains restricted")
require(v3["properties"]["payload"]=={"$ref":"./create-project.schema.json"},
        "authenticated CreateProject v1 payload remains separate")
require(command["required"]==[
    "schemaVersion","operationId","tenantRef","orderRef","contractRef",
    "contractRevision","productCode","idempotencyKey"],
    "payload keeps current eight-field CreateProject resource v1")
require(command["additionalProperties"] is False,
        "payload cannot admit extra fields")
require(v3.get("x-imagine-operational") is False,
        "schema never grants live authentication")
require("no implicit fallback to v1 or v2" in v3.get("x-imagine-semantic-gates",[]),
        "explicitly document fail-closed no downgrade")
require(openapi.get("openapi")=="3.1.0" and openapi["servers"]==[],
        "new spec is unpublished with no network host")
require(openapi.get("x-imagine-operational") is False,
        "new spec is explicitly non-operational")
require(openapi["security"]==[{"MutualTLS":[]}],
        "S2S transport requirement remains explicit, not silently removed")
require(openapi["components"]["securitySchemes"]["MutualTLS"]["type"]=="mutualTLS",
        "do not confuse a candidate transport definition with deployed mTLS")
create=openapi["paths"]["/v1/projects"]["post"]
require(create["operationId"]=="createSiteProject",
        "v3 is same logical CreateProject v1 resource")
require(create["requestBody"]["content"]["application/json"]["schema"]==
        {"$ref":"./commercial-epoch-envelope.v3.candidate.schema.json"},
        "new CreateProject request must point ONLY to signed v3")
require(create.get("x-imagine-legacy-envelope-fallback") is False,
        "no transparent downgrade to v1/v2")
require({"202","401","403","409","422","503"}.issubset(create["responses"]),
        "non-operational candidate retains failure codes")
require("NOT project creation" in create["responses"]["202"]["description"],
        "HTTP 202 is not a publication or acceptance proof")
require(old["paths"]["/v1/projects"]["post"]["requestBody"]
        ["content"]["application/json"]["schema"]==
        {"$ref":"./candidate-envelope.schema.json"},
        "historical v1 reference stays explicit rather than stealth-edited")
require(v1["properties"]["schemaVersion"]=={"const":"1"},
        "historical v1 is not accidentally mutated")
require(all(isinstance(x,str) and x for x in
        create["x-imagine-required-preconditions"]),
        "document explicit issuer, epoch, nonce, tenant and authority duties")
# This check is intentionally a DRIFT/SPEC audit, not a JSON-Schema
# validation engine. The paired PHP test checks actual signature behavior.
print("PASS F1-292 strict v3 OpenAPI/JSON Schema vs PHP source, no fallback or host.")
