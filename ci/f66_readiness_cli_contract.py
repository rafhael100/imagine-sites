#!/usr/bin/env python3
"""F1-66: black-box contract for the read-only readiness CLI, never a go-live check."""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "ci" / "f64_readiness_inventory.py"
EXPECTED_ISSUES = {33, 34, 35, 36, 37, 38}


def cli(*args: str) -> str:
    result = subprocess.run(
        [sys.executable, str(SCRIPT), *args],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=20,
    )
    assert result.stderr == "", "unexpected readiness CLI stderr"
    return result.stdout


def main() -> None:
    first = cli("--json")
    second = cli("--json")
    assert first == second, "readiness output must be deterministic"
    report = json.loads(first)
    assert set(report) == {
        "schemaVersion", "sourceDate", "source", "status",
        "operationallyVerified", "releaseAuthorized",
        "totalGateCount", "blockedGateCount", "blockers", "manualDependencies",
    }
    assert report["schemaVersion"] == "sites.synthetic.readiness-inventory.v1"
    assert report["source"] == "config/operational-readiness.json"
    assert report["status"] == "BLOCKED"
    assert report["operationallyVerified"] is False
    assert report["releaseAuthorized"] is False
    assert report["blockedGateCount"] == report["totalGateCount"] == 10
    assert len(report["blockers"]) == 10
    keys = [b["key"] for b in report["blockers"]]
    assert len(set(keys)) == 10
    found_issues = set()
    for blocker in report["blockers"]:
        assert set(blocker) == {"key", "label", "issue", "declaredComplete"}
        assert blocker["declaredComplete"] is False
        assert blocker["label"] and isinstance(blocker["label"], str)
        prefix = "https://github.com/rafhael100/imagine-sites/issues/"
        assert blocker["issue"].startswith(prefix)
        found_issues.add(int(blocker["issue"][len(prefix):]))
    assert found_issues == EXPECTED_ISSUES
    assert len(report["manualDependencies"]) >= 4

    human = cli()
    assert "READINESS: BLOCKED" in human
    assert "Pending: 10/10" in human
    assert human.count("[PENDING]") == 10
    assert "Go-live authorized: NO" in human
    for blocker in report["blockers"]:
        assert blocker["issue"] in human
    assert "PASS F1-65" in cli("--self-test")
    print("PASS F1-66: JSON/text/self-test outputs agree; all ten gates remain blocked")


if __name__ == "__main__":
    main()
