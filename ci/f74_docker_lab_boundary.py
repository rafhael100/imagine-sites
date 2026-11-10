#!/usr/bin/env python3
"""F1-74: verify the lab build-context boundary and rendered Compose without running it.

This is a static/semantic regression gate, NOT a Docker build, image scan,
network boundary proof, production attestation or permission to deploy.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
IGNORE = ROOT / "ops/staging/Dockerfile.dockerignore"
DOCKERFILE = ROOT / "ops/staging/Dockerfile"
ALLOW = (
    "!apps/", "!apps/sites/", "!apps/sites/**", "!src/", "!src/**",
    "!fixtures/", "!fixtures/**", "!labs/", "!labs/**",
    "!contracts/", "!contracts/**",
)
DENY = (
    "**/.env", "**/.env.*", "**/.git", "**/.git/**", "**/.ssh",
    "**/.ssh/**", "**/vendor", "**/vendor/**", "**/node_modules",
    "**/node_modules/**", "**/storage", "**/storage/**",
    "**/bootstrap/cache", "**/bootstrap/cache/**", "**/.npmrc",
    "**/.composer/auth.json", "**/auth.json", "**/*.pem", "**/*.key",
    "**/*.p12", "**/*.pfx", "**/id_rsa", "**/id_ed25519",
    "**/*credentials*", "**/*secret*",
)
SERVICES = {"sites-lab-db", "sites-lab-tests"}
NETWORK = "sites-lab-isolated"


def check_context(ignore: str, dockerfile: str) -> None:
    rules = [
        s.strip() for line in ignore.splitlines()
        if (s := line.strip()) and not s.startswith("#")
    ]
    expected = ["**", *ALLOW, *DENY]
    if rules != expected:
        raise ValueError("build-context allowlist/denylist changed or reordered")
    if "COPY apps/sites/ ./apps/sites/" not in dockerfile or (
        "COPY src/ ./src/" not in dockerfile
    ):
        raise ValueError("image must copy explicit application/source folders")
    if "COPY . " in dockerfile or "ADD " in dockerfile:
        raise ValueError("unrestricted COPY/ADD is not allowed")
    cache_setup = "mkdir -p apps/sites/storage/framework/cache/data"
    install = "composer install --no-interaction"
    if cache_setup not in dockerfile or install not in dockerfile:
        raise ValueError("missing reproducible Laravel build prerequisites")
    if dockerfile.index(cache_setup) > dockerfile.index(install):
        raise ValueError("cache must exist before composer install scripts")
    if "USER 10001:10001" not in dockerfile:
        raise ValueError("runtime must not run as root")
    if "CMD [\"php\", \"artisan\", \"test\", \"--no-ansi\"]" not in dockerfile:
        raise ValueError("runtime must execute only a CLI test process")


def inspect_rendered(value: object) -> None:
    if not isinstance(value, dict):
        raise ValueError("Compose config must be an object")
    services = value.get("services")
    nets = value.get("networks")
    if not isinstance(services, dict) or set(services) != SERVICES:
        raise ValueError("unexpected service inventory")
    if not isinstance(nets, dict) or set(nets) != {NETWORK}:
        raise ValueError("unexpected network inventory")
    if nets[NETWORK].get("internal") is not True or (
        nets[NETWORK].get("external") is True
    ):
        raise ValueError("lab network must be private and internal")
    for name, service in services.items():
        if not isinstance(service, dict):
            raise ValueError("invalid service")
        if service.get("profiles") != ["lab"] or service.get("restart") != "no":
            raise ValueError("services must be opt-in and not restart")
        if service.get("networks") is None or set(service["networks"]) != {NETWORK}:
            raise ValueError("service network must be isolated")
        for key in ("ports", "expose", "devices", "cap_add", "network_mode",
                    "extra_hosts", "secrets", "configs"):
            if key in service and service[key]:
                raise ValueError(f"{name} has forbidden field: {key}")
        if service.get("privileged") is True:
            raise ValueError("privileged container not allowed")
        if "no-new-privileges:true" not in service.get("security_opt", []):
            raise ValueError("no-new-privileges must be active")
        if service.get("environment", {}).get("APP_ENV") == "production":
            raise ValueError("production env is forbidden")
    db, test = services["sites-lab-db"], services["sites-lab-tests"]
    if db.get("image") != "postgres:18":
        raise ValueError("unexpected PostgreSQL major version")
    if test.get("cap_drop") != ["ALL"]:
        raise ValueError("test process must drop all capabilities")
    if test.get("build", {}).get("dockerfile") != "ops/staging/Dockerfile":
        raise ValueError("unexpected Dockerfile")
    test_volumes = test.get("volumes") or []
    if test_volumes:
        raise ValueError("test container should have no attached filesystem")
    db_volumes = db.get("volumes") or []
    if len(db_volumes) != 1 or (
        db_volumes[0].get("type") != "volume"
        or db_volumes[0].get("source") != "sites-lab-data"
        or db_volumes[0].get("target") != "/var/lib/postgresql"
    ):
        raise ValueError("DB must use its one named disposable volume")
    for name, service in services.items():
        env = service.get("environment", {})
        if not isinstance(env, dict):
            raise ValueError("unexpected environment")
        if not isinstance(env.get("DB_PASSWORD" if name == "sites-lab-tests" else "POSTGRES_PASSWORD"), str):
            raise ValueError("required ephemeral DB password absent")


def fixture() -> dict:
    base = {
        "profiles": ["lab"], "restart": "no",
        "networks": {NETWORK: None},
        "security_opt": ["no-new-privileges:true"],
    }
    db = {
        **base, "image": "postgres:18",
        "environment": {"POSTGRES_PASSWORD": "SYNTHETIC-ONLY"},
        "volumes": [{
            "type": "volume", "source": "sites-lab-data",
            "target": "/var/lib/postgresql",
        }],
    }
    test = {
        **base, "cap_drop": ["ALL"],
        "build": {"dockerfile": "ops/staging/Dockerfile"},
        "environment": {"DB_PASSWORD": "SYNTHETIC-ONLY", "APP_ENV": "testing"},
    }
    return {
        "services": {"sites-lab-db": db, "sites-lab-tests": test},
        "networks": {NETWORK: {"internal": True}},
    }


def self_test(ignore: str, dockerfile: str) -> None:
    check_context(ignore, dockerfile)
    for variant in (
        ignore.replace("**/.env", "", 1),
        ignore + "\n!apps/sites/.env\n",
        ignore.replace("!src/**", "!src/**/*.php"),
    ):
        try:
            check_context(variant, dockerfile)
        except ValueError:
            pass
        else:
            raise AssertionError("unsafe ignore policy mutation passed")
    try:
        check_context(ignore, dockerfile.replace(
            "COPY apps/sites/ ./apps/sites/", "COPY . ./"
        ))
    except ValueError:
        pass
    else:
        raise AssertionError("unrestricted copy mutation passed")
    good = fixture()
    inspect_rendered(good)
    for change in (
        lambda s: s["services"]["sites-lab-db"].update({"ports": ["5432:5432"]}),
        lambda s: s["networks"][NETWORK].update({"internal": False}),
        lambda s: s["services"]["sites-lab-tests"].update({"privileged": True}),
        lambda s: s["services"]["sites-lab-db"].update({"profiles": ["prod"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"cap_drop": []}),
        lambda s: s["services"]["sites-lab-tests"].update({"volumes": [{"type": "bind"}]}),
        lambda s: s["services"]["sites-lab-db"].update({"image": "postgres:latest"}),
    ):
        corrupted = fixture()
        change(corrupted)
        try:
            inspect_rendered(corrupted)
        except ValueError:
            pass
        else:
            raise AssertionError("unsafe Compose mutation passed")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--rendered-stdin", action="store_true")
    args = parser.parse_args()
    ignore = IGNORE.read_text(encoding="utf-8")
    dockerfile = DOCKERFILE.read_text(encoding="utf-8")
    self_test(ignore, dockerfile)
    if args.rendered_stdin:
        document = json.load(sys.stdin)
        inspect_rendered(document)
        print("PASS F1-74: Docker Compose renders an isolated opt-in lab (not started)")
    else:
        print("PASS F1-74: build context denied by default and lab fixture mutations rejected")
    print("No build, containers, network access, deployment or operational attestation")


if __name__ == "__main__":
    main()
