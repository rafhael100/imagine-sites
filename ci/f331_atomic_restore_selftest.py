#!/usr/bin/env python3
"""F1-331: atomic no-overwrite restore, crash-stage and race regression."""
from __future__ import annotations

import errno
import os
from pathlib import Path
import secrets
import tempfile

import f329_private_synthetic_zip as backup
import f329_synthetic_zip_selftest as fixture
import f330_private_synthetic_restore as recovery
import f331_atomic_private_rename as atomic


def denied(fn, label: str, errnos: tuple[int, ...] = ()) -> None:
    try:
        fn()
    except OSError as exc:
        if errnos and exc.errno not in errnos:
            raise AssertionError("unexpected errno for " + label) from exc
        return
    except ValueError:
        if errnos:
            raise AssertionError("expected kernel no-replace error") from None
        return
    raise AssertionError("unsafe atomic restore operation succeeded: " + label)


def main() -> None:
    checks = 0
    with tempfile.TemporaryDirectory(prefix="sites-f331-") as root:
        base = Path(root)
        isolated = base / "private"
        isolated.mkdir(mode=0o700)
        parent_fd = os.open(isolated, os.O_RDONLY | os.O_DIRECTORY |
                            os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            first_stage = ".sites-f331-restore-" + secrets.token_hex(8) + ".tmp"
            destination = "restored-f330-onepage"
            os.mkdir(first_stage, 0o700, dir_fd=parent_fd)
            os.mkdir(destination, 0o700, dir_fd=parent_fd)
            sentinel = isolated / destination / "existing"
            sentinel.write_text("private fictional sentinel", encoding="utf-8")
            denied(lambda: atomic.rename_no_replace(
                parent_fd, first_stage, destination
            ), "cannot replace concurrently created destination", (errno.EEXIST,))
            assert sentinel.read_text() == "private fictional sentinel"
            assert (isolated / first_stage).is_dir()
            checks += 1

            denied(lambda: atomic.rename_no_replace(
                parent_fd, "../other", destination
            ), "traversal stage")
            denied(lambda: atomic.rename_no_replace(
                parent_fd, first_stage, "restored-f330-other"
            ), "unknown final target")
            checks += 2
            sentinel.unlink()
            os.rmdir(destination, dir_fd=parent_fd)
            atomic.rename_no_replace(parent_fd, first_stage, destination)
            assert (isolated / destination).is_dir()
            assert not (isolated / first_stage).exists()
            checks += 1
        finally:
            os.close(parent_fd)

        for product in ("onepage", "landing_page"):
            files, artifact, digest = fixture.bundle(product)
            target = "restored-f330-" + product
            parent = base / ("restore-" + product)
            parent.mkdir(mode=0o700)
            archive_path = base / ("sites-f331-" + product + ".zip")
            backup.write_exclusive(archive_path, backup.make_zip(files))
            args = (archive_path, parent, target, product, artifact, digest)
            old_env = {key: os.environ.get(key) for key in (
                "APP_ENV", "SITES_SYNTHETIC_RESTORE",
                "SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE",
            )}
            os.environ["APP_ENV"] = "testing"
            os.environ["SITES_SYNTHETIC_RESTORE"] = "1"
            os.environ["SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE"] = "1"
            try:
                # An interrupted write cannot expose the FINAL directory;
                # the incomplete scratch remains quarantined under .tmp.
                normal_write = recovery.os.write
                try:
                    def fail_write(fd: int, data: bytes) -> int:
                        raise OSError(errno.EIO, "fictional IO failure")

                    recovery.os.write = fail_write
                    denied(lambda: recovery.restore(*args),
                           "write failure must block promotion", (errno.EIO,))
                finally:
                    recovery.os.write = normal_write
                assert not (parent / target).exists()
                assert any(p.name.startswith(".sites-f331-restore-")
                           for p in parent.iterdir())
                checks += 1

                # Simulate another process creating final target immediately
                # before the atomic rename. Must never overwrite its sentinel.
                real_rename = recovery.rename_no_replace
                try:
                    def competing_target(fd: int, stage: str,
                                         final: str) -> None:
                        os.mkdir(final, mode=0o700, dir_fd=fd)
                        (parent / final / "sentinel").write_text("owner has data")
                        return real_rename(fd, stage, final)

                    recovery.rename_no_replace = competing_target
                    denied(lambda: recovery.restore(*args),
                           "competing target cannot be replaced", (errno.EEXIST,))
                finally:
                    recovery.rename_no_replace = real_rename
                assert (parent / target / "sentinel").read_text() == "owner has data"
                checks += 1
                (parent / target / "sentinel").unlink()
                (parent / target).rmdir()

                result = recovery.restore(*args)
                assert result["labFilesRecovered"] is True
                assert result["operationalRestore"] is False
                assert backup.read_private_bundle(parent / target) == files
                checks += 1
                denied(lambda: recovery.restore(*args),
                       "repeat restore must fail before writing")
                assert backup.read_private_bundle(parent / target) == files
                checks += 1
            finally:
                for key, value in old_env.items():
                    if value is None:
                        os.environ.pop(key, None)
                    else:
                        os.environ[key] = value

    print(f"{checks} F1-331 no-replace, partial-write quarantine and racing-target checks passed")


if __name__ == "__main__":
    main()
