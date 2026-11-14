#!/usr/bin/env python3
"""P0 Leaseweb readiness inspection: read-only, non-authorizing, no secrets.

Does not SSH, connect to external providers, start containers, create secrets,
modify firewall/DNS, deploy, merge, or read an existing application .env.
For explicit operator use only. A green structural finding is NOT go-live.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
from typing import Any

EXPECTED_KEY = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
REQUIRED_SERVICES = {"sites-php", "sites-proxy"}
REQUIRED_RELEASE_GATES = frozenset({
    "signed_off_operational_contracts",
    "trusted_portal_identity_and_revocation",
    "separate_production_database_rls_backups",
    "real_ai_generation_scoped_and_homologated",
    "independent_verified_publication",
    "physical_dual_capture_crm_confirmed",
    "customer_approval_evidence_immutable",
    "privacy_security_and_cost_review",
    "client_domain_consent_and_certificates",
    "release_go_live_authorization",
})
NEVER_RELEASE = {
    "leaseweb_access_and_isolation": "operator_leaseweb_access_not_verified",
    "portal_s2s_mtls_and_iam": "portal_s2s_identity_not_operationally_verified",
    "asaas_settlement": "real_psp_settlement_not_verified",
    "customer_acceptance": "customer_mfa_consent_not_verified",
    "independent_revocation": "independent_revocation_and_restore_not_verified",
    "pg18_rls_backup_restore": "persistent_pg18_rls_backup_restore_not_verified",
    "cloudflare_publish_and_rollback": "no_authorized_cdn_publication_or_rollback",
    "security_privacy_review": "appsec_lgpd_review_not_signed_off",
    "explicit_go_live_authorization": "no_human_release_authorization",
}


def check(results: dict[str, dict[str, str]], key: str, ok: bool,
          success: str, failure: str) -> None:
    results[key] = {
        "result": "verified" if ok else "blocked",
        "reason": success if ok else failure,
    }


def release_policy_ok(path: Path) -> bool:
    """A readiness file that claims GO/true is a hard failure, never release proof."""
    try:
        if path.is_symlink() or not path.is_file():
            return False
        raw = json.loads(path.read_text(encoding="utf-8"))
        gates = raw.get("gates")
        return (
            raw.get("schema") == "imagine-sites.production-readiness.v1"
            and raw.get("status") == "BLOCKED"
            and raw.get("non_operational") is True
            and isinstance(gates, dict)
            and set(gates) == REQUIRED_RELEASE_GATES
            and all(value is False for value in gates.values())
        )
    except (OSError, UnicodeError, ValueError, TypeError, AttributeError):
        return False


def examine_compose(doc: dict[str, Any]) -> dict[str, bool]:
    """Assess rendered docker compose JSON in memory; never print it."""
    services = doc.get("services")
    networks = doc.get("networks")
    if not isinstance(services, dict) or not isinstance(networks, dict):
        return {name: False for name in (
            "service_inventory", "loopback_only", "php_isolation",
            "staging_configuration", "network_separation",
        )}
    php = services.get("sites-php", {})
    proxy = services.get("sites-proxy", {})
    if not isinstance(php, dict) or not isinstance(proxy, dict):
        return {name: False for name in (
            "service_inventory", "loopback_only", "php_isolation",
            "staging_configuration", "network_separation",
        )}

    ports = proxy.get("ports")
    if not isinstance(ports, list):
        ports = []
    only_one_local_port = (
        len(ports) == 1
        and isinstance(ports[0], dict)
        and ports[0].get("host_ip") == "127.0.0.1"
        and str(ports[0].get("published")) == "18081"
        and str(ports[0].get("target")) == "8080"
        and ports[0].get("protocol", "tcp") == "tcp"
    )

    php_env = php.get("environment", {})
    if not isinstance(php_env, dict):
        php_env = {}
    unexpected_fixture_flags = any(
        str(k).startswith("SITES_SYNTHETIC_")
        or (str(k).startswith("SITES_F") and str(k).endswith("_LAB"))
        for k in php_env
    )
    php_networks = php.get("networks", {})
    proxy_networks = proxy.get("networks", {})
    network_internal = networks.get("sites-internal", {})
    if isinstance(php_networks, list):
        php_networks = dict.fromkeys(php_networks)
    if isinstance(proxy_networks, list):
        proxy_networks = dict.fromkeys(proxy_networks)
    if not isinstance(php_networks, dict):
        php_networks = {}
    if not isinstance(proxy_networks, dict):
        proxy_networks = {}

    def hardened(service: dict[str, Any]) -> bool:
        options = service.get("security_opt", [])
        caps = service.get("cap_drop", [])
        return (
            service.get("read_only") is True
            and service.get("privileged") is not True
            and isinstance(options, list)
            and "no-new-privileges:true" in options
            and isinstance(caps, list)
            and "ALL" in caps
            and not service.get("network_mode")
        )

    return {
        "service_inventory": set(services) == REQUIRED_SERVICES,
        "loopback_only": only_one_local_port
            and not php.get("ports") and not php.get("expose_host_ports"),
        "php_isolation": hardened(php) and hardened(proxy)
            and php.get("restart") in ("no", None),
        "staging_configuration":
            php_env.get("APP_ENV") == "staging"
            and str(php_env.get("APP_DEBUG")).lower() == "false"
            and php_env.get("DB_HOST") == "sites-database-not-provisioned"
            and php_env.get("DB_DATABASE") == "imagine_sites_unprovisioned"
            and php_env.get("DB_PASSWORD") == ""
            and not unexpected_fixture_flags,
        "network_separation":
            set(php_networks) == {"sites-internal"}
            and set(proxy_networks) == {"sites-local-edge", "sites-internal"}
            and isinstance(network_internal, dict)
            and network_internal.get("internal") is True,
    }


def safe_compose_snapshot(repo: Path) -> dict[str, Any] | None:
    """Compose config only; no up/build and no real key interpolation."""
    compose = repo / "ops" / "homologation" / "compose.yaml"
    if compose.is_symlink() or not compose.is_file():
        return None
    docker = shutil.which("docker")
    if not docker:
        return None
    env = os.environ.copy()
    # Never use the existing host's possibly real staging key to render config.
    env["SITES_STAGING_APP_KEY"] = EXPECTED_KEY
    try:
        completed = subprocess.run(
            [docker, "compose", "-f", str(compose),
             "config", "--format", "json"],
            cwd=repo, env=env, capture_output=True, text=True,
            check=False, timeout=30,
        )
        if completed.returncode != 0 or len(completed.stdout) > 100000:
            return None
        doc = json.loads(completed.stdout)
        return doc if isinstance(doc, dict) else None
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return None


def safe_host_probe() -> dict[str, bool]:
    """Only local read-only probes; never print OS, daemon or network details."""
    docker = shutil.which("docker")
    try:
        release = platform.freedesktop_os_release()
        ubuntu24 = release.get("ID") == "ubuntu" and release.get("VERSION_ID") == "24.04"
    except (OSError, ValueError, AttributeError):
        ubuntu24 = False

    compose_available = False
    daemon_available = False
    if docker:
        for command, label in (
            ([docker, "compose", "version", "--short"], "compose"),
            ([docker, "info", "--format", "{{.ServerVersion}}"], "daemon"),
        ):
            try:
                result = subprocess.run(
                    command, capture_output=True, text=True,
                    check=False, timeout=12,
                )
                valid = result.returncode == 0 and bool(result.stdout.strip())
                if label == "compose":
                    compose_available = valid
                else:
                    daemon_available = valid
            except (OSError, subprocess.TimeoutExpired):
                pass
    return {
        "ubuntu_24_04": ubuntu24,
        "docker_compose_readable": compose_available,
        "docker_daemon_readable": daemon_available,
    }


def audit(repo: Path, *, probe_host: bool = False,
          compose_doc: dict[str, Any] | None = None) -> dict[str, Any]:
    checks: dict[str, dict[str, str]] = {}
    check(checks, "policy_default_deny",
          release_policy_ok(repo / "config" / "operational-readiness.json"),
          "explicit_no_go_retained", "readiness_policy_absent_or_unsafe")
    snapshot = compose_doc if compose_doc is not None else safe_compose_snapshot(repo)
    findings = examine_compose(snapshot) if snapshot is not None else {}
    reasons = {
        "service_inventory": "unexpected_staging_service_inventory",
        "loopback_only": "network_ingress_not_proven_loopback_only",
        "php_isolation": "runtime_privilege_isolation_not_verified",
        "staging_configuration": "staging_or_placeholder_db_not_enforced",
        "network_separation": "private_network_boundary_not_verified",
    }
    for key, denial in reasons.items():
        check(checks, key, findings.get(key, False),
              "compose_structure_verified_only", denial)
    if probe_host:
        host = safe_host_probe()
        for key in ("ubuntu_24_04", "docker_compose_readable",
                    "docker_daemon_readable"):
            check(checks, key, host[key], "local_read_only_probe_verified",
                  "operator_host_prerequisite_unverified")

    # These gates cannot be promoted by an environment variable, compose config,
    # CI status, /up health response or this nonprivileged read-only diagnostic.
    for key, explanation in NEVER_RELEASE.items():
        check(checks, key, False, "not_applicable", explanation)
    return {
        "schemaVersion": "sites.leaseweb.readonly-preflight.v1",
        "scope": "operator_or_ci_read_only_no_network_mutation",
        "status": "blocked",
        "can_deploy": False,
        "can_accept_real_orders": False,
        "can_publish": False,
        "checks": checks,
        "blockers": [k for k, finding in checks.items()
                     if finding["result"] == "blocked"],
        "notice": "Read-only evidence only; human-reviewed IAM, PSP, restore, consent and release gates remain closed.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Read-only Leaseweb/Sites preflight; NEVER grants GO."
    )
    parser.add_argument("--repo-root", type=Path,
                        default=Path(__file__).resolve().parents[2])
    parser.add_argument("--probe-host", action="store_true",
                        help="Read only OS and Docker daemon/Compose availability")
    args = parser.parse_args()
    try:
        result = audit(args.repo_root.resolve(), probe_host=args.probe_host)
    except Exception:
        # Never echo secrets, paths, command exception text, or infrastructure.
        result = {
            "schemaVersion": "sites.leaseweb.readonly-preflight.v1",
            "status": "blocked", "can_deploy": False,
            "can_accept_real_orders": False, "can_publish": False,
            "blockers": ["preflight_unavailable"],
        }
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 1  # An intentionally blocked release preflight is not a CI failure.


if __name__ == "__main__":
    raise SystemExit(main())
