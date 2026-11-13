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
    # F1-344: a sandbox can still consume another stack's volume or
    # network if the Compose PROJECT, network name, or volume is overridden.
    # Reject externally named resources before Docker starts anything.
    if value.get("name") != "imagine-sites-lab":
        raise ValueError("unexpected Compose project namespace")
    volumes = value.get("volumes")
    if not isinstance(volumes,dict) or set(volumes)!={"sites-lab-data"}:
        raise ValueError("only the disposable PG lab volume is permitted")
    volume=volumes["sites-lab-data"]
    if (not isinstance(volume,dict)
            or volume.get("name") not in (
                None,"imagine-sites-lab_sites-lab-data")
            or volume.get("external") is True
            or volume.get("driver") not in (None,"local")
            or set(volume)-{"name","external","driver"}):
        raise ValueError("unexpected external or redirected lab volume")
    services = value.get("services")
    nets = value.get("networks")
    if not isinstance(services, dict) or set(services) != SERVICES:
        raise ValueError("unexpected service inventory")
    if not isinstance(nets, dict) or set(nets) != {NETWORK}:
        raise ValueError("unexpected network inventory")
    network=nets[NETWORK]
    # Docker Compose v2 normalizes a harmless default IPAM object even if
    # no IPAM is declared in the source. Accept only the empty/default
    # representation; custom networks, subnets and gateway allocation fail.
    ipam=network.get("ipam") if isinstance(network,dict) else None
    default_ipam=(
        ipam is None or
        (isinstance(ipam,dict)
         and set(ipam)<={"driver","config","options"}
         and ipam.get("driver") in (None,"default")
         and ipam.get("config") in (None,[],{})
         and ipam.get("options") in (None,{}))
    )
    if (not isinstance(network,dict)
            or network.get("internal") is not True
            or network.get("name") not in (
                None,"imagine-sites-lab_sites-lab-isolated")
            or network.get("external") is True
            or network.get("driver") not in (None,"bridge")
            or network.get("attachable",False) is not False
            or not default_ipam
            or set(network)-{"name","internal","driver","external",
                             "attachable","ipam"}):
        raise ValueError("lab network must remain an isolated private bridge")
    for name, service in services.items():
        if not isinstance(service, dict):
            raise ValueError("invalid service")
        if service.get("profiles") != ["lab"] or service.get("restart") != "no":
            raise ValueError("services must be opt-in and not restart")
        if service.get("networks") is None or set(service["networks"]) != {NETWORK}:
            raise ValueError("service network must be isolated")
        # F1-341: also reject host namespace, Docker-socket, privilege,
        # credential injection and process override escapes. Compose allows
        # these keys without an explicit port, so a ports-only guard misses
        # them. No effect on operational hosts; lab config is read only.
        forbidden = (
            "ports", "expose", "devices", "cap_add", "network_mode",
            "extra_hosts", "secrets", "configs", "pid", "ipc", "uts",
            "cgroup", "cgroup_parent", "userns_mode", "volumes_from",
            "env_file", "entrypoint", "command", "user", "group_add",
            "privileged", "security_opt_add", "sysctls", "init",
            "credential_spec", "device_cgroup_rules", "extends",
            "links", "network_mode", "isolation",
        )
        for key in forbidden:
            if key in service and service[key]:
                raise ValueError(f"{name} has forbidden host/process override: {key}")
        if service.get("security_opt") != ["no-new-privileges:true"]:
            raise ValueError("lab security options must match the exact approved profile")
        if service.get("environment", {}).get("APP_ENV") == "production":
            raise ValueError("production env is forbidden")
    db, test = services["sites-lab-db"], services["sites-lab-tests"]
    if db.get("image") != "postgres:18":
        raise ValueError("unexpected PostgreSQL major version")
    # F1-343: Compose healthchecks execute code INSIDE containers. The
    # allowed PostgreSQL readiness command is exact and side-effect-free.
    # A changed probe could execute arbitrary shell or conceal DB failure.
    health=db.get("healthcheck")
    if (not isinstance(health,dict)
            or set(health)!={"test","interval","timeout","retries"}
            or health["test"]!=["CMD-SHELL","pg_isready -U postgres -d sites_lab"]
            or health["interval"] not in ("5s", 5_000_000_000)
            or health["timeout"] not in ("3s", 3_000_000_000)
            or type(health["retries"]) is not int or health["retries"]!=12):
        raise ValueError("unexpected PostgreSQL healthcheck command or timing")
    if test.get("healthcheck"):
        raise ValueError("test container must not execute a healthcheck")
    if db.get("depends_on"):
        raise ValueError("DB container must not depend on another service")
    dependency=test.get("depends_on")
    if (not isinstance(dependency,dict)
            or set(dependency)!={"sites-lab-db"}
            or not isinstance(dependency["sites-lab-db"],dict)):
        raise ValueError("test may depend only on private lab PostgreSQL")
    dep=dependency["sites-lab-db"]
    if (dep.get("condition")!="service_healthy"
            or dep.get("required",True) is not True
            or dep.get("restart",False) is not False
            or set(dep)-{"condition","required","restart"}):
        raise ValueError("unexpected PostgreSQL dependency condition")
    if test.get("cap_drop") != ["ALL"]:
        raise ValueError("test process must drop all capabilities")
    if test.get("build", {}).get("dockerfile") != "ops/staging/Dockerfile":
        raise ValueError("unexpected Dockerfile")
    test_volumes = test.get("volumes") or []
    if test_volumes:
        raise ValueError("test container should have no attached filesystem")
    db_volumes = db.get("volumes") or []
    if len(db_volumes) != 2 or (
        db_volumes[0].get("type") != "volume"
        or db_volumes[0].get("source") != "sites-lab-data"
        or db_volumes[0].get("target") != "/var/lib/postgresql"
    ) or (
        db_volumes[1].get("type") != "bind"
        or not isinstance(db_volumes[1].get("source"), str)
        or not db_volumes[1]["source"].endswith(
            "/ops/staging/postgres-init/01-create-lab-role.sh"
        )
        or db_volumes[1].get("target") != "/docker-entrypoint-initdb.d/01-create-lab-role.sh"
        or db_volumes[1].get("read_only") is not True
    ):
        raise ValueError("DB must have one volume and one exact read-only role-init fixture")
    admin_env = db.get("environment", {})
    app_env = test.get("environment", {})
    # F1-342: even with an isolated network, additional PostgreSQL init
    # variables (especially POSTGRES_HOST_AUTH_METHOD=trust) can disable
    # authentication. Require exact keys rather than accepting harmless-
    # looking extras; similarly pin the application's local DB endpoint.
    approved_db_keys = {
        "POSTGRES_DB", "POSTGRES_USER", "POSTGRES_PASSWORD",
        "SITES_LAB_DB_PASSWORD",
    }
    approved_app_keys = {
        "APP_ENV", "APP_DEBUG", "GITHUB_ACTIONS", "DB_CONNECTION",
        "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD",
    }
    if (not isinstance(admin_env, dict) or not isinstance(app_env, dict)
            or set(admin_env) != approved_db_keys
            or set(app_env) != approved_app_keys):
        raise ValueError("only the exact synthetic DB/test environment is allowed")
    if (app_env.get("APP_ENV") != "testing"
            or app_env.get("APP_DEBUG") != "false"
            or app_env.get("GITHUB_ACTIONS") not in ("true", "false")
            or app_env.get("DB_CONNECTION") != "pgsql"
            or app_env.get("DB_HOST") != "sites-lab-db"
            or app_env.get("DB_PORT") != "5432"):
        raise ValueError("untrusted test environment or external DB endpoint")
    if (admin_env.get("POSTGRES_USER") != "postgres"
        or admin_env.get("POSTGRES_DB") != "sites_lab"
        or app_env.get("DB_USERNAME") != "siteslab"
        or app_env.get("DB_DATABASE") != "sites_lab"
        or "SITES_LAB_DB_PASSWORD" not in admin_env
        or not isinstance(admin_env.get("POSTGRES_PASSWORD"), str)
        or not isinstance(admin_env.get("SITES_LAB_DB_PASSWORD"), str)
        or admin_env.get("POSTGRES_PASSWORD") == admin_env.get("SITES_LAB_DB_PASSWORD")
        or app_env.get("DB_PASSWORD") != admin_env.get("SITES_LAB_DB_PASSWORD")):
        raise ValueError("app and admin PG credentials must be distinct with restricted app role")
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
        "healthcheck": {
            "test": ["CMD-SHELL","pg_isready -U postgres -d sites_lab"],
            "interval": "5s", "timeout": "3s", "retries": 12,
        },
        "environment": {
            "POSTGRES_USER": "postgres", "POSTGRES_DB": "sites_lab",
            "POSTGRES_PASSWORD": "SYNTHETIC-ADMIN-ONLY",
            "SITES_LAB_DB_PASSWORD": "SYNTHETIC-APP-ONLY",
        },
        "volumes": [
            {"type": "volume", "source": "sites-lab-data",
             "target": "/var/lib/postgresql"},
            {"type": "bind",
             "source": "/workspace/ops/staging/postgres-init/01-create-lab-role.sh",
             "target": "/docker-entrypoint-initdb.d/01-create-lab-role.sh",
             "read_only": True},
        ],
    }
    test = {
        **base, "cap_drop": ["ALL"],
        "depends_on": {
            "sites-lab-db": {"condition": "service_healthy", "required": True}
        },
        "build": {"dockerfile": "ops/staging/Dockerfile"},
        "environment": {
            "DB_PASSWORD": "SYNTHETIC-APP-ONLY",
            "DB_USERNAME": "siteslab", "DB_DATABASE": "sites_lab",
            "APP_ENV": "testing", "APP_DEBUG": "false",
            "GITHUB_ACTIONS": "true", "DB_CONNECTION": "pgsql",
            "DB_HOST": "sites-lab-db", "DB_PORT": "5432",
        },
    }
    return {
        "name": "imagine-sites-lab",
        "services": {"sites-lab-db": db, "sites-lab-tests": test},
        "networks": {NETWORK: {
            "name": "imagine-sites-lab_sites-lab-isolated",
            "internal": True,
        }},
        "volumes": {"sites-lab-data": {
            "name": "imagine-sites-lab_sites-lab-data",
        }},
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
        lambda s: s.update({"name": "other-project"}),
        lambda s: s["networks"][NETWORK].update({"name": "other-stack_network"}),
        lambda s: s["networks"][NETWORK].update({"driver": "macvlan"}),
        lambda s: s["networks"][NETWORK].update({"ipam": {"driver":"default","config":[{"subnet":"10.0.0.0/8"}]}}),
        lambda s: s["networks"][NETWORK].update({"ipam": {"driver":"host"}}),
        lambda s: s["networks"][NETWORK].update({"attachable": True}),
        lambda s: s["networks"][NETWORK].update({"external": True}),
        lambda s: s["volumes"]["sites-lab-data"].update({"name": "production_db"}),
        lambda s: s["volumes"]["sites-lab-data"].update({"driver_opts": {"type": "none", "device": "/home"}}),
        lambda s: s["volumes"]["sites-lab-data"].update({"external": True}),
        lambda s: s["volumes"].update({"other": {"name": "unexpected"}}),
        lambda s: s["services"]["sites-lab-tests"].update({"privileged": True}),
        lambda s: s["services"]["sites-lab-db"]["healthcheck"].update({"test": ["CMD-SHELL", "sh -c 'touch /tmp/unexpected'"]}),
        lambda s: s["services"]["sites-lab-db"]["healthcheck"].update({"disable": True}),
        lambda s: s["services"]["sites-lab-db"]["healthcheck"].update({"interval": "1ms"}),
        lambda s: s["services"]["sites-lab-tests"].update({"healthcheck": {"test": ["CMD", "sh", "-c", "id"]}}),
        lambda s: s["services"]["sites-lab-tests"]["depends_on"]["sites-lab-db"].update({"condition": "service_started"}),
        lambda s: s["services"]["sites-lab-tests"]["depends_on"]["sites-lab-db"].update({"required": False}),
        lambda s: s["services"]["sites-lab-tests"]["depends_on"].update({"host-db": {"condition": "service_healthy"}}),
        lambda s: s["services"]["sites-lab-db"].update({"depends_on": {"sites-lab-tests": {"condition": "service_started"}}}),
        lambda s: s["services"]["sites-lab-db"].update({"profiles": ["prod"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"pid": "host"}),
        lambda s: s["services"]["sites-lab-tests"].update({"ipc": "host"}),
        lambda s: s["services"]["sites-lab-tests"].update({"user": "0:0"}),
        lambda s: s["services"]["sites-lab-tests"].update({"entrypoint": ["/bin/sh"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"command": ["sh", "-c", "id"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"env_file": ["/tmp/not-approved.env"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"volumes_from": ["untrusted"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"group_add": ["docker"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"sysctls": {"kernel.shmmax": 123}}),
        lambda s: s["services"]["sites-lab-tests"].update({"security_opt": ["no-new-privileges:true", "seccomp=unconfined"]}),
        lambda s: s["services"]["sites-lab-tests"].update({"cap_drop": []}),
        lambda s: s["services"]["sites-lab-tests"].update({"volumes": [{"type": "bind"}]}),
        lambda s: s["services"]["sites-lab-db"].update({"image": "postgres:latest"}),
        lambda s: s["services"]["sites-lab-db"]["environment"].update({"POSTGRES_USER": "siteslab"}),
        lambda s: s["services"]["sites-lab-db"]["environment"].update({"POSTGRES_PASSWORD": "SYNTHETIC-APP-ONLY"}),
        lambda s: s["services"]["sites-lab-db"]["volumes"][1].update({"read_only": False}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"DB_USERNAME": "postgres"}),
        lambda s: s["services"]["sites-lab-db"]["environment"].update({"POSTGRES_HOST_AUTH_METHOD": "trust"}),
        lambda s: s["services"]["sites-lab-db"]["environment"].update({"POSTGRES_INITDB_ARGS": "--auth-host=trust"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"DB_HOST": "host.docker.internal"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"DB_CONNECTION": "sqlite"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"APP_DEBUG": "true"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"APP_ENV": "production"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"GITHUB_ACTIONS": "unexpected"}),
        lambda s: s["services"]["sites-lab-tests"]["environment"].update({"SITES_SHARED_SECRET": "injected"}),
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
