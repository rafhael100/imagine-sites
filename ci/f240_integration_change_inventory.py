#!/usr/bin/env python3
"""F1-240: exhaustive, read-only integration diff inventory over local Git.

GitHub compare responses cap their "files" array at 300, which obscures a
large batch. This program audits the real git diff between a PINNED dev SHA
and the PR HEAD, reports all changes, and rejects lost release gates, changed
dev baseline, Git symlinks/submodules and missing evidence contracts.
It never checks out a production environment, writes to the repo or deploys.
"""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

from f64_readiness_inventory import inventory, load_strict_json

ROOT = Path(__file__).resolve().parent.parent
BASELINE = "71b8ffe51139d0e70ed32591b07f295fb7bf5fe3"
REQUIRED = frozenset({
    "AGENTS.md",
    "config/operational-readiness.json",
    "src/Persistence/LabMigrationTargetFenceV1.php",
    "apps/sites/app/Http/Middleware/SyntheticLabRouteFence.php",
    "src/Integration/PortalEditorialHandoffPreviewV1.php",
    "tests/f236-portal-f48-producer-consumer-parity.php",
    "ops/fpm-probe/compose.yml",
    "ci/f238_generate_demo_gallery.php",
    "ci/f239_demo_chromium_screenshots.py",
})
HIGH_RISK_PREFIXES = (
    ".github/workflows/", "config/", "ops/", "apps/sites/routes/",
    "apps/sites/database/migrations/", "src/Security/", "src/Persistence/",
    "src/Integration/", "src/Application/", "contracts/",
)


def run(*args: str, allowed_failure: bool = False) -> subprocess.CompletedProcess[bytes]:
    proc = subprocess.run(
        ["git", *args], cwd=ROOT, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=35, check=False,
    )
    if proc.returncode and not allowed_failure:
        raise RuntimeError("F1-240 local git operation failed: " + args[0])
    return proc


def classify(path: str) -> str:
    if path.startswith(".github/workflows/"):
        return "ci-workflows"
    if path.startswith("apps/sites/database/") or path.startswith("labs/postgres/"):
        return "database"
    if path.startswith("apps/sites/"):
        return "laravel"
    for pre, label in (
        ("src/Production/", "site-engine"),
        ("src/Integration/", "integration"),
        ("src/Security/", "security"),
        ("src/Persistence/", "persistence"),
        ("src/Application/", "application"),
        ("contracts/", "api-contracts"),
        ("ops/", "infra-labs"),
        ("ci/", "ci-scripts"),
        ("tests/", "tests"),
        ("docs/", "docs"),
        ("config/", "readiness"),
        ("fixtures/", "fixtures"),
        ("AGENTS.md", "governance"),
    ):
        if path.startswith(pre):
            return label
    return "other"


def parse_diff(raw: bytes) -> list[tuple[str, str]]:
    if not raw or raw[-1:] != b"\x00":
        raise ValueError("F1-240 git name-status list is missing its NUL trailer")
    parts = raw[:-1].split(b"\x00")
    if len(parts) % 2:
        raise ValueError("F1-240 invalid number of NUL fields")
    result: list[tuple[str, str]] = []
    seen: set[str] = set()
    for i in range(0, len(parts), 2):
        status = parts[i].decode("ascii")
        path = parts[i + 1].decode("utf-8")
        if status not in {"A", "M", "D", "T"}:
            raise ValueError("F1-240 unexpected diff status")
        if (
            path in seen or not path or path.startswith("/")
            or "\\" in path or "\x00" in path
            or any(v in ("", ".", "..") for v in path.split("/"))
        ):
            raise ValueError("F1-240 noncanonical or duplicate diff path")
        seen.add(path)
        result.append((status, path))
    if not result:
        raise ValueError("F1-240 empty integration diff is not useful")
    return sorted(result, key=lambda x: x[1])


def parse_modes(raw: bytes) -> dict[str, str]:
    modes: dict[str, str] = {}
    for item in raw.split(b"\x00"):
        if not item:
            continue
        meta, sep, path_bytes = item.partition(b"\t")
        if not sep:
            raise ValueError("F1-240 invalid git tree")
        mode = meta.split(b" ", 1)[0].decode("ascii")
        path = path_bytes.decode("utf-8")
        modes[path] = mode
    return modes


def self_test() -> None:
    for raw in [b"A\x00a.php\x00M\x00foo/bar\x00", b"D\x00x\x00"]:
        assert parse_diff(raw)
    for raw in [b"", b"A\x00x", b"A\x00x\x00A\x00x\x00", b"R100\x00x\x00"]:
        try:
            parse_diff(raw)
            raise AssertionError("invalid git name-status accepted")
        except ValueError:
            pass
    assert classify(".github/workflows/x.yml") == "ci-workflows"
    assert classify("src/Security/Fence.php") == "security"
    assert classify("ops/staging/Dockerfile") == "infra-labs"
    assert classify("docs/README.md") == "docs"
    print("PASS F1-240 parser refuses incomplete, duplicate, and unexpected git changes")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--output")
    parser.add_argument("--expected-base", default=BASELINE)
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    if (
        os.getenv("GITHUB_ACTIONS") != "true"
        or not args.output
        or not re.fullmatch("[0-9a-f]{40}", args.expected_base)
        or args.expected_base != BASELINE
    ):
        raise RuntimeError("F1-240 only runs against pinned dev baseline in GitHub Actions")

    current = run("rev-parse", "refs/remotes/origin/dev").stdout.decode().strip()
    head = run("rev-parse", "HEAD").stdout.decode().strip()
    ancestor = run("merge-base", current, head).stdout.decode().strip()
    if current != BASELINE or ancestor != current:
        raise RuntimeError("F1-240 dev moved or PR is not strictly ahead; review/rebase first")
    statuses = parse_diff(run("diff", "--name-status", "--no-renames", "-z",
                              current, head).stdout)
    names = {path for _, path in statuses}
    tree = parse_modes(run("ls-tree", "-r", "-z", "HEAD").stdout)
    if not REQUIRED.issubset(set(tree)) or REQUIRED.intersection(
        {path for status, path in statuses if status == "D"}
    ):
        raise RuntimeError("F1-240 expected security/product contract disappeared")
    for status, path in statuses:
        if status != "D" and tree.get(path) not in ("100644", "100755"):
            raise RuntimeError("F1-240 new symlink/submodule/type anomaly denied")

    state = inventory(load_strict_json(
        (ROOT / "config/operational-readiness.json").read_text("utf-8")
    ))
    if state["status"] != "BLOCKED" or state["releaseAuthorized"] is not False:
        raise RuntimeError("F1-240 operational readiness changed unexpectedly")

    paths = [
        {
            "status": status,
            "path": path,
            "group": classify(path),
            "requiresIndependentReview": (
                path.startswith(HIGH_RISK_PREFIXES)
                or path in {"AGENTS.md", "config/operational-readiness.json"}
            ),
        }
        for status, path in statuses
    ]
    counts = Counter(x["group"] for x in paths)
    risky = [x["path"] for x in paths if x["requiresIndependentReview"]]
    statuses_count = Counter(x["status"] for x in paths)
    summary = {
        "schema": "imagine-sites.dev-integration-inventory.v1",
        "baseBranch": "dev",
        "baseSha": current,
        "headSha": head,
        "source": "local git diff --name-status --no-renames -z",
        "completeDiff": True,
        "trackedChanges": len(paths),
        "changedGroups": dict(sorted(counts.items())),
        "changeStatuses": dict(sorted(statuses_count.items())),
        "independentReviewPaths": len(risky),
        "blockedOperationalGates": state["blockedGateCount"],
        "goLiveApproved": False,
        "deploymentPerformed": False,
        "pathsSha256": hashlib.sha256(
            json.dumps(paths, separators=(",", ":"), ensure_ascii=False).encode()
        ).hexdigest(),
        "paths": paths,
    }
    path = Path(args.output)
    if path.parent != Path(os.getenv("RUNNER_TEMP", "")).resolve():
        raise RuntimeError("F1-240 output must stay in the ephemeral runner temp")
    if path.is_symlink() or path.exists():
        raise RuntimeError("F1-240 refuses to overwrite output")
    path.write_text(
        json.dumps(summary, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(
        "PASS F1-240: COMPLETE local git diff:",
        len(paths), "files,", len(risky), "independent review paths;",
        state["blockedGateCount"], "operational gates remain BLOCKED",
    )
    print("PASS F1-240: base/head pinned; no external calls, credentials or merge")


if __name__ == "__main__":
    main()
