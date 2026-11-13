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
import secrets
import stat
import sys

from f307_private_qa_json_contract import (
    FIXED_FILES, file_identity, open_private_directory,
)
from f331_atomic_private_rename import check_platform, rename_no_replace
from f329_private_synthetic_zip import (
    check_files, check_pins, read_owner_regular, read_private_bundle,
    sha, verify_zip,
)

LAB_FLAG = "SITES_SYNTHETIC_RESTORE"
EXPECTED_FILES = [name for name, _ in FIXED_FILES]


def require_pinned_scope(
    files: dict[str, bytes], tenant_ref: str, site_ref: str,
    release_ref: str,
) -> None:
    """F1-332: independently supplied project/release identity, fail closed.

    These CLI strings are NOT authenticated grants. Never derive their values
    from the ZIP or HTTP request on behalf of a real customer.
    """
    expected = (tenant_ref, site_ref, release_ref)
    if any(not isinstance(value, str) or not re.fullmatch(
        r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", value,
    ) for value in expected):
        raise ValueError("missing or invalid pinned tenant/site/release identity")
    # verify_zip already validates the manifest structure and local digest.
    # Load only after that, then require the independent exact tuple.
    manifest = json.loads(files["release-manifest.json"].decode("utf-8"))
    actual = tuple(manifest[key] for key in ("tenantRef", "siteRef", "releaseRef"))
    if actual != expected:
        raise ValueError("private ZIP belongs to a different synthetic scope")


def ensure_lab() -> None:
    if (sys.platform != "linux" or os.environ.get("APP_ENV") != "testing"
            or os.environ.get(LAB_FLAG) != "1"
            or (os.environ.get("GITHUB_ACTIONS") != "true" and
                os.environ.get("SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE") != "1")):
        # GitHub Actions is not an authorization source; this extra
        # local-only flag avoids accidental invocations on a developer VPS.
        raise ValueError("offline synthetic restore flags missing")


def restore(archive_path: Path, parent: Path, target_name: str,
            product: str, artifact: str, bundle: str,
            tenant_ref: str, site_ref: str, release_ref: str) -> dict[str, object]:
    ensure_lab()
    check_pins(artifact, bundle)
    if product not in ("onepage", "landing_page"):
        raise ValueError("unknown laboratory product")
    if target_name != "restored-f330-" + product:
        raise ValueError("fixed one-off lab target name required")
    check_platform()
    # Prove in-memory archive bytes before touching any destination directory.
    raw_archive = read_owner_regular(archive_path)
    files = verify_zip(raw_archive, product, artifact, bundle)
    # Check scope BEFORE any destination directory or staging is created.
    require_pinned_scope(files, tenant_ref, site_ref, release_ref)
    parent_fd = open_private_directory(parent)
    try:
        stat_parent = os.fstat(parent_fd)
        if (not stat.S_ISDIR(stat_parent.st_mode)
                or stat_parent.st_mode & 0o077):
            raise ValueError("destination parent must be owner-private")
        if target_name in os.listdir(parent_fd):
            raise ValueError("restored directory already exists")
        # F1-331: build in an unpredictable private staging directory.
        # The final name does NOT exist until every byte is fsynced and
        # independently read back. Rename requires kernel RENAME_NOREPLACE.
        stage_name = ".sites-f331-restore-" + secrets.token_hex(8) + ".tmp"
        os.mkdir(stage_name, mode=0o700, dir_fd=parent_fd)
        child_fd = os.open(
            stage_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW |
            os.O_CLOEXEC, dir_fd=parent_fd,
        )
        try:
            child_before = os.fstat(child_fd)
            if (child_before.st_mode & 0o077
                    or file_identity(child_before) != file_identity(
                        os.stat(stage_name, dir_fd=parent_fd,
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
                    os.stat(stage_name, dir_fd=parent_fd,
                            follow_symlinks=False))):
                raise ValueError("restored directory substituted")
        finally:
            os.close(child_fd)
        # Read back the STAGED inode before publishing the final name.
        staged = read_private_bundle(parent / stage_name)
        if staged != files:
            raise ValueError("staged recovery disagrees with exact ZIP bytes")
        check_files(staged, product, artifact, bundle)

        # Atomic Linux rename; never fall back to os.rename(), which could
        # silently overwrite a concurrent directory at the final name.
        rename_no_replace(parent_fd, stage_name, target_name)
        os.fsync(parent_fd)
        # Separate final-name readback of the exact files after promotion.
        recovered = read_private_bundle(parent / target_name)
        if recovered != files:
            raise ValueError("recovered bundle bytes differ from original")
        check_files(recovered, product, artifact, bundle)
        return {
            "schemaVersion": "sites.synthetic.private-restore-evidence.v1",
            "product": product,
            "archiveSha256": sha(raw_archive),
            "artifactDigest": artifact,
            "bundleDigest": bundle,
            "scopeMatched": True,
            "scopeAuthenticated": False,
            "syntheticOnly": True,
            "labFilesRecovered": True,
            "operationalRestore": False,
            "publishable": False,
            "officialClientAcceptance": False,
            "crmTouched": False,
        }
    except BaseException:
        # Fail closed: if writing failed before promotion, the incomplete
        # files remain ONLY under a private hidden staging name. No partial
        # final-name restore exists. Scratch cleanup is an operator task.
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
    parser.add_argument("--tenant-ref", required=True)
    parser.add_argument("--site-ref", required=True)
    parser.add_argument("--release-ref", required=True)
    args = parser.parse_args()
    try:
        result = restore(
            args.archive, args.parent, args.target_name,
            args.product, args.artifact, args.bundle,
            args.tenant_ref, args.site_ref, args.release_ref,
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
