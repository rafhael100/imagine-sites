#!/usr/bin/env python3
"""F1-329 adversarial synthetic private ZIP tests; all tempfile/offline."""
from __future__ import annotations

import hashlib
import io
import json
import os
from pathlib import Path
import tempfile
import zipfile

import f329_private_synthetic_zip as archive


def deny(action, label: str) -> None:
    try:
        action()
    except (ValueError, OSError, zipfile.BadZipFile):
        return
    raise AssertionError(f"unsafe archive accepted: {label}")


def synthetic_html(product: str) -> bytes:
    body = "onepage" if product == "onepage" else "landing-page"
    return f"""<!doctype html>
<html lang="pt-BR">
<head>
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; form-action 'none'; object-src 'none'">
<title>Empresa fictícia — laboratório privado</title>
</head>
<body data-site-product="{body}">
<main id="principal"><h1>Prévia fictícia</h1>
<a href="#contato">Contato de teste</a>
<section id="contato"><h2>Contato fictício</h2></section>
</main>
</body></html>""".encode("utf-8")


def bundle(product: str) -> tuple[dict[str, bytes], str, str]:
    html = synthetic_html(product)
    artifact = hashlib.sha256(html).hexdigest()
    manifest = {
        "schemaVersion": "sites.synthetic.bundle.v1",
        "tenantRef": "tenant.synthetic",
        "siteRef": "site.synthetic",
        "releaseRef": "release.synthetic",
        "artifactDigest": artifact,
        "artifactSizeBytes": len(html),
        "syntheticOnly": True,
        "publishable": False,
        "officialClientAcceptance": False,
    }
    raw = (json.dumps(manifest, ensure_ascii=False, indent=4) + "\n").encode()
    files = {"index.html": html, "release-manifest.json": raw}
    bundled = hashlib.sha256(html + b"\x00" + raw).hexdigest()
    return files, artifact, bundled


def owner_file(path: Path, data: bytes) -> None:
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    try:
        os.write(fd, data)
    finally:
        os.close(fd)


def mutate_zip(files: dict[str, bytes], names: list[str],
               comment: bytes = b"") -> bytes:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, mode="w", allowZip64=False) as writer:
        writer.comment = comment
        for name in names:
            info = zipfile.ZipInfo(name, date_time=archive.STAMP)
            info.create_system = 3
            info.external_attr = 0o100600 << 16
            info.compress_type = zipfile.ZIP_STORED
            writer.writestr(info, files.get(name, b"bad"))
    return stream.getvalue()


def main() -> None:
    count = 0
    with tempfile.TemporaryDirectory(prefix="sites-f329-") as root:
        base = Path(root)
        for product in ("onepage", "landing_page"):
            private = base / product
            private.mkdir(mode=0o700)
            files, artifact, bundle_digest = bundle(product)
            for name, data in files.items():
                owner_file(private / name, data)
            archive.check_files(files, product, artifact, bundle_digest)
            count += 1

            raw = archive.make_zip(files)
            assert archive.make_zip(files) == raw
            assert archive.verify_zip(raw, product, artifact, bundle_digest) == files
            count += 1

            destination = base / f"sites-f329-{product}.zip"
            archive.write_exclusive(destination, raw)
            assert archive.read_owner_regular(destination) == raw
            assert archive.verify_zip(
                archive.read_owner_regular(destination), product,
                artifact, bundle_digest
            ) == files
            count += 1
            assert archive.read_private_bundle(private) == files
            count += 1
            deny(lambda: archive.write_exclusive(destination, raw),
                 "existing private backup overwritten")
            assert archive.read_owner_regular(destination) == raw
            count += 1

            deny(lambda: archive.verify_zip(
                raw, product, "0" * 64, bundle_digest
            ), "wrong digest pin")
            count += 1
            deny(lambda: archive.verify_zip(
                raw, "landing_page" if product == "onepage" else "onepage",
                artifact, bundle_digest,
            ), "cross-product archive")
            count += 1
            deny(lambda: archive.verify_zip(
                raw + b"junk", product, artifact, bundle_digest
            ), "ZIP trailing garbage")
            count += 1
            deny(lambda: archive.verify_zip(
                b"junk" + raw, product, artifact, bundle_digest
            ), "ZIP prepended garbage")
            count += 1
            deny(lambda: archive.verify_zip(
                mutate_zip(files, ["release-manifest.json", "index.html"]),
                product, artifact, bundle_digest
            ), "archive entry order swapped")
            count += 1
            deny(lambda: archive.verify_zip(
                mutate_zip(files, ["index.html", "index.html"]),
                product, artifact, bundle_digest
            ), "duplicate ZIP filename")
            count += 1
            deny(lambda: archive.verify_zip(
                mutate_zip(files, ["../evil", "release-manifest.json"]),
                product, artifact, bundle_digest
            ), "zip-slip traversal")
            count += 1
            deny(lambda: archive.verify_zip(
                mutate_zip(files, archive.ZIP_NAMES, comment=b"hidden"),
                product, artifact, bundle_digest
            ), "ZIP comment metadata")
            count += 1
            tampered = dict(files)
            tampered["index.html"] += b"<script></script>"
            deny(lambda: archive.check_files(
                tampered, product, artifact, bundle_digest
            ), "tampered private HTML")
            count += 1
            altered_manifest = json.loads(files["release-manifest.json"])
            altered_manifest["publishable"] = True
            raw_manifest = json.dumps(altered_manifest).encode()
            bad_files = dict(files, **{"release-manifest.json": raw_manifest})
            new_bundle = hashlib.sha256(files["index.html"] +
                                        b"\x00" + raw_manifest).hexdigest()
            deny(lambda: archive.check_files(
                bad_files, product, artifact, new_bundle
            ), "recomputed manifest hash with publication flag")
            count += 1

            link = private / "unexpected"
            link.symlink_to(private / "index.html")
            deny(lambda: archive.read_private_bundle(private),
                 "unexpected symlink inside private bundle")
            link.unlink()
            count += 1
            second = private / "hardlink"
            os.link(private / "index.html", second)
            deny(lambda: archive.read_private_bundle(private),
                 "hardlink source file")
            second.unlink()
            count += 1

            alias = base / f"symlink-{product}.zip"
            alias.symlink_to(destination)
            deny(lambda: archive.read_owner_regular(alias),
                 "symlinked ZIP alias")
            alias.unlink()
            count += 1

            assert archive.read_owner_regular(destination) == raw
            count += 1
    print(f"{count} F1-329 offline deterministic private ZIP/negative checks passed")


if __name__ == "__main__":
    main()
