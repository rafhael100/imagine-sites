#!/usr/bin/env python3
"""F1-330: positive/negative lab-only ZIP recovery regression."""
from __future__ import annotations

import os
from pathlib import Path
import tempfile

import f329_private_synthetic_zip as bundle_zip
import f329_synthetic_zip_selftest as fixture
import f330_private_synthetic_restore as recovery


def denied(fn, label: str) -> None:
    try:
        fn()
    except (ValueError, OSError):
        return
    raise AssertionError("F1-330 unsafe restore accepted: " + label)


def main() -> None:
    checked = 0
    old = {k: os.environ.get(k) for k in (
        "APP_ENV", "SITES_SYNTHETIC_RESTORE",
        "SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE",
    )}
    try:
        with tempfile.TemporaryDirectory(prefix="sites-f330-") as root:
            base = Path(root)
            for product in ("onepage", "landing_page"):
                files, artifact, digest = fixture.bundle(product)
                archive_path = base / f"sites-f330-{product}.zip"
                raw = bundle_zip.make_zip(files)
                bundle_zip.write_exclusive(archive_path, raw)
                destination = base / f"parent-{product}"
                destination.mkdir(mode=0o700)
                target = "restored-f330-" + product
                args = (archive_path, destination, target, product, artifact, digest)

                # All flags required, including local-only opt-in outside CI.
                os.environ.pop("SITES_SYNTHETIC_RESTORE", None)
                os.environ["APP_ENV"] = "testing"
                os.environ["SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE"] = "1"
                denied(lambda: recovery.restore(*args), "restore without flag")
                assert not (destination / target).exists()
                checked += 1

                os.environ["SITES_SYNTHETIC_RESTORE"] = "1"
                os.environ["APP_ENV"] = "production"
                denied(lambda: recovery.restore(*args), "non-testing environment")
                assert not (destination / target).exists()
                checked += 1
                os.environ["APP_ENV"] = "testing"

                denied(lambda: recovery.restore(
                    archive_path, destination, target, product, "0" * 64, digest
                ), "untrusted HTML digest")
                assert not (destination / target).exists()
                checked += 1

                denied(lambda: recovery.restore(
                    archive_path, destination, "../../escaped", product,
                    artifact, digest,
                ), "path traversal as target")
                assert not (base / "escaped").exists()
                checked += 1

                denied(lambda: recovery.restore(
                    archive_path, destination, target,
                    "landing_page" if product == "onepage" else "onepage",
                    artifact, digest,
                ), "different product and target")
                assert not (destination / target).exists()
                checked += 1

                fake = destination / target
                fake.symlink_to(archive_path)
                denied(lambda: recovery.restore(*args),
                       "already-existing target symlink")
                assert fake.is_symlink()
                fake.unlink()
                checked += 1

                bad_path = base / f"damaged-f330-{product}.zip"
                bundle_zip.write_exclusive(bad_path, raw + b"garbage")
                denied(lambda: recovery.restore(
                    bad_path, destination, target, product, artifact, digest
                ), "invalid archive does not create destination")
                assert not (destination / target).exists()
                checked += 1

                report = recovery.restore(*args)
                assert report["labFilesRecovered"] is True
                assert report["operationalRestore"] is False
                assert report["publishable"] is False
                assert report["officialClientAcceptance"] is False
                assert report["crmTouched"] is False
                assert report["syntheticOnly"] is True
                assert bundle_zip.read_private_bundle(destination / target) == files
                checked += 1

                for name in bundle_zip.ZIP_NAMES:
                    p = destination / target / name
                    assert p.stat().st_mode & 0o077 == 0
                    assert p.stat().st_nlink == 1
                    assert p.read_bytes() == files[name]
                    checked += 1

                denied(lambda: recovery.restore(*args),
                       "second restore must never overwrite verified bytes")
                assert bundle_zip.read_private_bundle(destination / target) == files
                checked += 1

                # Group-readable parent is disallowed even for fictional data.
                lax = base / f"public-parent-{product}"
                lax.mkdir(mode=0o755)
                denied(lambda: recovery.restore(
                    archive_path, lax, target, product, artifact, digest
                ), "permissive destination parent")
                assert not (lax / target).exists()
                checked += 1
    finally:
        for key, value in old.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value

    print(f"{checked} F1-330 offline restore/overwrite/flag/permissions denials passed")


if __name__ == "__main__":
    main()
