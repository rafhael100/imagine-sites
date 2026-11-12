#!/usr/bin/env python3
"""F1-186: static guard for expensive CI workflows.

No YAML/PyPI/GitHub network access, no runtime or deployment approval.
This test checks only the exact intended synthetic workflow guard text.
"""
from __future__ import annotations

from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
FILES=(
    "synthetic-php.yml",
    "sites-laravel-f1-03.yml",
    "sites-f1-41-offline-review-gallery.yml",
    "sites-f1-83-real-lab-compose.yml",
    "sites-f1-174-edited-private-bundle.yml",
    "sites-f1-184-private-layout-package.yml",
    "sites-f1-185-canonical-bundle.yml",
)
PUSH_MAIN={"synthetic-php.yml","sites-laravel-f1-03.yml"}
CURRENCY_GROUP="group: "+chr(36)+"{{ github.workflow }}-"+chr(36)+"{{ github.event.pull_request.number || github.ref }}"

def check(name: str, source: str) -> None:
    if not source.startswith("name: ") or "\non:\n" not in source:
        raise ValueError(f"{name}: missing workflow identity or events")
    if source.count("\nconcurrency:\n")!=1 or (
        "\nconcurrency:\n  "+CURRENCY_GROUP+"\n  cancel-in-progress: true\n"
        not in source
    ):
        raise ValueError(f"{name}: superseded runs must be canceled only within workflow/PR")
    if "\npermissions:\n  contents: read\n" not in source:
        raise ValueError(f"{name}: read-only token required")
    if "\n  pull_request:\n" not in source:
        raise ValueError(f"{name}: pull request verification missing")
    if name in PUSH_MAIN:
        if "\non:\n  push:\n    branches: [main]\n  pull_request:\n" not in source:
            raise ValueError(f"{name}: redundant all-branch push runs forbidden")
    elif "\non:\n  pull_request:\n" not in source or "\n  push:" in source:
        raise ValueError(f"{name}: PR-only workflow changed triggers")
    if "runs-on:" not in source or "actions/checkout@v4" not in source:
        raise ValueError(f"{name}: actual CI job removed")

def negative_mutations(name: str, source: str) -> int:
    changes=[
        source.replace("  cancel-in-progress: true\n","  cancel-in-progress: false\n"),
        source.replace(CURRENCY_GROUP,"group: global-untrusted-merge"),
        source.replace("\npermissions:\n  contents: read\n",
                       "\npermissions:\n  contents: write\n"),
        source.replace("\n  pull_request:\n","\n  workflow_dispatch:\n"),
        source.replace("actions/checkout@v4","actions/checkout@v1"),
    ]
    if name in PUSH_MAIN:
        changes.append(source.replace(
            "\n  push:\n    branches: [main]\n",
            "\n  push:\n"
        ))
    for i,wrong in enumerate(changes):
        try: check(name,wrong)
        except ValueError:continue
        raise AssertionError(f"{name}: unsafe CI mutation {i} accepted")
    return len(changes)

def main()->None:
    mutations=0
    for name in FILES:
        source=(ROOT/".github/workflows"/name).read_text("utf-8")
        check(name,source)
        mutations+=negative_mutations(name,source)
        print("PASS F1-186",name,"PR guard + superseded-run cancellation")
    assert len(FILES)==7 and mutations>=37
    print(f"PASS F1-186: {len(FILES)} workflows, {mutations} unsafe mutations denied.")
    print("No production workflows, secret access, live CRM or deploy modified.")

if __name__=="__main__":
    main()
