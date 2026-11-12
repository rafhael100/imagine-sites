#!/usr/bin/env python3
"""F1-73: static deny-first guard for a NON-RUNNING Docker Compose lab profile.

Does not parse all YAML, run Docker, attest dependencies or authorize staging.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
COMPOSE = ROOT / "ops/staging/compose.yml"
DOCKERFILE = ROOT / "ops/staging/Dockerfile"
PROHIBITED = [
    (r"(?m)^[ \t]*ports[ \t]*:", "published ports"),
    (r"(?m)^[ \t]*network_mode[ \t]*:[ \t]*host\b", "host network"),
    (r"(?m)^[ \t]*privileged[ \t]*:[ \t]*true\b", "privileged container"),
    (r"(?m)^[ \t]*restart[ \t]*:[ \t]*(?:always|unless-stopped)\b", "automatic persistent runtime"),
    (r"(?m)^[ \t]*APP_ENV[ \t]*:[ \t]*['\"]?production\b", "production environment"),
    (r"(?m)^[ \t]*SITES_SYNTHETIC_.*:[ \t]*['\"]?1\b", "implicit lab feature flags"),
]
FORBIDDEN_DOCKER = [
    (r"(?m)^[ \t]*EXPOSE\b", "exposed listener"),
    (r"(?m)^[ \t]*ENTRYPOINT\b", "unexpected entrypoint"),
    (r"(?m)^[ \t]*CMD[^\n]*(?:artisan[ \t]+serve|php-fpm|nginx|apache)", "web server process"),
]


def inspect(compose: str, dockerfile: str) -> None:
    for expression, description in PROHIBITED:
        if re.search(expression, compose, re.IGNORECASE):
            raise ValueError("unsafe lab compose: " + description)
    for expression, description in FORBIDDEN_DOCKER:
        if re.search(expression, dockerfile, re.IGNORECASE):
            raise ValueError("unsafe lab Dockerfile: " + description)
    required_compose = [
        "sites-lab-db:", "sites-lab-tests:", "profiles: [\"lab\"]",
        "internal: true", "no-new-privileges:true", "cap_drop: [\"ALL\"]",
        "SITES_LAB_DB_PASSWORD:?", "restart: \"no\"", "APP_ENV: testing",
        "GITHUB_ACTIONS: ${GITHUB_ACTIONS:-false}",
    ]
    for item in required_compose:
        if item not in compose:
            raise ValueError("lab profile missing safeguard: " + item)
    if compose.count("profiles: [\"lab\"]") != 2:
        raise ValueError("both services must be opt-in lab only")
    if compose.count("SITES_LAB_DB_PASSWORD:?") != 2:
        raise ValueError("both services must require a private ephemeral DB password")
    required_docker = ["USER 10001:10001", "php:8.4-cli-bookworm",
                       "CMD [\"php\", \"artisan\", \"test\", \"--no-ansi\"]"]
    for item in required_docker:
        if item not in dockerfile:
            raise ValueError("lab image missing safeguard: " + item)


def self_test(compose: str, dockerfile: str) -> None:
    inspect(compose, dockerfile)
    samples = [
        (compose + "\nports:\n  - 8080:8000\n", dockerfile),
        (compose + "\nnetwork_mode: host\n", dockerfile),
        (compose + "\nprivileged: true\n", dockerfile),
        (compose.replace('restart: "no"', 'restart: always'), dockerfile),
        (compose.replace("internal: true", "internal: false"), dockerfile),
        (compose.replace('profiles: ["lab"]', 'profiles: ["production"]'), dockerfile),
        (compose.replace("APP_ENV: testing", "APP_ENV: production"), dockerfile),
        # A local Docker invocation may never silently pretend to be CI.
        (compose.replace("GITHUB_ACTIONS: ${GITHUB_ACTIONS:-false}",
                         "GITHUB_ACTIONS: \"true\""), dockerfile),
        (compose, dockerfile + "\nEXPOSE 80\n"),
        (compose, dockerfile.replace(
            'CMD ["php", "artisan", "test", "--no-ansi"]',
            'CMD ["php", "artisan", "serve"]'
        )),
    ]
    for i, (wrong_compose, wrong_docker) in enumerate(samples):
        try:
            inspect(wrong_compose, wrong_docker)
        except ValueError:
            continue
        raise AssertionError(f"unsafe lab mutation #{i} was accepted")


def main() -> None:
    compose = COMPOSE.read_text(encoding="utf-8")
    dockerfile = DOCKERFILE.read_text(encoding="utf-8")
    self_test(compose, dockerfile)
    print("PASS F1-73: opt-in isolated lab definition, all negative mutations denied")
    print("NOT a Docker execution, operational test, production authorization or go-live")


if __name__ == "__main__":
    main()
