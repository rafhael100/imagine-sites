#!/usr/bin/env python3
"""Offline adversarial tests for the non-authorizing Leaseweb staging preflight."""

import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "ops" / "homologation" / "leaseweb-readonly-preflight.py"
SPEC = importlib.util.spec_from_file_location("sites_leaseweb_preflight", SCRIPT)
assert SPEC and SPEC.loader
APP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(APP)


def isolated_compose():
    return {
        "services": {
            "sites-php": {
                "read_only": True, "privileged": False,
                "security_opt": ["no-new-privileges:true"],
                "cap_drop": ["ALL"], "restart": "no",
                "networks": {"sites-internal": None},
                "environment": {
                    "APP_ENV": "staging", "APP_DEBUG": "false",
                    "DB_HOST": "sites-database-not-provisioned",
                    "DB_DATABASE": "imagine_sites_unprovisioned",
                    "DB_PASSWORD": "",
                    "SITES_STAGING_APP_KEY": "secret-must-not-appear",
                },
            },
            "sites-proxy": {
                "read_only": True, "privileged": False,
                "security_opt": ["no-new-privileges:true"],
                "cap_drop": ["ALL"],
                "networks": {"sites-local-edge": None, "sites-internal": None},
                "ports": [
                    {"host_ip": "127.0.0.1", "published": "18081",
                     "target": 8080, "protocol": "tcp"}
                ],
            },
        },
        "networks": {
            "sites-internal": {"internal": True},
            "sites-local-edge": {"internal": False},
        },
    }


class LeasewebReadOnlyPreflightTests(unittest.TestCase):
    def test_safe_two_service_fixture_is_structurally_accepted_only(self):
        result = APP.examine_compose(isolated_compose())
        self.assertEqual(5, len(result))
        self.assertTrue(all(result.values()))

    def test_broken_network_and_privilege_are_blocked(self):
        for mutation in (
            lambda d: d["services"]["sites-proxy"]["ports"][0].update(
                host_ip="0.0.0.0"),
            lambda d: d["services"]["sites-proxy"]["ports"].append(
                {"host_ip": "127.0.0.1", "published": "443", "target": 443}),
            lambda d: d["services"]["sites-php"].update(ports=[
                {"host_ip": "0.0.0.0", "published": "9000", "target": 9000}]),
            lambda d: d["services"]["sites-php"].update(privileged=True),
            lambda d: d["services"]["sites-php"].update(read_only=False),
            lambda d: d["services"]["sites-php"].update(cap_drop=[]),
            lambda d: d["services"]["sites-php"].update(security_opt=[]),
            lambda d: d["services"]["sites-php"]["networks"].update(
                {"sites-local-edge": None}),
            lambda d: d["networks"]["sites-internal"].update(internal=False),
            lambda d: d["services"]["sites-php"]["environment"].update(
                APP_ENV="production"),
            lambda d: d["services"]["sites-php"]["environment"].update(
                DB_HOST="prod-db.example.invalid"),
            lambda d: d["services"]["sites-php"]["environment"].update(
                SITES_SYNTHETIC_HTTP="1"),
            lambda d: d["services"].update({"portal-live": {}}),
        ):
            config = copy.deepcopy(isolated_compose())
            mutation(config)
            self.assertFalse(all(APP.examine_compose(config).values()),
                             msg="Unsafe Compose mutation passed")

    def test_missing_structures_fail_closed(self):
        for config in ({}, {"services": {}}, {"networks": {}},
                       {"services": [], "networks": []}):
            self.assertTrue(
                all(value is False for value in APP.examine_compose(config).values())
            )

    def test_policy_cannot_be_signed_off_by_edited_gate_json(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp) / "readiness.json"
            state = {
                "schema": "imagine-sites.production-readiness.v1",
                "status": "BLOCKED",
                "non_operational": True,
                "gates": {f"gate_{i}": False for i in range(10)},
            }
            file.write_text(json.dumps(state), encoding="utf-8")
            self.assertTrue(APP.release_policy_ok(file))
            for changed in (
                {"status": "READY"},
                {"non_operational": False},
                {"gates": dict(state["gates"], gate_1=True)},
            ):
                revision = dict(state)
                revision.update(changed)
                file.write_text(json.dumps(revision), encoding="utf-8")
                self.assertFalse(APP.release_policy_ok(file))

    def test_structurally_green_fixture_never_authorizes_deploy(self):
        repo = SCRIPT.parents[2]
        result = APP.audit(repo, compose_doc=isolated_compose())
        self.assertEqual("blocked", result["status"])
        self.assertFalse(result["can_deploy"])
        self.assertFalse(result["can_publish"])
        self.assertFalse(result["can_accept_real_orders"])
        for gate in APP.NEVER_RELEASE:
            self.assertEqual("blocked", result["checks"][gate]["result"])
            self.assertIn(gate, result["blockers"])
        rendered = json.dumps(result)
        self.assertNotIn("secret-must-not-appear", rendered)
        self.assertNotIn("SITES_STAGING_APP_KEY", rendered)
        self.assertNotIn("DB_PASSWORD", rendered)

    def test_missing_runtime_config_never_authorizes(self):
        with tempfile.TemporaryDirectory() as temp:
            result = APP.audit(Path(temp), compose_doc={})
            self.assertFalse(result["can_deploy"])
            self.assertIn("policy_default_deny", result["blockers"])
            self.assertIn("service_inventory", result["blockers"])


if __name__ == "__main__":
    unittest.main()
