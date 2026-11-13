#!/usr/bin/env python3
"""F1-329: deterministic *synthetic-only* private ZIP export and inspection.

No extraction, restore, network, provider API, PII, approval, or deployment.
External digest pins are caller claims and must NOT be treated as trusted
Portal signatures. Python standard library only; Linux GitHub CI fixtures.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import sys
import zipfile

from f307_private_qa_json_contract import (
    FIXED_FILES, file_identity, inspect_inert_html, open_private_directory,
    read_regular_private,
)

ZIP_NAMES = [name for name, _ in FIXED_FILES]
ZIP_MAX = sum(limit for _, limit in FIXED_FILES) + 4096
STAMP = (1980, 1, 1, 0, 0, 0)
MANIFEST_KEYS = [
    "schemaVersion", "tenantRef", "siteRef", "releaseRef",
    "artifactDigest", "artifactSizeBytes", "syntheticOnly",
    "publishable", "officialClientAcceptance",
]


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def check_pins(artifact: str, bundle: str) -> None:
    if not all(re.fullmatch(r"[a-f0-9]{64}", p) for p in (artifact, bundle)):
        raise ValueError("two explicit 64-hex synthetic pins required")


def check_files(files: dict[str, bytes], product: str,
                artifact: str, bundle: str) -> None:
    check_pins(artifact, bundle)
    if product not in ("onepage", "landing_page"):
        raise ValueError("unrecognized synthetic product")
    if list(files) != ZIP_NAMES:
        raise ValueError("noncanonical file inventory/order")
    html, raw = (files[name] for name in ZIP_NAMES)
    if not (0 < len(html) <= 150000 and 0 < len(raw) <= 8192):
        raise ValueError("unsafe file sizes")
    if sha(html) != artifact or sha(html + b"\x00" + raw) != bundle:
        raise ValueError("private bundle not equal to externally supplied pins")
    inspect_inert_html(html, product)
    try:
        doc = json.loads(raw.decode("utf-8"), object_pairs_hook=reject_duplicates,
                         parse_constant=reject_constant)
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("invalid private manifest") from exc
    if not isinstance(doc, dict) or list(doc) != MANIFEST_KEYS:
        raise ValueError("unrecognized or unordered private manifest")
    if (doc["schemaVersion"] != "sites.synthetic.bundle.v1"
            or doc["syntheticOnly"] is not True
            or doc["publishable"] is not False
            or doc["officialClientAcceptance"] is not False
            or type(doc["artifactSizeBytes"]) is not int
            or doc["artifactSizeBytes"] != len(html)
            or doc["artifactDigest"] != artifact):
        raise ValueError("manifest claims publication or disagrees with HTML")
    for field in ("tenantRef", "siteRef", "releaseRef"):
        value = doc[field]
        if (not isinstance(value, str) or
                not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", value)):
            raise ValueError("unrecognized synthetic manifest identity")
    # F1-336: JSON parsers may accept multiple byte spellings for the
    # same keys, while other services canonicalize/compare differently.
    # Accept only the exact pretty-print representation created by the
    # synthetic PHP ReleaseBundle (all its values are ASCII identifiers,
    # booleans, hashes and integer sizes). ZIP digests alone are untrusted.
    canonical = (json.dumps(doc, ensure_ascii=False, indent=4) + "\n").encode("utf-8")
    if raw != canonical:
        raise ValueError("noncanonical or ambiguously encoded private manifest")


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


def reject_duplicates(pairs: list[tuple[str, object]]) -> dict:
    out: dict[str, object] = {}
    for key, value in pairs:
        if key in out:
            raise ValueError("duplicate manifest key")
        out[key] = value
    return out


def reject_constant(text: str) -> None:
    raise ValueError("nonstandard manifest constant")


def read_private_bundle(directory: Path) -> dict[str, bytes]:
    fd = open_private_directory(directory)
    try:
        directory_info = os.fstat(fd)
        if (directory_info.st_mode & 0o077
                or directory_info.st_uid != os.geteuid()):
            raise ValueError("private source directory owner or mode invalid")
        # Source files must be private regular inodes owned by this process,
        # not merely readable through an owner-only parent.
        for name in ZIP_NAMES:
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if (not stat.S_ISREG(info.st_mode)
                    or info.st_uid != os.geteuid()
                    or info.st_mode & 0o077):
                raise ValueError("private source file owner or mode invalid")
        if os.listdir(fd) != ZIP_NAMES and sorted(os.listdir(fd)) != sorted(ZIP_NAMES):
            raise ValueError("unexpected private source contents")
        files = {name: read_regular_private(fd, name, limit)
                 for name, limit in FIXED_FILES}
        named = os.stat(directory, follow_symlinks=False)
        if file_identity(os.fstat(fd)) != file_identity(named):
            raise ValueError("source directory replaced")
        return files
    finally:
        os.close(fd)


def make_zip(files: dict[str, bytes]) -> bytes:
    if list(files) != ZIP_NAMES:
        raise ValueError("noncanonical archive inventory")
    out = io.BytesIO()
    with zipfile.ZipFile(out, mode="w", allowZip64=False) as writer:
        writer.comment = b""
        for name in ZIP_NAMES:
            info = zipfile.ZipInfo(name, date_time=STAMP)
            info.create_system = 3
            info.external_attr = 0o100600 << 16
            info.compress_type = zipfile.ZIP_STORED
            writer.writestr(info, files[name])
    data = out.getvalue()
    if not (0 < len(data) <= ZIP_MAX):
        raise ValueError("ZIP exceeds bounded synthetic archive size")
    return data


def verify_zip(raw: bytes, product: str, artifact: str, bundle: str) -> dict[str, bytes]:
    if not (0 < len(raw) <= ZIP_MAX):
        raise ValueError("ZIP file exceeds maximum allowed size")
    try:
        with zipfile.ZipFile(io.BytesIO(raw), mode="r", allowZip64=False) as archive:
            infos = archive.infolist()
            if (archive.comment or len(infos) != len(ZIP_NAMES) or
                    [item.filename for item in infos] != ZIP_NAMES):
                raise ValueError("unexpected archive entry inventory")
            for info, (_, maximum) in zip(infos, FIXED_FILES):
                if (info.file_size < 1 or info.file_size > maximum
                        or info.compress_size != info.file_size
                        or info.compress_type != zipfile.ZIP_STORED
                        or info.flag_bits != 0
                        or info.date_time != STAMP
                        or info.extra or info.comment
                        or info.create_system != 3
                        or info.external_attr != 0o100600 << 16):
                    raise ValueError("noncanonical or unsafe archive entry")
            files = {info.filename: archive.read(info)
                     for info in infos}
    except (zipfile.BadZipFile, EOFError, OSError, RuntimeError) as exc:
        raise ValueError("invalid or corrupt ZIP") from exc
    check_files(files, product, artifact, bundle)
    if raw != make_zip(files):
        raise ValueError("noncanonical ZIP bytes (prefix, suffix or altered headers)")
    return files


def read_owner_regular(path: Path) -> bytes:
    """Bounded, nonblocking, no-follow archive read from a pinned directory."""
    directory_fd = open_private_directory(path.parent)
    try:
        # F1-334: 0600 on the ZIP itself is not sufficient to treat a
        # group/world-traversable archive directory as a private backup area.
        directory_info = os.fstat(directory_fd)
        if (directory_info.st_mode & 0o077
                or directory_info.st_uid != os.geteuid()):
            raise ValueError("private ZIP directory owner or mode invalid")
        name = path.name
        if not re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,100}\.zip", name):
            raise ValueError("unexpected private archive filename")
        before = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or before.st_uid != os.geteuid()
                or before.st_mode & 0o077
                or not 0 < before.st_size <= ZIP_MAX):
            raise ValueError("untrusted archive inode or permissions")
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW |
                     os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory_fd)
        try:
            opened = os.fstat(fd)
            if file_identity(before) != file_identity(opened):
                raise ValueError("archive changed during opening")
            chunks = []
            remaining = ZIP_MAX + 1
            while remaining:
                data = os.read(fd, remaining)
                if not data:
                    break
                chunks.append(data)
                remaining -= len(data)
            raw = b"".join(chunks)
            after = os.fstat(fd)
            named = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
            if (len(raw) != before.st_size
                    or file_identity(before) != file_identity(after)
                    or file_identity(before) != file_identity(named)):
                raise ValueError("archive changed during readback")
            return raw
        finally:
            os.close(fd)
    finally:
        os.close(directory_fd)


def write_exclusive(path: Path, data: bytes) -> None:
    if (not re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,100}\.zip", path.name)
            or not 0 < len(data) <= ZIP_MAX):
        raise ValueError("unsafe archive output")
    directory_fd = open_private_directory(path.parent)
    created_identity = None
    try:
        directory_info = os.fstat(directory_fd)
        if (directory_info.st_mode & 0o077
                or directory_info.st_uid != os.geteuid()):
            raise ValueError("private ZIP output directory owner or mode invalid")
        fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                     os.O_NOFOLLOW | os.O_CLOEXEC, mode=0o600,
                     dir_fd=directory_fd)
        created = os.fstat(fd)
        created_identity = (created.st_dev, created.st_ino)
        try:
            offset = 0
            while offset < len(data):
                n = os.write(fd, data[offset:])
                if n <= 0:
                    raise OSError("partial private ZIP write")
                offset += n
            os.fsync(fd)
            opened = os.fstat(fd)
            named = os.stat(path.name, dir_fd=directory_fd, follow_symlinks=False)
            if (not stat.S_ISREG(opened.st_mode)
                    or opened.st_nlink != 1
                    or opened.st_uid != os.geteuid()
                    or opened.st_mode & 0o077
                    or file_identity(opened) != file_identity(named)):
                raise ValueError("private ZIP output inode replaced")
        finally:
            os.close(fd)
        # F1-335: a successful fsync/write is NOT proof that the expected
        # private bytes survived. Reopen via another pinned directory FD,
        # compare complete exact bytes, then fsync parent before returning.
        if read_owner_regular(path) != data:
            raise ValueError("private ZIP post-write exact readback mismatch")
        os.fsync(directory_fd)
    except BaseException:
        # Never delete a replacement inode if another local process swapped
        # the path while the private archive write was failing.
        if created_identity is not None:
            try:
                named = os.stat(path.name, dir_fd=directory_fd,
                                follow_symlinks=False)
                # File *size* changes naturally while writing. Compare
                # only immutable device+inode identity during cleanup,
                # and refuse links/special files; a replacement must never
                # be deleted merely because it has the same byte length.
                if (stat.S_ISREG(named.st_mode) and named.st_nlink == 1
                        and (named.st_dev, named.st_ino) == created_identity):
                    os.unlink(path.name, dir_fd=directory_fd)
            except OSError:
                pass
        raise
    finally:
        os.close(directory_fd)


def main() -> None:
    parser = argparse.ArgumentParser(description="Offline private synthetic ZIP backup")
    parser.add_argument("mode", choices=("create", "inspect"))
    parser.add_argument("--directory", type=Path)
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--product", required=True, choices=("onepage", "landing_page"))
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--bundle", required=True)
    parser.add_argument("--tenant-ref", required=True)
    parser.add_argument("--site-ref", required=True)
    parser.add_argument("--release-ref", required=True)
    args = parser.parse_args()
    try:
        check_pins(args.artifact, args.bundle)
        if args.mode == "create":
            if args.directory is None:
                raise ValueError("create requires fixed synthetic source directory")
            files = read_private_bundle(args.directory)
            check_files(files, args.product, args.artifact, args.bundle)
            require_pinned_scope(files, args.tenant_ref, args.site_ref,
                                 args.release_ref)
            raw = make_zip(files)
            verify_zip(raw, args.product, args.artifact, args.bundle)
            write_exclusive(args.archive, raw)
        else:
            raw = read_owner_regular(args.archive)
            inspected = verify_zip(raw, args.product, args.artifact, args.bundle)
            require_pinned_scope(inspected, args.tenant_ref, args.site_ref,
                                 args.release_ref)
        print(json.dumps({
            "schemaVersion": "sites.synthetic.private-zip-evidence.v1",
            "mode": args.mode,
            "product": args.product,
            "archiveSha256": sha(raw),
            "archiveBytes": len(raw),
            "artifactDigest": args.artifact,
            "bundleDigest": args.bundle,
            "scopeMatched": True,
            "scopeAuthenticated": False,
            "syntheticOnly": True,
            "restored": False,
            "publishable": False,
            "trustedPins": False,
        }, separators=(",", ":")))
    except (ValueError, OSError, KeyError, TypeError) as exc:
        # Fail closed without leaking paths, identities or content bytes.
        print("F1-329: private synthetic ZIP export/inspection denied",
              file=sys.stderr)
        raise SystemExit(2) from None


if __name__ == "__main__":
    main()
