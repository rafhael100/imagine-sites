#!/usr/bin/env python3
"""F1-246: compare REAL HTML release digests across PHP 8.3 and 8.4.

The two job artifacts contain only manifest.json from the identical
fictitious six-page gallery. The comparison is byte-for-byte and also
checks every digest and review gate, not only the JSON schema.
No permission to publish, no network, no customer information.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re

SCHEMA = "imagine-sites.synthetic-demo-gallery.v1"
EXPECTED = frozenset(
    f"{product}-{style}.html"
    for product in ("onepage", "landing_page")
    for style in ("classic", "modern", "warm")
)


def no_duplicates(pairs: list[tuple[str, object]]) -> dict:
    d: dict = {}
    for key, value in pairs:
        if key in d:
            raise ValueError("F1-246 duplicate manifest key denied")
        d[key] = value
    return d


def strict_manifest(source: bytes) -> dict:
    if not source or len(source) > 20000:
        raise ValueError("F1-246 invalid manifest byte length")
    try:
        data = json.loads(
            source.decode("utf-8"), object_pairs_hook=no_duplicates,
            parse_constant=lambda v: (_ for _ in ()).throw(ValueError(v)),
        )
    except (UnicodeError, json.JSONDecodeError) as ex:
        raise ValueError("F1-246 invalid manifest UTF-8/JSON") from ex
    if not isinstance(data, dict) or (
        data.get("schema") != SCHEMA
        or data.get("demoOnly") is not True
        or data.get("publishable") is not False
        or data.get("approvedForClient") is not False
        or data.get("hasContactCapture") is not False
        or data.get("internalReviewRequired") is not True
        or data.get("portalAuthorityRequired") is not True
        or data.get("draftState") != "internal_review"
        or data.get("generationPath") != "synthetic_structured_copy_to_internal_review"
        or data.get("sourceProvenanceSchema") != "sites.synthetic.draft-provenance.v1"
        or not isinstance(data.get("sourceCopySha256"), str)
        or not re.fullmatch(r"[a-f0-9]{64}",data["sourceCopySha256"])
        or not isinstance(data.get("rendererSourceSha256"), str)
        or not re.fullmatch(r"[a-f0-9]{64}",data["rendererSourceSha256"])
        or data.get("richSections") != ["highlights", "process", "faq"]
        or data.get("productCodes") != ["onepage", "landing_page"]
        or data.get("stylePresets") != ["classic", "modern", "warm"]
        or not isinstance(data.get("files"), dict)
        or set(data["files"]) != EXPECTED
        or not isinstance(data.get("gallerySha256"), str)
        or not re.fullmatch(r"[a-f0-9]{64}", data["gallerySha256"])
    ):
        raise ValueError("F1-246 manifest is not a blocked rich-v4 demo")
    for name in EXPECTED:
        info = data["files"][name]
        product, style = name[:-5].split("-", 1)
        if not isinstance(info, dict) or (
            info.get("product") != product
            or info.get("preset") != style
            or info.get("copySchema") != "sites.ai-copy.v4"
            or not isinstance(info.get("sourceProvenanceSha256"), str)
            or not re.fullmatch(r"[a-f0-9]{64}",info["sourceProvenanceSha256"])
            or not isinstance(info.get("privateBundleSha256"), str)
            or not re.fullmatch(r"[a-f0-9]{64}",info["privateBundleSha256"])
            or type(info.get("workflowRevision")) is not int
            or info["workflowRevision"] != 2
            or info.get("draftState") != "internal_review"
            or info.get("internalReviewRequired") is not True
            or info.get("portalAuthorityRequired") is not True
            or info.get("officialClientAcceptance") is not False
            or info.get("publishable") is not False
            or info.get("sections") != [
                "benefits", "highlights", "process", "faq", "contact"
            ]
            or info.get("navigation") != (
                "section_anchors" if product == "onepage" else "local_cta"
            )
            or info.get("socialMetadata") is not True
            or type(info.get("bytes")) is not int
            or not (2000 <= info["bytes"] <= 150000)
            or not isinstance(info.get("sha256"), str)
            or not re.fullmatch(r"[a-f0-9]{64}", info["sha256"])
        ):
            raise ValueError("F1-246 invalid per-page digest or review gate: " + name)
    return data


def compare(a: bytes, b: bytes) -> int:
    left = strict_manifest(a)
    right = strict_manifest(b)
    if a != b or left != right:
        raise ValueError(
            "F1-246 PHP 8.3/8.4 produced DIFFERENT gallery bytes or release digests"
        )
    # Reproducibility is defined over the COMPLETE source manifest and
    # the embedded page SHA-256 values, not unverified CI "PASS" labels.
    digest = hashlib.sha256(a).hexdigest()
    files = left["files"]
    if len({info["sha256"] for info in files.values()}) != 6:
        raise ValueError("F1-246 preview digests must identify six distinct files")
    print(
        "PASS F1-246: PHP 8.3 == 8.4 byte-exact manifest, "
        "6 HTML sha256 digests, 6 private review gates; "
        "manifest SHA-256=" + digest[:16]
    )
    return len(files)


def self_test() -> None:
    files = {}
    for i, name in enumerate(sorted(EXPECTED)):
        product, preset = name[:-5].split("-", 1)
        files[name] = {
            "product": product,
            "preset": preset,
            "sha256": f"{i+1:064x}",
            "sourceProvenanceSha256": f"{i+101:064x}",
            "privateBundleSha256": f"{i+201:064x}",
            "workflowRevision": 2,
            "bytes": 5000,
            "copySchema": "sites.ai-copy.v4",
            "draftState": "internal_review",
            "internalReviewRequired": True,
            "portalAuthorityRequired": True,
            "officialClientAcceptance": False,
            "publishable": False,
            "sections": ["benefits", "highlights", "process", "faq", "contact"],
            "navigation": "section_anchors" if product == "onepage" else "local_cta",
            "socialMetadata": True,
        }
    fake = {
        "schema": SCHEMA,
        "productCodes": ["onepage", "landing_page"],
        "stylePresets": ["classic", "modern", "warm"],
        "editorialFixture": "fixtures/sites/f1-170-process-copy.synthetic.json",
        "richSections": ["highlights", "process", "faq"],
        "generationPath": "synthetic_structured_copy_to_internal_review",
        "sourceProvenanceSchema": "sites.synthetic.draft-provenance.v1",
        "sourceCopySha256": "b"*64,
        "rendererSourceSha256": "c"*64,
        "draftState": "internal_review",
        "internalReviewRequired": True,
        "portalAuthorityRequired": True,
        "demoOnly": True,
        "approvedForClient": False,
        "publishable": False,
        "hasContactCapture": False,
        "gallerySha256": "a" * 64,
        "files": files,
    }
    raw = json.dumps(fake).encode()
    assert compare(raw, raw) == 6
    for property, badvalue in [
        ("publishable", True),
        ("approvedForClient", True),
        ("internalReviewRequired", False),
        ("portalAuthorityRequired", False),
    ]:
        bad = copy.deepcopy(fake)
        bad[property] = badvalue
        try:
            compare(raw, json.dumps(bad).encode())
            raise AssertionError("F1-246 authorization change accepted")
        except ValueError:
            pass
    changed = copy.deepcopy(fake)
    changed["files"]["onepage-classic.html"]["sha256"] = "f" * 64
    try:
        compare(raw, json.dumps(changed).encode())
        raise AssertionError("F1-246 divergent page bytes accepted")
    except ValueError:
        pass
    altered = copy.deepcopy(fake)
    altered["files"]["onepage-classic.html"]["sourceProvenanceSha256"] = "z"*64
    try:
        compare(raw,json.dumps(altered).encode())
        raise AssertionError("F1-248 malformed source provenance accepted")
    except ValueError:
        pass
    altered = copy.deepcopy(fake)
    altered["files"]["onepage-classic.html"]["workflowRevision"] = 3
    try:
        compare(raw,json.dumps(altered).encode())
        raise AssertionError("F1-248 stale workflow revision accepted")
    except ValueError:
        pass
    try:
        strict_manifest(b'{"schema":"x","schema":"y"}')
        raise AssertionError("F1-246 duplicate key accepted")
    except ValueError:
        pass
    print("PASS F1-246 negative tests for digest divergence and false approvals")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--left")
    parser.add_argument("--right")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    if (
        os.environ.get("GITHUB_ACTIONS") != "true"
        or not args.left
        or not args.right
    ):
        raise SystemExit("F1-246 only accepts two CI-generated manifest files")
    runner = Path(os.environ["RUNNER_TEMP"]).resolve()
    inputs = [Path(args.left).resolve(), Path(args.right).resolve()]
    for p in inputs:
        if not p.is_file() or p.is_symlink() or not p.is_relative_to(runner):
            raise RuntimeError("F1-246 manifest must live under runner temp")
    compare(inputs[0].read_bytes(), inputs[1].read_bytes())


if __name__ == "__main__":
    main()
