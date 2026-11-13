#!/usr/bin/env python3
"""F1-326: offline-first GitHub ruleset plan for Imagine Sites dev/main.

Default PLAN and SELF-TEST use no credentials, network, customer files or
external packages. APPLY is an intentionally separate operator-only action:
requires an authenticated repository administrator, a real independent
reviewer and multiple explicit acknowledgments. Never called from CI.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
import subprocess
import sys
from typing import Any

REPOSITORY = "rafhael100/imagine-sites"
RULESET_NAME = "Imagine Sites P0 - dev/main independent review"
BRANCHES = ["refs/heads/dev", "refs/heads/main"]
# Actual always-on GitHub Actions job names observed on the integrated SHA.
# Do NOT require a path-filtered workflow as it might never run on a PR.
REQUIRED_CHECKS = [
    "prevent-accidental-production",
    "pure-domain (8.3)",
    "pure-domain (8.4)",
]


def make_plan() -> dict[str, Any]:
    return {
        "name": RULESET_NAME,
        "target": "branch",
        "enforcement": "active",
        "bypass_actors": [],
        "conditions": {
            "ref_name": {"include": BRANCHES, "exclude": []},
        },
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {
                "type": "pull_request",
                "parameters": {
                    "allowed_merge_methods": ["merge", "squash", "rebase"],
                    "dismiss_stale_reviews_on_push": True,
                    "require_code_owner_review": False,
                    "require_last_push_approval": True,
                    "required_approving_review_count": 1,
                    "required_review_thread_resolution": True,
                },
            },
            {
                "type": "required_status_checks",
                "parameters": {
                    "required_status_checks": [
                        {"context": name} for name in REQUIRED_CHECKS
                    ],
                    "strict_required_status_checks_policy": True,
                },
            },
        ],
    }


def validate_plan(plan: dict[str, Any]) -> None:
    if (
        set(plan) != {
            "name", "target", "enforcement", "bypass_actors",
            "conditions", "rules",
        }
        or plan["name"] != RULESET_NAME
        or plan["target"] != "branch"
        or plan["enforcement"] != "active"
        or plan["bypass_actors"] != []
        or plan["conditions"]
        != {"ref_name": {"include": BRANCHES, "exclude": []}}
    ):
        raise ValueError("noncanonical or bypassable ruleset scope")
    rules = plan["rules"]
    if (
        not isinstance(rules, list)
        or [rule.get("type") for rule in rules]
        != ["deletion", "non_fast_forward", "pull_request",
            "required_status_checks"]
        or rules[0] != {"type": "deletion"}
        or rules[1] != {"type": "non_fast_forward"}
    ):
        raise ValueError("mandatory ref safety rules absent")
    pr = rules[2]["parameters"]
    if (
        pr != {
            "allowed_merge_methods": ["merge", "squash", "rebase"],
            "dismiss_stale_reviews_on_push": True,
            "require_code_owner_review": False,
            "require_last_push_approval": True,
            "required_approving_review_count": 1,
            "required_review_thread_resolution": True,
        }
    ):
        raise ValueError("independent PR approval contract changed")
    checks = rules[3]["parameters"]
    if checks != {
        "required_status_checks": [
            {"context": name} for name in REQUIRED_CHECKS
        ],
        "strict_required_status_checks_policy": True,
    }:
        raise ValueError("always-on required-check policy changed")


def canonical_bytes(plan: dict[str, Any]) -> bytes:
    return json.dumps(
        plan, ensure_ascii=False, sort_keys=True, separators=(",", ":"),
    ).encode("utf-8")


def self_test() -> None:
    plan = make_plan()
    validate_plan(plan)
    variants = []
    for mutate in (
        lambda p: p.update(enforcement="disabled"),
        lambda p: p["bypass_actors"].append(
            {"actor_id": 1, "actor_type": "User", "bypass_mode": "always"}
        ),
        lambda p: p["conditions"]["ref_name"]["include"].remove(
            "refs/heads/main"
        ),
        lambda p: p["rules"][2]["parameters"].update(
            required_approving_review_count=0
        ),
        lambda p: p["rules"][2]["parameters"].update(
            require_last_push_approval=False
        ),
        lambda p: p["rules"][3]["parameters"]["required_status_checks"].pop(),
        lambda p: p["rules"][3]["parameters"].update(
            strict_required_status_checks_policy=False
        ),
    ):
        bad = copy.deepcopy(plan)
        mutate(bad)
        variants.append(bad)
    for index, bad in enumerate(variants):
        try:
            validate_plan(bad)
        except ValueError:
            continue
        raise AssertionError(f"unsafe policy mutation {index} accepted")
    print("F1-326: 1 canonical policy and 7 negative mutations verified offline")
    audit_self_test()


def github_api(
    path: str,
    *,
    method: str = "GET",
    data: bytes | None = None,
) -> Any:
    if os.environ.get("GITHUB_ACTIONS") == "true":
        raise RuntimeError("F1-326 forbids authenticated API calls from CI")
    cmd = [
        "gh", "api", "--method", method,
        "-H", "Accept: application/vnd.github+json",
        path,
    ]
    if data is not None:
        cmd += ["--input", "-"]
    try:
        result = subprocess.run(
            cmd, input=data, capture_output=True, timeout=35, check=False,
        )
    except (FileNotFoundError, OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError(
            "GitHub CLI authentication/availability required locally"
        ) from exc
    if result.returncode != 0:
        # Never echo auth data, remote request bodies or token-bearing errors.
        raise RuntimeError(
            f"GitHub admin API request failed ({method}, exit "
            f"{result.returncode}); inspect gh auth status privately"
        )
    try:
        return json.loads(result.stdout)
    except (UnicodeError, ValueError) as exc:
        raise RuntimeError("GitHub response is not a JSON object/list") from exc


def evaluate_active_rules(branch: str, rules: list[Any]) -> dict[str, Any]:
    """F1-327: check the actual active-rule PARAMETERS, not just rule types.

    This cannot inspect bypass rights, reviewer availability or a rejected
    direct push. A separate authenticated admin audit remains mandatory.
    """
    if branch not in ("dev", "main"):
        raise ValueError("only exact protected branch names may be audited")
    known = [r for r in rules if isinstance(r, dict)]
    types = {r.get("type") for r in known}
    pr_secure = False
    checks_secure = False
    for rule in known:
        params = rule.get("parameters")
        if not isinstance(params, dict):
            continue
        if rule.get("type") == "pull_request":
            approvals = params.get("required_approving_review_count")
            if (
                type(approvals) is int and approvals >= 1
                and params.get("dismiss_stale_reviews_on_push") is True
                and params.get("require_last_push_approval") is True
                and params.get("required_review_thread_resolution") is True
            ):
                pr_secure = True
        if rule.get("type") == "required_status_checks":
            entries = params.get("required_status_checks")
            if not isinstance(entries, list):
                continue
            contexts = {
                item["context"] for item in entries
                if isinstance(item, dict)
                and isinstance(item.get("context"), str)
            }
            if (
                params.get("strict_required_status_checks_policy") is True
                and set(REQUIRED_CHECKS).issubset(contexts)
            ):
                checks_secure = True

    all_types = {
        "pull_request", "required_status_checks",
        "non_fast_forward", "deletion",
    }.issubset(types)
    return {
        "branch": branch,
        "hasAllFourTypes": all_types,
        "independentReviewParameters": pr_secure,
        "alwaysOnRequiredChecks": checks_secure,
        "hasEnforcedBaseline": all_types and pr_secure and checks_secure,
        "bypassAndDirectPushNotVerified": True,
    }


def inspect_active_branch_rules(branch: str) -> dict[str, Any]:
    rules = github_api(f"repos/{REPOSITORY}/rules/branches/{branch}?per_page=100")
    if not isinstance(rules, list):
        raise RuntimeError("GitHub returned non-list branch rules")
    return evaluate_active_rules(branch, rules)


def audit() -> int:
    reports = [inspect_active_branch_rules(b) for b in ("dev", "main")]
    for result in reports:
        print(json.dumps(result, sort_keys=True))
    if not all(p["hasEnforcedBaseline"] for p in reports):
        print(
            "BLOCKED: at least one branch lacks effective approval/check "
            "parameters or anti-delete/force-push rules",
            file=sys.stderr,
        )
        return 2
    print(
        "F1-327: GitHub reports the expected active rules and parameters "
        "on both branches. Admin must STILL check bypass rights, reviewer "
        "access, CODEOWNERS and rejected direct pushes; not a release permit."
    )
    return 0


def audit_self_test() -> None:
    plan = make_plan()
    rows = copy.deepcopy(plan["rules"])
    if not evaluate_active_rules("dev", rows)["hasEnforcedBaseline"]:
        raise AssertionError("valid fixture rejected")
    if not evaluate_active_rules("main", rows)["hasEnforcedBaseline"]:
        raise AssertionError("main valid fixture rejected")

    mutations = [
        lambda r: r.pop(0),  # deletion protection absent
        lambda r: r.pop(1),  # force-push protection absent
        lambda r: r[2]["parameters"].update(required_approving_review_count=0),
        lambda r: r[2]["parameters"].update(require_last_push_approval=False),
        lambda r: r[2]["parameters"].update(
            dismiss_stale_reviews_on_push=False
        ),
        lambda r: r[2]["parameters"].update(
            required_review_thread_resolution=False
        ),
        lambda r: r[3]["parameters"]["required_status_checks"].pop(),
        lambda r: r[3]["parameters"].update(
            strict_required_status_checks_policy=False
        ),
    ]
    for index, mutate in enumerate(mutations):
        bad = copy.deepcopy(rows)
        mutate(bad)
        if evaluate_active_rules("dev", bad)["hasEnforcedBaseline"]:
            raise AssertionError(f"unsafe active rule mutation {index} accepted")
    print("F1-327: 2 valid branch baselines and 8 negative audits passed offline")


def apply_plan(*, confirm_repository: str, reviewer_ready: bool,
               admin_change_approved: bool) -> int:
    if os.environ.get("GITHUB_ACTIONS") == "true":
        raise RuntimeError("Refusing to change repo settings from CI")
    if (
        confirm_repository != REPOSITORY
        or not reviewer_ready
        or not admin_change_approved
    ):
        raise ValueError(
            "Explicit exact repository confirmation, real independent reviewer "
            "and administrator change approval are mandatory"
        )
    plan = make_plan()
    validate_plan(plan)
    current = github_api(f"repos/{REPOSITORY}/rulesets?per_page=100")
    if not isinstance(current, list):
        raise RuntimeError("Existing rulesets could not be inspected safely")
    if any(isinstance(item, dict) and item.get("name") == RULESET_NAME
           for item in current):
        raise RuntimeError(
            "Ruleset already exists: refusing duplicate or silent overwrite"
        )
    response = github_api(
        f"repos/{REPOSITORY}/rulesets",
        method="POST", data=canonical_bytes(plan),
    )
    if not isinstance(response, dict) or not isinstance(response.get("id"), int):
        raise RuntimeError("Created ruleset could not be verified")
    print(f"F1-326 admin action submitted, ruleset id {response['id']}.")
    return audit()


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Review-only plan or explicitly authorized GitHub ruleset"
    )
    parser.add_argument(
        "action", nargs="?", default="plan",
        choices=("plan", "self-test", "audit", "apply"),
    )
    parser.add_argument("--confirm-repository", default="")
    parser.add_argument("--reviewer-ready", action="store_true")
    parser.add_argument("--admin-change-approved", action="store_true")
    args = parser.parse_args()
    if args.action == "self-test":
        self_test()
        return 0
    if args.action == "plan":
        plan = make_plan()
        validate_plan(plan)
        print(json.dumps(plan, ensure_ascii=False, indent=2))
        print(
            "SHA256 " + hashlib.sha256(canonical_bytes(plan)).hexdigest(),
            file=sys.stderr,
        )
        return 0
    if args.action == "audit":
        return audit()
    return apply_plan(
        confirm_repository=args.confirm_repository,
        reviewer_ready=args.reviewer_ready,
        admin_change_approved=args.admin_change_approved,
    )


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, RuntimeError) as exc:
        print(f"F1-326 blocked: {exc}", file=sys.stderr)
        raise SystemExit(2) from None
