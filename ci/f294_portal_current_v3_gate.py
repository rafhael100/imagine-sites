#!/usr/bin/env python3
"""F1-294: fail-closed Portal/Sites v3 producer compatibility inventory.

Read only TWO separately pinned, public Portal source trees. It is deliberately
IMPOSSIBLE for this gate to authorize production, even if both expose v3.
No HTTP endpoint, runtime service, key, PII or active identity is accessed.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

CURRENT_SHA = "47514f4eac64a3d7ab0a0924cc890fb5a124d8b8"
HISTORICAL_SHA = "8d1e20bc5c39dc4106b549c5e979351b5c766615"
HISTORICAL_SIGNER_BLOB = "e797e3b9829ae8fdb97156f87142f8f12228db08"
REL = Path("apps/portal/app/Domain/SitesIntegration")
SIGNER = "SyntheticSitesCommercialEpochSignedEnvelopeSigner.php"
ISSUER = "LabPortalSitesVersionedIssuer.php"

def require(ok:bool, message:str)->None:
    if not ok:
        raise ValueError("F1-294 " + message)

def git_sha(directory:Path)->str:
    proc=subprocess.run(
        ["git","-C",str(directory),"rev-parse","HEAD"],
        capture_output=True,text=True,check=True,timeout=10
    )
    return proc.stdout.strip()

def read_historical(history:Path)->None:
    signer=history/REL/SIGNER
    issuer=history/REL/ISSUER
    for f in (signer,issuer):
        require(f.is_file() and not f.is_symlink(),
                "historical pinned signer or issuer is absent")
    data=signer.read_bytes()
    git_blob=hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest()
    require(git_blob==HISTORICAL_SIGNER_BLOB,
            "historical signer blob differs from previously audited candidate")
    code=data.decode("utf-8")
    source=issuer.read_text(encoding="utf-8")
    require("class SyntheticSitesCommercialEpochSignedEnvelopeSigner" in code
            and "commercialEpoch" in code and "hash_hmac(" in code,
            "historical v3 signer lacks expected technical fields")
    require("issueCommercialEpochSigned(" in source,
            "historical issuer does not expose the v3 synthetic method")

def inspect_current(current:Path)->dict:
    expected=current/REL/SIGNER
    issuer=current/REL/ISSUER
    found=expected.is_file() and not expected.is_symlink()
    present_issuer=issuer.is_file() and not issuer.is_symlink()
    # The official release review must independently establish provenance,
    # authority and trusted transport even when source files exist.
    return {
        "schemaVersion":"sites.portal-current-v3-capability.v1",
        "currentPortalRef":CURRENT_SHA,
        "historicalCandidateRef":HISTORICAL_SHA,
        "currentExpectedV3SignerPresent":found,
        "currentExpectedIssuerPresent":present_issuer,
        "historicalCandidateV3SignerVerified":True,
        "capabilityDiagnostic":(
            "BLOCKED_UNREVIEWED_CURRENT_V3_IMPLEMENTATION"
            if found and present_issuer else
            "BLOCKED_CURRENT_V3_NOT_ESTABLISHED"
        ),
        "portalCurrentV3Operational":False,
        "portalSignedEnvelopeV3Homologated":False,
        "sitesMayConnectToPortal":False,
        "sitesMayCreateRealProject":False,
        "releaseAuthorized":False,
        "manualNextStep":"Review current Portal issuer and commercial authority in the same approved integration SHA",
    }

def self_test()->None:
    with tempfile.TemporaryDirectory(prefix="sites-f294-test-") as tmp:
        root=Path(tmp)
        r=inspect_current(root)
        require(r["capabilityDiagnostic"]=="BLOCKED_CURRENT_V3_NOT_ESTABLISHED",
                "missing signer does not fail closed")
        require(all(r[key] is False for key in (
            "portalCurrentV3Operational","portalSignedEnvelopeV3Homologated",
            "sitesMayConnectToPortal","sitesMayCreateRealProject","releaseAuthorized")),
            "missing signer cannot grant anything")
        d=root/REL
        d.mkdir(parents=True)
        (d/SIGNER).write_text("synthetic candidate",encoding="utf-8")
        r=inspect_current(root)
        require(r["capabilityDiagnostic"]=="BLOCKED_CURRENT_V3_NOT_ESTABLISHED",
                "issuer missing must deny")
        (d/ISSUER).write_text("synthetic candidate",encoding="utf-8")
        r=inspect_current(root)
        require(r["capabilityDiagnostic"]=="BLOCKED_UNREVIEWED_CURRENT_V3_IMPLEMENTATION",
                "presence alone must not authorize")
        require(r["releaseAuthorized"] is False and
                r["sitesMayCreateRealProject"] is False,
                "source presence is not a trust decision")
    print("PASS F1-294: missing/partial/present Portal signer always NO-GO")

def main()->None:
    parser=argparse.ArgumentParser()
    parser.add_argument("--current",type=Path)
    parser.add_argument("--history",type=Path)
    parser.add_argument("--self-test",action="store_true")
    args=parser.parse_args()
    if args.self_test:
        self_test()
        return
    require(args.current is not None and args.history is not None,
            "two source roots are mandatory")
    current=args.current.resolve(strict=True)
    history=args.history.resolve(strict=True)
    require(current!=history,"current and history must be separate Git checkouts")
    require(git_sha(current)==CURRENT_SHA,"pinned current Portal SHA mismatch")
    require(git_sha(history)==HISTORICAL_SHA,"pinned historical Portal SHA mismatch")
    read_historical(history)
    report=inspect_current(current)
    require(report["releaseAuthorized"] is False and
            report["sitesMayConnectToPortal"] is False,
            "report must be a hard production denial")
    print(json.dumps(report,ensure_ascii=False,sort_keys=True))
    print("PASS F1-294 exact pinned source comparison, current Portal v3 NOT approved.")

if __name__=="__main__":
    main()
