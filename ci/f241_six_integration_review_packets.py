#!/usr/bin/env python3
"""F1-241: turn exhaustive Git F1-240 inventory into six complete review packets.

The packets are evidence for *human* review only, not attestation, approval,
customer consent, automatic merge, operational access, or deployment.
"""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
import sys

BATCHES: dict[str, tuple[str, str]] = {
    "A-render-visual": (
        "Motor de renderizacao, design e visibilidade",
        "Revisar HTML/CSP/noindex, copy, previews Onepage/Landing, responsividade, direitos e qualidade",
    ),
    "B-db-security": (
        "Persistencia, isolamento e fronteiras de seguranca",
        "Revisar migrations/RLS, nonce, multiempresa, rotas, revogacao, lock, backup e restauracao",
    ),
    "C-integrations": (
        "Contratos e integracoes Portal, CRM e Agents",
        "Revisar versao do contrato, autoridade, assinatura real futura, consentimento, T0 e idempotencia",
    ),
    "D-infra-ci": (
        "Infraestrutura, configuracao e pipeline",
        "Revisar permissoes, secrets, supply-chain, imagens, portas e gates; sem implantacao",
    ),
    "E-testing-labs": (
        "Testes e laboratorios",
        "Revisar suites negativas, estabilidade e limites entre laboratorio e producao",
    ),
    "F-docs-other": (
        "Documentacao, governanca e outros caminhos",
        "Revisar pendencias e consistencia documental; nenhum documento concede aceite ou go-live",
    ),
}


def bucket(path: str) -> str:
    if path.startswith((
        "src/Production/", "apps/sites/resources/", "fixtures/",
    )):
        return "A-render-visual"
    if path.startswith((
        "src/Security/", "src/Persistence/", "apps/sites/database/",
        "apps/sites/routes/", "apps/sites/app/", "labs/postgres/",
    )):
        return "B-db-security"
    if path.startswith(("src/Integration/", "src/Application/", "contracts/")):
        return "C-integrations"
    if path.startswith((".github/", "ops/", "ci/", "config/")):
        return "D-infra-ci"
    if path.startswith(("tests/", "labs/", "apps/sites/tests/")):
        return "E-testing-labs"
    return "F-docs-other"


def assert_source(data: object) -> list[dict]:
    if not isinstance(data, dict) or (
        data.get("schema") != "imagine-sites.dev-integration-inventory.v1"
        or data.get("baseBranch") != "dev"
        or data.get("completeDiff") is not True
        or data.get("blockedOperationalGates") != 10
        or data.get("goLiveApproved") is not False
        or data.get("deploymentPerformed") is not False
    ):
        raise ValueError("F1-241 missing full blocked F1-240 source")
    for key in ("baseSha", "headSha", "pathsSha256"):
        if not isinstance(data.get(key), str) or not re.fullmatch(
            "[a-f0-9]{40}" if key != "pathsSha256" else "[a-f0-9]{64}",
            data[key],
        ):
            raise ValueError("F1-241 invalid pinned source hash")
    items = data.get("paths")
    if not isinstance(items, list) or not items or len(items) != data.get("trackedChanges"):
        raise ValueError("F1-241 incomplete source paths")
    raw = json.dumps(items, separators=(",", ":"), ensure_ascii=False).encode()
    if hashlib.sha256(raw).hexdigest() != data["pathsSha256"]:
        raise ValueError("F1-241 source path digest changed")
    seen = set()
    sensitive = 0
    for item in items:
        if not isinstance(item, dict) or set(item) != {
            "status", "path", "group", "requiresIndependentReview"
        }:
            raise ValueError("F1-241 invalid per-path schema")
        name = item["path"]
        if (
            not isinstance(name, str) or len(name) > 500
            or not name or name in seen
            or any(ord(c) < 32 or ord(c) == 127 for c in name)
            or "\`" in name or "|" in name
            or not isinstance(item["requiresIndependentReview"], bool)
            or item["status"] not in ("A", "M", "D", "T")
        ):
            raise ValueError("F1-241 ambiguous or duplicate path")
        seen.add(name)
        sensitive += int(item["requiresIndependentReview"])
    if sensitive != data.get("independentReviewPaths"):
        raise ValueError("F1-241 sensitive count drift")
    return items


def self_test() -> None:
    for path, expected in [
        ("src/Production/StaticSiteRenderer.php", "A-render-visual"),
        ("apps/sites/resources/views/labs/review.blade.php", "A-render-visual"),
        ("src/Security/Nonce.php", "B-db-security"),
        ("apps/sites/database/migrations/001.php", "B-db-security"),
        ("src/Integration/SiteProjectCommand.php", "C-integrations"),
        ("contracts/portal-sites/v1/create-project.schema.json", "C-integrations"),
        (".github/workflows/synthetic-php.yml", "D-infra-ci"),
        ("ops/staging/compose.yml", "D-infra-ci"),
        ("tests/f23-cross-repo.php", "E-testing-labs"),
        ("docs/STATUS.md", "F-docs-other"),
    ]:
        assert bucket(path) == expected, (path, bucket(path))
    assert len(BATCHES) == 6
    print("PASS F1-241 six stable routing categories for code review")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--input")
    parser.add_argument("--outdir")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    runner = Path(os.environ.get("RUNNER_TEMP", "/nonexistent")).resolve()
    if os.environ.get("GITHUB_ACTIONS") != "true" or not args.input or not args.outdir:
        raise RuntimeError("F1-241 requires CI-only source and output")
    source = Path(args.input)
    target = Path(args.outdir)
    if (
        source.is_symlink() or source.parent.resolve() != runner
        or target.is_symlink() or target.parent.resolve() != runner
        or target.exists()
    ):
        raise RuntimeError("F1-241 paths must be fresh and CI-local")
    data = json.loads(source.read_text("utf-8"))
    items = assert_source(data)
    groups: dict[str, list[dict]] = {k: [] for k in BATCHES}
    for item in items:
        groups[bucket(item["path"])].append(item)
    if sum(len(x) for x in groups.values()) != len(items):
        raise RuntimeError("F1-241 lost integration file during grouping")
    if sum(1 for x in groups.values() for y in x if y["requiresIndependentReview"]) != data["independentReviewPaths"]:
        raise RuntimeError("F1-241 dropped a sensitive integration path")
    target.mkdir(mode=0o700)
    packets = []
    for key, (title, instructions) in BATCHES.items():
        files = sorted(groups[key], key=lambda item: item["path"])
        critical = [x for x in files if x["requiresIndependentReview"]]
        lines = [
            f"# F1-241 — {title}",
            "",
            f"**Origem:** base \`{data['baseSha']}\` / HEAD \`{data['headSha']}\`",
            f"**SHA-256 do inventario:** \`{data['pathsSha256']}\`",
            f"**Escopo:** {len(files)} arquivos, {len(critical)} para revisao independente.",
            "",
            f"**Criterios:** {instructions}.",
            "",
            "**Estado de risco:** 10/10 gates operacionais BLOQUEADOS, sem merge, publicacao ou aceite.",
            "",
            "| Alteracao | Arquivo | Revisao independente |",
            "| --- | --- | --- |",
        ]
        for item in files:
            lines.append(
                f"| {item['status']} | \`{item['path']}\` | "
                f"{'SIM' if item['requiresIndependentReview'] else 'triagem normal'} |"
            )
        lines += [
            "",
            "Registro de revisao humana, findings e aceite devem ser documentados",
            "separadamente. Este arquivo e APENAS um inventario, nao uma aprovacao.",
            "",
        ]
        filename = key + ".md"
        content = "\n".join(lines)
        f = target / filename
        f.write_text(content, encoding="utf-8")
        f.chmod(0o600)
        packets.append({
            "batch": key,
            "file": filename,
            "changedPaths": len(files),
            "independentReviewPaths": len(critical),
            "sha256": hashlib.sha256(f.read_bytes()).hexdigest(),
        })
    if len(packets) != 6:
        raise RuntimeError("F1-241 expected exactly six review packets")
    manifest = {
        "schema": "imagine-sites.synthetic-review-packets.v1",
        "baseSha": data["baseSha"],
        "headSha": data["headSha"],
        "sourcePathsSha256": data["pathsSha256"],
        "fileCount": len(items),
        "independentReviewPaths": data["independentReviewPaths"],
        "operationalGatesBlocked": 10,
        "mergeAuthorized": False,
        "productionAuthorized": False,
        "packets": packets,
    }
    (target / "review-packets.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    if len(list(target.iterdir())) != 7:
        raise RuntimeError("F1-241 review packet inventory mismatch")
    print(
        f"PASS F1-241: {len(items)} files, "
        f"{data['independentReviewPaths']} sensitive paths across 6 packets"
    )
    for entry in packets:
        print("PASS F1-241", entry["batch"], entry["changedPaths"],
              "paths", entry["independentReviewPaths"], "sensitive")


if __name__ == "__main__":
    main()
