#!/usr/bin/env python3
"""F1-330: positive/negative lab-only ZIP recovery regression."""
from __future__ import annotations

import os
import json
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


SCOPE = ("tenant.synthetic", "site.synthetic", "release.synthetic")


def restore_with_scope(*args):
    return recovery.restore(*args, *SCOPE)


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
                denied(lambda: restore_with_scope(*args), "restore without flag")
                assert not (destination / target).exists()
                checked += 1

                os.environ["SITES_SYNTHETIC_RESTORE"] = "1"
                os.environ["APP_ENV"] = "production"
                denied(lambda: restore_with_scope(*args), "non-testing environment")
                assert not (destination / target).exists()
                checked += 1
                os.environ["APP_ENV"] = "testing"

                denied(lambda: restore_with_scope(
                    archive_path, destination, target, product, "0" * 64, digest
                ), "untrusted HTML digest")
                assert not (destination / target).exists()
                checked += 1

                denied(lambda: restore_with_scope(
                    archive_path, destination, "../../escaped", product,
                    artifact, digest,
                ), "path traversal as target")
                assert not (base / "escaped").exists()
                checked += 1

                denied(lambda: restore_with_scope(
                    archive_path, destination, target,
                    "landing_page" if product == "onepage" else "onepage",
                    artifact, digest,
                ), "different product and target")
                assert not (destination / target).exists()
                checked += 1

                fake = destination / target
                fake.symlink_to(archive_path)
                denied(lambda: restore_with_scope(*args),
                       "already-existing target symlink")
                assert fake.is_symlink()
                fake.unlink()
                checked += 1

                bad_path = base / f"damaged-f330-{product}.zip"
                bundle_zip.write_exclusive(bad_path, raw + b"garbage")
                denied(lambda: restore_with_scope(
                    bad_path, destination, target, product, artifact, digest
                ), "invalid archive does not create destination")
                assert not (destination / target).exists()
                checked += 1

                # F1-332: the fixture's digest can be self-consistent for
                # ANOTHER company's release, but project pins must still
                # refuse any write to this private destination.
                for invalid in (
                    ("different.tenant", SCOPE[1], SCOPE[2]),
                    (SCOPE[0], "different.site", SCOPE[2]),
                    (SCOPE[0], SCOPE[1], "different.release"),
                    ("", SCOPE[1], SCOPE[2]),
                ):
                    denied(lambda bad=invalid: recovery.restore(
                        *args, *bad,
                    ), "wrong or absent independently pinned project identity")
                    assert not (destination / target).exists()
                    checked += 1

                foreign_files = dict(files)
                foreign_meta = json.loads(files["release-manifest.json"])
                foreign_meta["tenantRef"] = "foreign.tenant"
                foreign_manifest = (json.dumps(foreign_meta, ensure_ascii=False,
                                               indent=4) + "\n").encode("utf-8")
                foreign_files["release-manifest.json"] = foreign_manifest
                foreign_pin = bundle_zip.sha(
                    files["index.html"] + b"\x00" + foreign_manifest
                )
                foreign_archive = base / f"foreign-f332-{product}.zip"
                bundle_zip.write_exclusive(
                    foreign_archive, bundle_zip.make_zip(foreign_files),
                )
                denied(lambda: recovery.restore(
                    foreign_archive, destination, target, product, artifact,
                    foreign_pin, *SCOPE,
                ), "cross-tenant archive with new self-consistent bundle hash")
                assert not (destination / target).exists()
                checked += 1

                report = restore_with_scope(*args)
                assert report["scopeMatched"] is True
                assert report["scopeAuthenticated"] is False
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

                denied(lambda: restore_with_scope(*args),
                       "second restore must never overwrite verified bytes")
                assert bundle_zip.read_private_bundle(destination / target) == files
                checked += 1

                # Group-readable parent is disallowed even for fictional data.
                lax = base / f"public-parent-{product}"
                lax.mkdir(mode=0o755)
                denied(lambda: restore_with_scope(
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
