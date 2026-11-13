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


def file_identity(info: os.stat_result) -> tuple[int, int, int, int, int]:
    """Identity and safety fields used for before/after descriptor checks."""
    return (info.st_dev, info.st_ino, info.st_nlink,
            info.st_size, stat.S_IFMT(info.st_mode))


def require_regular(info: os.stat_result, max_bytes: int) -> None:
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or
            not (0 < info.st_size <= max_bytes)):
        raise ValueError("not a single-linked, bounded regular file")


def open_private_directory(directory: Path) -> int:
    """Pin the directory inode; reject a final-component symlink."""
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    fd = os.open(directory, flags)
    try:
        opened = os.fstat(fd)
        named = os.stat(directory, follow_symlinks=False)
        if (not stat.S_ISDIR(opened.st_mode) or
                file_identity(opened) != file_identity(named)):
            raise ValueError("private directory changed while opening")
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_regular_private(directory_fd: int, filename: str, max_bytes: int) -> bytes:
    """F1-308: no symlink traversal or potentially blocking FIFO open.

    Access the named inode *relative to a pinned directory descriptor* and
    compare its metadata against the opened file before and after reading.
    """
    if filename not in ("index.html", "release-manifest.json", "evidence.json") and (
        re.fullmatch(r"f307-(?:onepage|landing_page)\.json", filename) is None
    ):
        raise ValueError("untrusted evidence filename")
    before = os.stat(filename, dir_fd=directory_fd, follow_symlinks=False)
    require_regular(before, max_bytes)
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    fd = os.open(filename, flags, dir_fd=directory_fd)
    try:
        opened = os.fstat(fd)
        require_regular(opened, max_bytes)
        if file_identity(opened) != file_identity(before):
            raise ValueError("file swapped between stat and open")
        chunks = []
        consumed = 0
        while consumed <= max_bytes:
            block = os.read(fd, max_bytes + 1 - consumed)
            if not block:
                break
            chunks.append(block)
            consumed += len(block)
        data = b"".join(chunks)
        after = os.fstat(fd)
        named = os.stat(filename, dir_fd=directory_fd, follow_symlinks=False)
        require_regular(after, max_bytes)
        require_regular(named, max_bytes)
        if (file_identity(after) != file_identity(before) or
                file_identity(named) != file_identity(before) or
                len(data) != before.st_size):
            raise ValueError("file inode, size or content length changed")
        return data
    finally:
        os.close(fd)


def read_bounded_report(path: Path) -> bytes:
    """F1-309: do not read unbounded or linked evidence before JSON parsing."""
    directory_fd = open_private_directory(path.parent)
    try:
        return read_regular_private(directory_fd, path.name, 4096)
    finally:
        os.close(directory_fd)


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
    dir_fd = open_private_directory(private_dir)
    try:
        if sorted(os.listdir(dir_fd)) != sorted(name for name, _ in FIXED_FILES):
            raise ValueError("unexpected private files")

        expected_files = doc["files"]
        if not isinstance(expected_files, list) or len(expected_files) != 2:
            raise ValueError("wrong evidence file count")
        chunks = []
        for index, (name, limit) in enumerate(FIXED_FILES):
            item = expected_files[index]
            if not isinstance(item, dict) or list(item) != ["name", "bytes", "sha256"]:
                raise ValueError("unexpected file evidence structure")
            data = read_regular_private(dir_fd, name, limit)
            digest = hashlib.sha256(data).hexdigest()
            if (item["name"] != name or type(item["bytes"]) is not int or
                item["bytes"] != len(data) or item["sha256"] != digest):
                raise ValueError("local file bytes disagree with reported evidence")
            chunks.append(data)
        opened = os.fstat(dir_fd)
        named = os.stat(private_dir, follow_symlinks=False)
        if (not stat.S_ISDIR(named.st_mode) or
                file_identity(opened) != file_identity(named)):
            raise ValueError("private directory replaced during readback")
    finally:
        os.close(dir_fd)
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
    verify(read_bounded_report(args.report), args.directory, args.product,
           args.artifact, args.bundle)
    print("PASS F1-307: independently verified private synthetic QA evidence, NO-GO")


if __name__ == "__main__":
    main()
