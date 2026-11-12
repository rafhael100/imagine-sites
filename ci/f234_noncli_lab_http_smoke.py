#!/usr/bin/env python3
"""F1-234: exercise synthetic route denial in a REAL non-CLI HTTP process.

GitHub Actions + localhost ONLY. No VPS, external URL, customer data, tokens,
provider, publication, or web-facing listener. If Laravel can cache the
synthetic closures, verify that those cached routes remain 404 under the PHP
built-in HTTP SAPI even with the synthetic flags enabled.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
APP = ROOT / "apps" / "sites"
HOST = "127.0.0.1"
PORT = 18091
BASE = f"http://{HOST}:{PORT}"
ROUTES = APP / "bootstrap" / "cache"


def allowed_runner() -> None:
    if (
        os.getenv("GITHUB_ACTIONS") != "true"
        or os.getenv("APP_ENV") != "testing"
        or os.getenv("SITES_F234_LOCAL_HTTP_LAB") != "1"
        or not (APP / "artisan").is_file()
        or not (APP / "vendor" / "autoload.php").is_file()
    ):
        raise RuntimeError("F1-234 strictly requires disposable GitHub Actions Laravel CI")


def command(args: list[str], env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        args, cwd=APP, env=env, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=35, check=False,
    )


def status(path: str, method: str = "GET") -> int:
    request = Request(
        BASE + path, method=method,
        data=b"{}" if method == "POST" else None,
        headers={"Content-Type": "application/json"},
    )
    try:
        with urlopen(request, timeout=2) as response:
            return response.status
    except HTTPError as error:
        return error.code


def main() -> None:
    allowed_runner()
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing",
        "APP_DEBUG": "false",
        "SITES_SYNTHETIC_HTTP": "1",
        "SITES_SYNTHETIC_REVIEW_UI": "1",
        "SITES_SYNTHETIC_RICH_REVIEW": "1",
        "SITES_F222_SEO_COPY_REVIEW": "1",
        "LOG_CHANNEL": "stderr",
        "SESSION_DRIVER": "array",
        "CACHE_STORE": "array",
    })
    if list(ROUTES.glob("routes-*.php")):
        raise RuntimeError("F1-234 refuses to replace an existing route cache")

    server: subprocess.Popen[str] | None = None
    try:
        build = command(["php", "artisan", "route:cache"], env)
        has_cache = bool(list(ROUTES.glob("routes-*.php")))
        if build.returncode == 0:
            if not has_cache:
                raise RuntimeError("Route cache command succeeded without a cache")
            listing = command(["php", "artisan", "route:list", "--json"], env)
            if listing.returncode != 0:
                raise RuntimeError("Cannot inspect the synthetic cached routes")
            routes = json.loads(listing.stdout)
            paths = {str(item.get("uri", "")).lstrip("/") for item in routes}
            if "_lab/sites/v1/create-project" not in paths:
                raise RuntimeError("Expected lab route absent from compiled cache")
            print("PASS F1-234: laboratory routes actually present in a compiled CI-only cache")
        elif "closure" in build.stdout.lower() and not has_cache:
            # An uncacheable closure is a safe fail of the cache creation.
            print("PASS F1-234: Laravel refused caching the synthetic closure routes")
        else:
            raise RuntimeError("Unexpected failure building the synthetic Laravel route cache")

        # PHP's built-in server is a real HTTP SAPI: PHP_SAPI = cli-server.
        # It can never grant the lab's CLI-only permission, even with all
        # fictional flags left enabled or with a compiled route cache.
        server = subprocess.Popen(
            ["php", "-S", f"{HOST}:{PORT}", "-t", "public", "public/index.php"],
            cwd=APP, env=env, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, text=True,
        )
        ready = False
        for _ in range(40):
            if server.poll() is not None:
                raise RuntimeError("Loopback PHP test server exited unexpectedly")
            try:
                if status("/up") == 200:
                    ready = True
                    break
            except (URLError, TimeoutError):
                pass
            time.sleep(0.2)
        if not ready:
            raise RuntimeError("Loopback health endpoint did not become available")

        for path, method, expected in [
            ("/up", "GET", 200),
            ("/_lab/sites/v1/create-project", "POST", 404),
            ("/_lab/sites/v1/review", "GET", 404),
            ("/_lab/sites/v1/seo-copy-review?product=onepage&seo=custom&social=on", "GET", 404),
            ("/_lab/sites/v1/other-route", "GET", 404),
            ("/api/sites/publish", "POST", 404),
        ]:
            actual = status(path, method)
            if actual != expected:
                raise RuntimeError(
                    f"F1-234 non-CLI local HTTP route status mismatch: {method} {path}: {actual}"
                )
        print("PASS F1-234: non-CLI PHP HTTP denies lab paths; /up stays accessible")
        print("NOT an FPM security approval, live Portal IAM, customer release or public deploy")
    finally:
        if server is not None:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
        clear = command(["php", "artisan", "route:clear"], env)
        if clear.returncode != 0 or list(ROUTES.glob("routes-*.php")):
            raise RuntimeError("CI synthetic route cache cleanup failed")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # Do not include environment values, server logs, request content or
        # any Laravel stack trace in a public workflow log.
        print("FAIL F1-234: " + str(error).splitlines()[0][:180], file=sys.stderr)
        sys.exit(1)
