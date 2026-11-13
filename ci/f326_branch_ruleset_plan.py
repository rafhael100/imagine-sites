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


def inspect_active_branch_rules(branch: str) -> dict[str, Any]:
    rules = github_api(f"repos/{REPOSITORY}/rules/branches/{branch}?per_page=100")
    if not isinstance(rules, list):
        raise RuntimeError("GitHub returned non-list branch rules")
    types = {r.get("type") for r in rules if isinstance(r, dict)}
    return {
        "branch": branch,
        "hasPullRequest": "pull_request" in types,
        "hasStatusChecks": "required_status_checks" in types,
        "preventsForcePush": "non_fast_forward" in types,
        "preventsDeletion": "deletion" in types,
        "hasAllFourTypes": {
            "pull_request", "required_status_checks",
            "non_fast_forward", "deletion",
        }.issubset(types),
    }


def audit() -> int:
    reports = [inspect_active_branch_rules(b) for b in ("dev", "main")]
    for result in reports:
        print(json.dumps(result, sort_keys=True))
    if not all(p["hasAllFourTypes"] for p in reports):
        print("BLOCKED: missing active rule types; administrative review required",
              file=sys.stderr)
        return 2
    print(
        "F1-326: GitHub reports four active rule TYPES on dev/main; "
        "an administrator must still verify approval counts, contexts, "
        "bypass rights, role access and rejected direct pushes."
    )
    return 0


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
