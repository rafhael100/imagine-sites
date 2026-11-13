#!/usr/bin/env python3
"""P0 Sites staging VPS diagnostics: read-only, local, sanitized, NO-GO.

No external network, installs, Docker daemon access, secret reads, deploy,
cloud credentials, customer content or filesystem mutations. This is a
reproducible PREPARATION report, not a homologation or go-live approval.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import platform
import re
import shutil
import stat
import subprocess
import sys
from typing import Any

COMMANDS: dict[str, tuple[str, ...]] = {
    "dockerCli": ("docker", "--version"),
    "dockerComposeCli": ("docker", "compose", "version"),
    "gitCli": ("git", "--version"),
    "phpCli": ("php", "--version"),
    "composerCli": ("composer", "--version"),
    "pythonCli": ("python3", "--version"),
}
VERSION = re.compile(r"(?<!\d)(\d{1,3}\.\d{1,3}(?:\.\d{1,3})?)(?!\d)")


def os_release(contents: str) -> dict[str, str | None]:
    found: dict[str, str | None] = {"id": None, "version": None}
    for line in contents.splitlines()[:100]:
        for key, label in (("ID", "id"), ("VERSION_ID", "version")):
            if line.startswith(key + "="):
                val = line[len(key) + 1:].strip().strip('"').strip("'")
                if re.fullmatch(r"[a-z0-9._-]{1,32}", val, re.I):
                    found[label] = val.lower()
    return found


def read_os_release() -> dict[str, str | None]:
    path = Path("/etc/os-release")
    try:
        if path.is_file():
            return os_release(path.read_text(encoding="utf-8")[:16384])
    except (OSError, UnicodeError):
        pass
    return {"id": None, "version": None}


def local_cli_versions(probe_versions: bool = False) -> dict[str, dict[str, Any]]:
    result: dict[str, dict[str, Any]] = {}
    for label, argv in COMMANDS.items():
        if shutil.which(argv[0]) is None:
            result[label] = {"installed": False, "version": None}
            continue
        if not probe_versions:
            # Safe DEFAULT: PATH presence only, no untrusted binary executed.
            result[label] = {"installed": True, "version": None}
            continue
        try:
            process = subprocess.run(
                argv, capture_output=True, check=False, timeout=5,
                stdin=subprocess.DEVNULL, shell=False,
                env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
                     "LANG": "C", "LC_ALL": "C"},
            )
            # Expose only a strictly validated version number. Never return
            # stdout/stderr, paths, env vars or errors from local binaries.
            raw = (process.stdout + b"\n" + process.stderr)[:1024]
            first = raw.decode("utf-8", errors="replace")
            match = VERSION.search(first)
            result[label] = {
                "installed": True,
                "version": match.group(1) if match and
                           process.returncode == 0 else None,
            }
        except (OSError, subprocess.TimeoutExpired):
            result[label] = {"installed": True, "version": None}
    return result


def workspace_state(path: Path) -> dict[str, Any]:
    result = {
        "exists": False, "realDirectory": False,
        "symlink": False, "diskFreeMiB": None,
        "ownerUidMatches": False, "ownerPermissionsPrivate": False,
    }
    try:
        info = path.lstat()
        result["exists"] = True
        result["symlink"] = stat.S_ISLNK(info.st_mode)
        if not stat.S_ISDIR(info.st_mode) or result["symlink"]:
            return result
        result["realDirectory"] = True
        result["ownerUidMatches"] = info.st_uid == os.geteuid()
        result["ownerPermissionsPrivate"] = not bool(info.st_mode & 0o077)
        disk = os.statvfs(path)
        result["diskFreeMiB"] = (disk.f_bavail * disk.f_frsize) // 1048576
    except OSError:
        pass
    return result


def memory_mib() -> dict[str, int | None]:
    values: dict[str, int | None] = {"totalMiB": None, "availableMiB": None}
    try:
        raw = Path("/proc/meminfo").read_text(encoding="ascii")[:8192]
        for line in raw.splitlines():
            for key, out in (("MemTotal", "totalMiB"),
                             ("MemAvailable", "availableMiB")):
                matched = re.fullmatch(rf"{key}:\s+(\d+)\s+kB", line)
                if matched:
                    values[out] = int(matched.group(1)) // 1024
    except (OSError, UnicodeError):
        pass
    return values


def repo_readiness(repo: Path | None) -> dict[str, Any]:
    result: dict[str, Any] = {
        "inspected": False, "status": None, "gateCount": None,
        "blockedGateCount": None,
    }
    if repo is None:
        return result
    path = repo / "config" / "operational-readiness.json"
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 32768:
            return result
        raw = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(raw, dict) or not isinstance(raw.get("gates"), dict):
            return result
        gates = raw["gates"]
        if any(type(value) is not bool for value in gates.values()):
            return result
        result["inspected"] = True
        result["status"] = raw.get("status") if raw.get("status") in (
            "BLOCKED", "HOLD", "READY", "NO_GO"
        ) else "UNRECOGNIZED"
        result["gateCount"] = len(gates)
        result["blockedGateCount"] = sum(value is False for value in gates.values())
    except (OSError, UnicodeError, ValueError, TypeError):
        pass
    return result


def build_report(workspace: Path, repo: Path | None,
                 probe_versions: bool = False) -> dict[str, Any]:
    release = read_os_release()
    return {
        "schemaVersion": "sites.staging.host-readiness-observation.v1",
        "component": "Imagine Sites Engine",
        "mode": (
            "LOCAL_VERSION_COMMAND_OBSERVATION"
            if probe_versions else "LOCAL_READ_ONLY_PREPARATION"
        ),
        "goLiveAuthorized": False,
        "homologationComplete": False,
        "liveEndpointTested": False,
        # With optional version commands, external side effects cannot be
        # proven absent, even when only standard --version flags are passed.
        "dockerDaemonContacted": None if probe_versions else False,
        "credentialsInspected": False,
        "customerDataInspected": False,
        "networkRequestsMade": None if probe_versions else False,
        "filesModifiedByProbe": False,
        "versionCommandsExecuted": probe_versions,
        "externalCommandSideEffectsNotAttested": probe_versions,
        "status": "BLOCKED_PENDING_OPERATOR_AND_SECURITY_REVIEW",
        "host": {
            "os": release,
            "preferredUbuntu2404": (
                release["id"] == "ubuntu" and release["version"] == "24.04"
            ),
            "architecture": platform.machine() if platform.machine() in
                            ("x86_64", "aarch64", "arm64") else "other",
            "cpuCount": os.cpu_count(),
            "memory": memory_mib(),
            "workspace": workspace_state(workspace),
            "cli": local_cli_versions(probe_versions=probe_versions),
        },
        "repository": repo_readiness(repo),
        "manualGatesRemaining": [
            "adminBranchProtectionAndIndependentReview",
            "isolatedVpsContainersAndNetwork",
            "trustedPortalIdentityAndRevocation",
            "separateSitesPostgresRlsAndRestore",
            "approvedStaticReleaseAndCloudflareReadback",
            "dualLeadCaptureAndCrmConfirmation",
            "domainOwnerConsentTlsAndPrivacyReview",
            "authorizedHumanGoLive",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Read-only Imagine Sites VPS preflight, NEVER deployment"
    )
    parser.add_argument("--workspace", type=Path, default=Path("."))
    parser.add_argument("--repo", type=Path)
    parser.add_argument(
        "--probe-versions", action="store_true",
        help="EXPLICIT opt-in to execute local version commands; default is safe PATH-only checks",
    )
    args = parser.parse_args()
    result = build_report(args.workspace, args.repo,
                          probe_versions=args.probe_versions)
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    # The diagnostic completes successfully to allow collecting a report,
    # but its JSON status is ALWAYS BLOCKED: not a release gate.
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
