#!/usr/bin/env python3
"""F1-330: explicit lab-only recovery into a NEW private fixture directory.

No overwrite, ZIP extraction, path traversal, publication, provider calls,
customer data, credentials or production import. Linux CI scratch only.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import sys

from f307_private_qa_json_contract import (
    FIXED_FILES, file_identity, open_private_directory,
)
from f329_private_synthetic_zip import (
    check_files, check_pins, read_owner_regular, read_private_bundle,
    sha, verify_zip,
)

LAB_FLAG = "SITES_SYNTHETIC_RESTORE"
EXPECTED_FILES = [name for name, _ in FIXED_FILES]


def ensure_lab() -> None:
    if (sys.platform != "linux" or os.environ.get("APP_ENV") != "testing"
            or os.environ.get(LAB_FLAG) != "1"
            or (os.environ.get("GITHUB_ACTIONS") != "true" and
                os.environ.get("SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE") != "1")):
        # GitHub Actions is not an authorization source; this extra
        # local-only flag avoids accidental invocations on a developer VPS.
        raise ValueError("offline synthetic restore flags missing")


def restore(archive_path: Path, parent: Path, target_name: str,
            product: str, artifact: str, bundle: str) -> dict[str, object]:
    ensure_lab()
    check_pins(artifact, bundle)
    if product not in ("onepage", "landing_page"):
        raise ValueError("unknown laboratory product")
    if target_name != "restored-f330-" + product:
        raise ValueError("fixed one-off lab target name required")
    # Prove in-memory archive bytes before touching any destination directory.
    raw_archive = read_owner_regular(archive_path)
    files = verify_zip(raw_archive, product, artifact, bundle)
    parent_fd = open_private_directory(parent)
    try:
        stat_parent = os.fstat(parent_fd)
        if (not stat.S_ISDIR(stat_parent.st_mode)
                or stat_parent.st_mode & 0o077):
            raise ValueError("destination parent must be owner-private")
        if target_name in os.listdir(parent_fd):
            raise ValueError("restored directory already exists")
        # mkdir is exclusive even if the target becomes a symlink/dir
        # between the listing and creation. NEVER write existing fixtures.
        os.mkdir(target_name, mode=0o700, dir_fd=parent_fd)
        child_fd = os.open(
            target_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW |
            os.O_CLOEXEC, dir_fd=parent_fd,
        )
        try:
            child_before = os.fstat(child_fd)
            if (child_before.st_mode & 0o077
                    or file_identity(child_before) != file_identity(
                        os.stat(target_name, dir_fd=parent_fd,
                                follow_symlinks=False))):
                raise ValueError("private recovery directory replaced")
            for name in EXPECTED_FILES:
                payload = files[name]
                fd = os.open(
                    name, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                    os.O_NOFOLLOW | os.O_CLOEXEC,
                    mode=0o600, dir_fd=child_fd,
                )
                try:
                    at = 0
                    while at < len(payload):
                        count = os.write(fd, payload[at:])
                        if count <= 0:
                            raise OSError("partial private restore write")
                        at += count
                    os.fsync(fd)
                    written = os.fstat(fd)
                    named = os.stat(name, dir_fd=child_fd, follow_symlinks=False)
                    if (not stat.S_ISREG(written.st_mode)
                            or written.st_mode & 0o077
                            or written.st_nlink != 1
                            or file_identity(written) != file_identity(named)):
                        raise ValueError("restored private inode changed")
                finally:
                    os.close(fd)
            os.fsync(child_fd)
            if (file_identity(os.fstat(child_fd)) != file_identity(
                    os.stat(target_name, dir_fd=parent_fd,
                            follow_symlinks=False))):
                raise ValueError("restored directory substituted")
        finally:
            os.close(child_fd)
        # Separate fully bounded readback: a syntactically valid archive
        # cannot mark success unless both *written bytes* match pins.
        recovered = read_private_bundle(parent / target_name)
        if recovered != files:
            raise ValueError("recovered bundle bytes differ from original")
        check_files(recovered, product, artifact, bundle)
        os.fsync(parent_fd)
        return {
            "schemaVersion": "sites.synthetic.private-restore-evidence.v1",
            "product": product,
            "archiveSha256": sha(raw_archive),
            "artifactDigest": artifact,
            "bundleDigest": bundle,
            "syntheticOnly": True,
            "labFilesRecovered": True,
            "operationalRestore": False,
            "publishable": False,
            "officialClientAcceptance": False,
            "crmTouched": False,
        }
    except BaseException:
        # Intentional fail-closed quarantine: a partial, private directory
        # is never exposed to HTTP or silently retried/overwritten. Manual
        # cleanup of this fictional scratch path is the operator's task.
        # No risky pathname-based recursive deletion of replaced entries.
        raise
    finally:
        os.close(parent_fd)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Restore only fictional, preverified files to new private directory"
    )
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--parent", type=Path, required=True)
    parser.add_argument("--target-name", required=True)
    parser.add_argument("--product", required=True, choices=("onepage", "landing_page"))
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--bundle", required=True)
    args = parser.parse_args()
    try:
        result = restore(
            args.archive, args.parent, args.target_name,
            args.product, args.artifact, args.bundle,
        )
    except (ValueError, OSError, KeyError, TypeError):
        print(
            "F1-330 private lab restore refused; inspect any partial "
            "fixture directory manually before retrying",
            file=sys.stderr,
        )
        raise SystemExit(2) from None
    print(json.dumps(result, separators=(",", ":")))


if __name__ == "__main__":
    main()
