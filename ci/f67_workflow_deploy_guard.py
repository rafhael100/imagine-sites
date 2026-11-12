#!/usr/bin/env python3
"""F1-67: additional conservative GitHub Actions anti-deploy checks, not AppSec certification."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORKFLOWS = ROOT / ".github" / "workflows"
# Broad checks are intentionally deny-first: false positives must be reviewed manually.
PROHIBITED = (
    (r"\b(?:npx\s+)?wrangler\s+(?:pages\s+deploy|deploy|publish)\b", "Cloudflare deploy"),
    (r"\b(?:npx\s+)?firebase\s+deploy\b", "Firebase deploy"),
    (r"\b(?:vercel|netlify)\s+(?:deploy|--prod)\b", "hosting deploy"),
    (r"\bgh\s+(?:secret|variable)\s+set\b", "repository secret mutation"),
    (r"\bgh\s+(?:workflow\s+run|release\s+create)\b", "workflow/release mutation"),
    (r"\buses:\s*(?:cloudflare/pages-action|cloudflare/wrangler-action|peaceiris/actions-gh-pages|JamesIves/github-pages-deploy-action)@", "publishing action"),
    (r"\b(?:deployments|pages|id-token):\s*write\b", "write-level deployment permission"),
)
CHECKS = [(re.compile(p, re.IGNORECASE), description) for p, description in PROHIBITED]


def scan(source: str) -> list[str]:
    """Return reasons for denying a workflow's deployment-like capabilities."""
    findings = []
    for expression, label in CHECKS:
        if expression.search(source):
            findings.append(label)
    return findings


def self_test() -> None:
    bad = [
        "run: npx wrangler pages deploy ./dist",
        "run: wrangler deploy",
        "run: firebase deploy",
        "run: netlify deploy --prod",
        "run: gh secret set TOKEN",
        "run: gh workflow run build.yml",
        "uses: cloudflare/pages-action@v1",
        "uses: peaceiris/actions-gh-pages@v4",
        "permissions:\n  pages: write",
        "permissions:\n  id-token: write",
    ]
    for sample in bad:
        assert scan(sample), f"missed dangerous workflow fixture: {sample}"
    safe = [
        "run: python3 ci/f64_readiness_inventory.py --self-test",
        "uses: actions/checkout@v4",
        "permissions:\n  contents: read",
        "run: php -v",
    ]
    for sample in safe:
        assert not scan(sample), f"incorrectly denied a known safe fixture: {sample}"


def main() -> None:
    self_test()
    files = sorted(WORKFLOWS.glob("*.yml")) + sorted(WORKFLOWS.glob("*.yaml"))
    assert files, "missing workflow inventory"
    for file in files:
        reasons = scan(file.read_text(encoding="utf-8"))
        if reasons:
            raise AssertionError(f"{file.relative_to(ROOT)}: forbidden: {', '.join(reasons)}")
    print(f"PASS F1-67: {len(files)} workflow files scanned against deploy/write patterns")


if __name__ == "__main__":
    main()
