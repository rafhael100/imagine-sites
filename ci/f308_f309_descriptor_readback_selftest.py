#!/usr/bin/env python3
"""F1-308/309: fail-closed descriptor and bounded evidence tests, offline only.

No network, customer files, credentials, release authority, or deployment.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "private_qa_contract", HERE / "f307_private_qa_json_contract.py"
)
assert SPEC is not None and SPEC.loader is not None
contract = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(contract)


def denied(action, label: str) -> None:
    try:
        action()
    except (ValueError, OSError, UnicodeError):
        return
    raise AssertionError("F1-308/309 unsafe case accepted: " + label)


def fixture_report(product: str, html: bytes, manifest: bytes) -> tuple[bytes, str, str]:
    artifact = hashlib.sha256(html).hexdigest()
    bundle = hashlib.sha256(html + bytes([0]) + manifest).hexdigest()
    doc = {
        "schemaVersion": "sites.synthetic.private-bundle-evidence.v1",
        "syntheticOnly": True,
        "readOnly": True,
        "trustedPins": False,
        "officialClientAcceptance": False,
        "releaseAuthorized": False,
        "verifiedLocalBytes": True,
        "product": product,
        "artifactDigest": artifact,
        "bundleDigest": bundle,
        "files": [
            {"name": "index.html", "bytes": len(html),
             "sha256": hashlib.sha256(html).hexdigest()},
            {"name": "release-manifest.json", "bytes": len(manifest),
             "sha256": hashlib.sha256(manifest).hexdigest()},
        ],
        "structuralQa": {
            "passed": True,
            "checks": dict.fromkeys(contract.QA_KEYS, True),
            "humanReviewRequired": True,
            "wcagCertified": False,
        },
    }
    return json.dumps(doc, separators=(",", ":")).encode(), artifact, bundle


def main() -> None:
    checks = 0
    with tempfile.TemporaryDirectory(prefix="sites-f308-") as raw:
        base = Path(raw)
        directory = base / "private"
        directory.mkdir(mode=0o700)
        html = b"totally-fictional-inert-html"
        manifest = b'{"syntheticOnly":true}'
        index = directory / "index.html"
        meta = directory / "release-manifest.json"
        index.write_bytes(html)
        meta.write_bytes(manifest)
        report, artifact, bundle = fixture_report("onepage", html, manifest)
        contract.verify(report, directory, "onepage", artifact, bundle)
        checks += 1

        directory_fd = contract.open_private_directory(directory)
        try:
            assert contract.read_regular_private(directory_fd, "index.html", 150000) == html
            checks += 1
            denied(lambda: contract.read_regular_private(directory_fd, "index.html", 1),
                   "oversized regular file")
            checks += 1
            extra = directory / "extra-link"
            os.link(index, extra)
            denied(lambda: contract.read_regular_private(directory_fd, "index.html", 150000),
                   "hardlinked inode")
            extra.unlink()
            checks += 1
            moved = directory / "original.html"
            index.rename(moved)
            index.symlink_to(moved)
            denied(lambda: contract.read_regular_private(directory_fd, "index.html", 150000),
                   "symlink to a regular fixture")
            index.unlink()
            moved.rename(index)
            checks += 1
            index.unlink()
            os.mkfifo(index, mode=0o600)
            denied(lambda: contract.read_regular_private(directory_fd, "index.html", 150000),
                   "nonblocking named FIFO")
            index.unlink()
            index.write_bytes(html)
            checks += 1
            assert contract.read_regular_private(directory_fd, "index.html", 150000) == html
            checks += 1
        finally:
            os.close(directory_fd)

        report_file = base / "evidence.json"
        report_file.write_bytes(report)
        assert contract.read_bounded_report(report_file) == report
        checks += 1
        report_file.write_bytes(b"x" * 4097)
        denied(lambda: contract.read_bounded_report(report_file),
               "oversized JSON before parsing")
        checks += 1
        report_file.write_bytes(report)
        report_file.unlink()
        report_file.symlink_to(index)
        denied(lambda: contract.read_bounded_report(report_file),
               "symlinked evidence input")
        report_file.unlink()
        report_file.write_bytes(report)
        checks += 1

        unsafe = json.loads(report)
        unsafe["releaseAuthorized"] = True
        denied(lambda: contract.verify(
            json.dumps(unsafe).encode(), directory, "onepage", artifact, bundle
        ), "forged release authorization")
        checks += 1
        denied(lambda: contract.verify(
            report, directory, "landing_page", artifact, bundle
        ), "cross-product evidence")
        checks += 1
        denied(lambda: contract.verify(
            report, directory, "onepage", "0" * 64, bundle
        ), "wrong pinned artifact")
        checks += 1
        unsafe = report.replace(b'"passed":true', b'"passed":false')
        denied(lambda: contract.verify(
            unsafe, directory, "onepage", artifact, bundle
        ), "failed structural QA")
        checks += 1
        unsafe = report.replace(b'"trustedPins":false', b'"trustedPins":true')
        denied(lambda: contract.verify(
            unsafe, directory, "onepage", artifact, bundle
        ), "fabricated trusted pins")
        checks += 1
        rogue = directory / "rogue.txt"
        rogue.write_text("synthetic")
        denied(lambda: contract.verify(
            report, directory, "onepage", artifact, bundle
        ), "unexpected file inventory")
        rogue.unlink()
        checks += 1
        contract.verify(report, directory, "onepage", artifact, bundle)
        checks += 1

    print(f"{checks} F1-308/309 offline descriptor, FIFO, symlink, "
          "hardlink, JSON and NO-GO checks passed")


if __name__ == "__main__":
    main()
