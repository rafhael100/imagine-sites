#!/usr/bin/env python3
"""F1-64: read-only public-blocker inventory from operational-readiness.json.

This is a conservative CI reporter, NOT a production validator. Never marks
go-live authorized or checks an actual cloud, contract, user or secret.
"""
from __future__ import annotations

import argparse
import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CONFIG = ROOT / "config" / "operational-readiness.json"
GATE_ISSUES = {
    "signed_off_operational_contracts": 33,
    "trusted_portal_identity_and_revocation": 33,
    "separate_production_database_rls_backups": 34,
    "real_ai_generation_scoped_and_homologated": 37,
    "independent_verified_publication": 35,
    "physical_dual_capture_crm_confirmed": 36,
    "customer_approval_evidence_immutable": 38,
    "privacy_security_and_cost_review": 38,
    "client_domain_consent_and_certificates": 35,
    "release_go_live_authorization": 38,
}
LABELS = {
    "signed_off_operational_contracts": "Contratos e responsabilidades Portal/Sites",
    "trusted_portal_identity_and_revocation": "Identidade real, assinatura e revogação",
    "separate_production_database_rls_backups": "Banco isolado, RLS, backup e restore",
    "real_ai_generation_scoped_and_homologated": "Geração de sites IA/n8n real sob controle",
    "independent_verified_publication": "Prova independente da publicação",
    "physical_dual_capture_crm_confirmed": "Captação dupla e confirmação CRM",
    "customer_approval_evidence_immutable": "Evidência do aceite expresso do cliente",
    "privacy_security_and_cost_review": "Revisões LGPD/AppSec e custos",
    "client_domain_consent_and_certificates": "DNS, consentimento e certificados",
    "release_go_live_authorization": "Autorização final de go-live",
}


def inventory(data: object) -> dict:
    if not isinstance(data, dict) or set(data) != {
        "schema", "status", "checked_at_reference", "non_operational",
        "gates", "notes",
    }:
        raise ValueError("readiness file schema keys changed; require controlled review")
    if (
        data["schema"] != "imagine-sites.production-readiness.v1"
        or data["status"] != "BLOCKED"
        or data["non_operational"] is not True
        or not isinstance(data["checked_at_reference"], str)
        or not isinstance(data["notes"], str)
    ):
        raise ValueError("no production-ready status is authorized by F1-64")
    gates = data["gates"]
    if not isinstance(gates, dict) or set(gates) != set(GATE_ISSUES):
        raise ValueError("operational gate list changed; require controlled review")
    if any(type(value) is not bool for value in gates.values()):
        raise ValueError("readiness gate flags must be exact booleans")
    if any(gates.values()):
        raise ValueError("a true gate needs independent evidence review; CI cannot approve it")
    blockers = [
        {
            "key": key,
            "label": LABELS[key],
            "issue": f"https://github.com/rafhael100/imagine-sites/issues/{issue}",
            "declaredComplete": False,
        }
        for key, issue in GATE_ISSUES.items()
    ]
    return {
        "schemaVersion": "sites.synthetic.readiness-inventory.v1",
        "sourceDate": data["checked_at_reference"],
        "source": "config/operational-readiness.json",
        "status": "BLOCKED",
        "operationallyVerified": False,
        "releaseAuthorized": False,
        "totalGateCount": len(GATE_ISSUES),
        "blockedGateCount": len(blockers),
        "blockers": blockers,
        "manualDependencies": [
            "Acesso autorizado à VPS Leaseweb para homologação",
            "Cloudflare e domínio técnico de teste autorizados",
            "Credenciais em gerenciador de segredos, nunca no GitHub público ou chat",
            "Evidências reais independentes para cada gate e revisão humana",
        ],
    }


def self_test(actual: dict) -> None:
    valid = inventory(actual)
    assert valid["status"] == "BLOCKED"
    assert valid["blockedGateCount"] == 10
    assert all(not item["declaredComplete"] for item in valid["blockers"])
    assert valid["releaseAuthorized"] is False
    mutations = []
    for name, value in [
        ("status", "READY"), ("non_operational", False),
        ("gates", None),
    ]:
        item = copy.deepcopy(actual)
        item[name] = value
        mutations.append(item)
    for new_gate, value in [
        ("release_go_live_authorization", True),
        ("release_go_live_authorization", "false"),
    ]:
        item = copy.deepcopy(actual)
        item["gates"][new_gate] = value
        mutations.append(item)
    missing = copy.deepcopy(actual)
    missing["gates"].pop("privacy_security_and_cost_review")
    mutations.append(missing)
    extra = copy.deepcopy(actual)
    extra["gates"]["ad_hoc_verified"] = False
    mutations.append(extra)
    for idx, mutated in enumerate(mutations):
        try:
            inventory(mutated)
        except ValueError:
            pass
        else:
            raise AssertionError(f"unsafe readiness mutation #{idx} was accepted")
    print("PASS F1-64: 10 blocked gates, all unsafe readiness mutations denied")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true",
                        help="Machine-readable evidence inventory, never authorizes release")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    with CONFIG.open(encoding="utf-8") as stream:
        source = json.load(stream)
    result = inventory(source)
    if args.self_test:
        self_test(source)
        return
    if args.json:
        print(json.dumps(result,ensure_ascii=False,indent=2))
        return
    print(f"IMAGINE SITES READINESS: {result['status']}")
    print(f"Pending: {result['blockedGateCount']}/{result['totalGateCount']} operational gates")
    for b in result["blockers"]:
        print(f" - [PENDING] {b['label']} -> {b['issue']}")
    print("Go-live authorized: NO; evidence verification: NOT PERFORMED")
    print("No services accessed, credentials used, payment initiated or artifact deployed.")


if __name__ == "__main__":
    main()
