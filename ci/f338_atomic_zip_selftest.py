#!/usr/bin/env python3
"""F1-338: stress the ZIP staging-to-final no-overwrite boundary offline."""
from __future__ import annotations

import errno
import os
from pathlib import Path
import tempfile

import f329_private_synthetic_zip as backup
import f329_synthetic_zip_selftest as fixture
import f331_atomic_private_rename as atomic


def denied(fn, label: str) -> None:
    try:
        fn()
    except (ValueError, OSError):
        return
    raise AssertionError("unsafe staged ZIP operation succeeded: " + label)


def staged_names(directory: Path) -> list[str]:
    return sorted(p.name for p in directory.iterdir()
                  if p.name.startswith(".sites-f338-archive-"))


def main() -> None:
    checked = 0
    with tempfile.TemporaryDirectory(prefix="sites-f338-") as tmp:
        root = Path(tmp)
        for product in ("onepage", "landing_page"):
            parent = root / product
            parent.mkdir(mode=0o700)
            files, artifact, bundle = fixture.bundle(product)
            raw = backup.make_zip(files)
            target = parent / ("private-" + product + ".zip")
            original_write = backup.os.write
            saw_staging = False

            def assert_before_promotion(fd: int, payload: bytes) -> int:
                nonlocal saw_staging
                assert not target.exists(), "partial ZIP exposed as final name"
                assert len(staged_names(parent)) == 1
                saw_staging = True
                return original_write(fd, payload)

            try:
                backup.os.write = assert_before_promotion
                backup.write_exclusive(target, raw)
            finally:
                backup.os.write = original_write
            assert saw_staging and target.exists() and not staged_names(parent)
            assert backup.verify_zip(
                backup.read_owner_regular(target), product, artifact, bundle
            ) == files
            checked += 1

            denied(lambda: backup.write_exclusive(target, raw),
                   "cannot overwrite an existing backup")
            assert backup.read_owner_regular(target) == raw
            assert not staged_names(parent)
            checked += 1

            interrupted = parent / ("interrupted-" + product + ".zip")
            try:
                backup.os.write = lambda fd, payload: (_ for _ in ()).throw(
                    OSError(errno.EIO, "synthetic disk failure")
                )
                denied(lambda: backup.write_exclusive(interrupted, raw),
                       "I/O failure must never expose final name")
            finally:
                backup.os.write = original_write
            assert not interrupted.exists() and not staged_names(parent)
            checked += 1

            # A competitor that wins the final filename *during* the stage
            # must cause atomic promotion to fail, never overwrite contents.
            competing = parent / ("raced-" + product + ".zip")
            original_rename = backup.rename_zip_no_replace
            try:
                def create_racing_target(parent_fd: int, stage: str,
                                         final: str) -> None:
                    competing.write_bytes(b"foreign synthetic owner")
                    os.chmod(competing, 0o600)
                    return original_rename(parent_fd, stage, final)
                backup.rename_zip_no_replace = create_racing_target
                denied(lambda: backup.write_exclusive(competing, raw),
                       "concurrent target must win and never be replaced")
            finally:
                backup.rename_zip_no_replace = original_rename
            assert competing.read_bytes() == b"foreign synthetic owner"
            assert not staged_names(parent)
            checked += 1

            # Simulate a mutated readback after promotion. The creator must
            # only delete *its own* final inode, never declare a good ZIP.
            corrupt = parent / ("postrename-" + product + ".zip")
            original_read = backup.read_owner_regular
            try:
                def failed_postrename_readback(path: Path) -> bytes:
                    if path == corrupt:
                        return b"corrupted after kernel rename"
                    return original_read(path)
                backup.read_owner_regular = failed_postrename_readback
                denied(lambda: backup.write_exclusive(corrupt, raw),
                       "final name readback mismatch")
            finally:
                backup.read_owner_regular = original_read
            assert not corrupt.exists() and not staged_names(parent)
            checked += 1

            # Native guard must reject arbitrary target names and traversal.
            with os.scandir(parent):
                pass
            fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                denied(lambda: atomic.rename_zip_no_replace(
                    fd, "../wrong.tmp", "x.zip"
                ), "stage traversal")
                denied(lambda: atomic.rename_zip_no_replace(
                    fd, ".sites-f338-archive-0123456789abcdef.tmp",
                    "../outside.zip"
                ), "final traversal")
                checked += 2
            finally:
                os.close(fd)
    print(f"{checked} F1-338 atomic ZIP promotion/overwrite/crash tests passed")


if __name__ == "__main__":
    main()
