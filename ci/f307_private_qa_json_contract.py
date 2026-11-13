#!/usr/bin/env python3
"""F1-307: independently cross-check local synthetic QA JSON and pinned files.

CLI/testing only. Never treats local digests, reviewer checks or flags as
Portal identity, customer acceptance, WCAG certification or release authority.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
from pathlib import Path

ROOT_KEYS = [
    "schemaVersion", "syntheticOnly", "readOnly", "trustedPins",
    "officialClientAcceptance", "releaseAuthorized", "verifiedLocalBytes",
    "product", "artifactDigest", "bundleDigest", "files", "structuralQa",
]
QA_KEYS = [
    "inert_markup", "sha256_consistent", "lang_and_title",
    "main_and_heading", "navigation_anchors", "section_heading_labels",
    "link_accessible_names", "mobile_and_noindex", "no_executable_elements",
    "faq_disclosure_semantics",
]
FIXED_FILES = (("index.html", 150000), ("release-manifest.json", 8192))


def reject_duplicate(pairs: list[tuple[str, object]]) -> dict:
    out = {}
    for key, value in pairs:
        if key in out:
            raise ValueError("duplicate QA evidence key")
        out[key] = value
    return out


def deny_constant(value: str) -> None:
    raise ValueError("nonstandard JSON denied")


def read_regular_private(path: Path, max_bytes: int) -> bytes:
    original = os.lstat(path)
    if not stat.S_ISREG(original.st_mode) or original.st_nlink != 1:
        raise ValueError("private evidence file is not single-linked regular")
    if not (0 < original.st_size <= max_bytes):
        raise ValueError("private evidence file exceeds fixed size bound")
    with path.open("rb") as handle:
        opened = os.fstat(handle.fileno())
        if (opened.st_dev, opened.st_ino, opened.st_size) != (
            original.st_dev, original.st_ino, original.st_size
        ):
            raise ValueError("private inode changed before read")
        data = handle.read(max_bytes + 1)
        now = os.fstat(handle.fileno())
        named = os.lstat(path)
        if any(
            (x.st_dev, x.st_ino, x.st_size, x.st_nlink) != (
                original.st_dev, original.st_ino, original.st_size, 1
            )
            for x in (now, named)
        ):
            raise ValueError("private inode changed during evidence check")
    if len(data) != original.st_size:
        raise ValueError("private evidence read incomplete")
    return data


def verify(raw: bytes, private_dir: Path, product: str,
           expected_artifact: str, expected_bundle: str) -> None:
    if product not in ("onepage", "landing_page"):
        raise ValueError("unsupported fictional product")
    if not all(re.fullmatch(r"[0-9a-f]{64}", item)
               for item in (expected_artifact, expected_bundle)):
        raise ValueError("invalid external lab digests")
    if not (0 < len(raw) <= 4096):
        raise ValueError("QA JSON exceeds fixed size bound")
    doc = json.loads(raw.decode("utf-8"), object_pairs_hook=reject_duplicate,
                     parse_constant=deny_constant)
    if not isinstance(doc, dict) or list(doc) != ROOT_KEYS:
        raise ValueError("unexpected QA evidence structure or key order")
    for key in ("syntheticOnly", "readOnly", "verifiedLocalBytes"):
        if doc[key] is not True:
            raise ValueError("QA evidence must be local, synthetic and read-only")
    for key in ("trustedPins", "officialClientAcceptance", "releaseAuthorized"):
        if doc[key] is not False:
            raise ValueError("QA evidence claimed operational authority")
    if doc["schemaVersion"] != "sites.synthetic.private-bundle-evidence.v1":
        raise ValueError("unexpected QA evidence version")
    if doc["product"] != product or doc["artifactDigest"] != expected_artifact:
        raise ValueError("evidence product or HTML digest mismatch")
    if doc["bundleDigest"] != expected_bundle:
        raise ValueError("evidence bundle digest mismatch")
    qa = doc["structuralQa"]
    if not isinstance(qa, dict) or list(qa) != [
        "passed", "checks", "humanReviewRequired", "wcagCertified"
    ]:
        raise ValueError("unexpected QA result structure")
    if qa["passed"] is not True or qa["humanReviewRequired"] is not True or (
        qa["wcagCertified"] is not False
    ):
        raise ValueError("QA check claims authority or failed")
    checks = qa["checks"]
    if not isinstance(checks, dict) or list(checks) != QA_KEYS or any(
        value is not True for value in checks.values()
    ):
        raise ValueError("all ten structural checks must pass exactly")
    if private_dir.is_symlink() or not private_dir.is_dir():
        raise ValueError("missing or unsafe private fixture directory")
    if sorted(p.name for p in private_dir.iterdir()) != sorted(
        name for name, _ in FIXED_FILES
    ):
        raise ValueError("unexpected private files")
    expected_files = doc["files"]
    if not isinstance(expected_files, list) or len(expected_files) != 2:
        raise ValueError("wrong evidence file count")
    chunks = []
    for index, (name, limit) in enumerate(FIXED_FILES):
        item = expected_files[index]
        if not isinstance(item, dict) or list(item) != ["name", "bytes", "sha256"]:
            raise ValueError("unexpected file evidence structure")
        data = read_regular_private(private_dir / name, limit)
        digest = hashlib.sha256(data).hexdigest()
        if (item["name"] != name or type(item["bytes"]) is not int or
            item["bytes"] != len(data) or item["sha256"] != digest):
            raise ValueError("local file bytes disagree with reported evidence")
        chunks.append(data)
    if hashlib.sha256(chunks[0]).hexdigest() != expected_artifact:
        raise ValueError("actual HTML digest mismatch")
    if hashlib.sha256(chunks[0] + bytes([0]) + chunks[1]).hexdigest() != expected_bundle:
        raise ValueError("actual bundle digest mismatch")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", required=True, type=Path)
    parser.add_argument("--directory", required=True, type=Path)
    parser.add_argument("--product", required=True)
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--bundle", required=True)
    args = parser.parse_args()
    verify(args.report.read_bytes(), args.directory, args.product,
           args.artifact, args.bundle)
    print("PASS F1-307: independently verified private synthetic QA evidence, NO-GO")


if __name__ == "__main__":
    main()
