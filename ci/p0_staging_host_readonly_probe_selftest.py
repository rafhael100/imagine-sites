#!/usr/bin/env python3
"""P0 dry-run staging probe self-tests. Tempdirs only, no network or secrets."""
from __future__ import annotations

import json
import os
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

import p0_staging_host_readonly_probe as probe


def main() -> None:
    checks = 0
    release = probe.os_release(
        'NAME="Ubuntu"\nID=ubuntu\nVERSION_ID="24.04"\n'
    )
    assert release == {"id": "ubuntu", "version": "24.04"}
    checks += 1
    assert probe.os_release("ID=../evil\nVERSION_ID=../../etc/passwd") == {
        "id": None, "version": None,
    }
    checks += 1

    with tempfile.TemporaryDirectory(prefix="sites-p0-probe-") as tmp:
        root = Path(tmp)
        workspace = root / "workspace"
        workspace.mkdir(mode=0o700)
        secure = probe.workspace_state(workspace)
        assert secure["exists"] and secure["realDirectory"]
        assert secure["ownerUidMatches"]
        assert secure["ownerPermissionsPrivate"]
        assert isinstance(secure["diskFreeMiB"], int)
        checks += 1

        workspace.chmod(0o755)
        assert probe.workspace_state(workspace)["ownerPermissionsPrivate"] is False
        checks += 1

        linked = root / "alias"
        linked.symlink_to(workspace, target_is_directory=True)
        state = probe.workspace_state(linked)
        assert state["symlink"] is True and state["realDirectory"] is False
        assert state["diskFreeMiB"] is None
        checks += 1

        settings = root / "repository" / "config"
        settings.mkdir(parents=True, mode=0o700)
        ready = settings / "operational-readiness.json"
        gates = {"gate" + str(i): False for i in range(10)}
        ready.write_text(json.dumps({"status": "BLOCKED", "gates": gates}))
        report = probe.repo_readiness(root / "repository")
        assert report == {
            "inspected": True,
            "status": "BLOCKED",
            "gateCount": 10,
            "blockedGateCount": 10,
        }
        checks += 1

        ready.write_text(json.dumps({"status": "READY", "gates": {"x": "true"}}))
        assert probe.repo_readiness(root / "repository")["inspected"] is False
        checks += 1

        ready.unlink()
        ready.symlink_to(workspace)
        assert probe.repo_readiness(root / "repository")["inspected"] is False
        checks += 1

    # External version banners may contain arbitrary text. Only the
    # normalized semver digits must reach stdout; never copy errors/env.
    with patch.object(probe.shutil, "which", return_value="/mock/bin"), \
         patch.object(probe.subprocess, "run", return_value=SimpleNamespace(
             stdout=b"tool version 27.4.1 token=sensitive-version-banner",
             stderr=b"secret=do-not-print", returncode=0,
         )) as call:
        # Default must NOT execute even a local "version" command.
        safe = probe.local_cli_versions()
        assert all(row == {"installed": True, "version": None}
                   for row in safe.values())
        assert call.call_count == 0
        checks += 1

        # Optional execution is allowed ONLY when explicitly requested.
        versions = probe.local_cli_versions(probe_versions=True)
        assert all(row["version"] == "27.4.1"
                   for row in versions.values())
        assert call.call_count == len(probe.COMMANDS)
        for entry in call.call_args_list:
            assert entry.kwargs["shell"] is False
            assert entry.kwargs["timeout"] == 5
            assert entry.kwargs["stdin"] is probe.subprocess.DEVNULL
    checks += 1

    # A machine may satisfy prerequisites, but this tool must NEVER report
    # actual homologation or permission to publish.
    with patch.object(probe, "local_cli_versions", return_value={}), \
         patch.object(probe, "read_os_release", return_value={
             "id": "ubuntu", "version": "24.04",
         }):
        result = probe.build_report(Path("."), None)
        assert result["versionCommandsExecuted"] is False
        assert result["externalCommandSideEffectsNotAttested"] is False
        assert result["filesModifiedByProbe"] is False
        assert result["goLiveAuthorized"] is False
        assert result["homologationComplete"] is False
        assert result["liveEndpointTested"] is False
        assert result["status"] == "BLOCKED_PENDING_OPERATOR_AND_SECURITY_REVIEW"
        serialized = json.dumps(result)
        for secret_word in ("secret=do-not-print", "sensitive-version-banner"):
            assert secret_word not in serialized
        checks += 1

        opted = probe.build_report(Path("."), None, probe_versions=True)
        assert opted["versionCommandsExecuted"] is True
        assert opted["externalCommandSideEffectsNotAttested"] is True
        assert opted["goLiveAuthorized"] is False
        assert opted["homologationComplete"] is False
        checks += 1

    print(f"{checks} P0 local staging read-only/sanitization checks passed; NO-GO")


if __name__ == "__main__":
    main()
