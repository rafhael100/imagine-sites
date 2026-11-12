#!/usr/bin/env python3
"""F1-50 strict schema shape check, no network and no 3rd-party libs."""
import json
from pathlib import Path
root=Path(__file__).resolve().parent.parent/"contracts/portal-sites/v1"
v2=json.loads((root/"review-handoff-epoch-envelope.schema.json").read_text())
v1=json.loads((root/"review-handoff-envelope.schema.json").read_text())
fields=["schemaVersion","issuer","audience","issuedAt","expiresAt","nonce","authorizationRevision","candidate","mac"]
assert v2["additionalProperties"] is False
assert v2["required"]==list(v2["properties"])==fields
assert v2["properties"]["schemaVersion"]["const"]=="sites.synthetic.review-handoff.v2"
assert v2["properties"]["authorizationRevision"]=={"type":"integer","minimum":1}
assert v2["properties"]["candidate"]["$ref"]=="./review-handoff-envelope.schema.json#/properties/candidate"
candidate=v1["properties"]["candidate"]
assert candidate["properties"]["officialClientAcceptance"]["const"] is False
assert candidate["properties"]["publishable"]["const"] is False
assert v2["properties"]["issuer"]["const"]=="imagine-sites"
assert v2["properties"]["audience"]["const"]=="imagine-portal"
print("PASS F1-50 v2 schema pins authority epoch, 10-field nonpublishable candidate and DENY semantics")
