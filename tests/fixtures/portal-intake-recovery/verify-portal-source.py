#!/usr/bin/env python3
"""Verify the two exact, unchanged source trees before the paired CI fixture."""
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys

sys.dont_write_bytecode = True
PORTAL_COMMIT = 'd1aaab523848baf80325a023f3ebbf4998a3607f'
PORTAL_REPOSITORY = 'rafhael100/imagine-portal'


def require(condition):
    if not condition:
        raise RuntimeError('Source identity mismatch')


def git(root, *arguments):
    result = subprocess.run(['git', '-C', str(root), *arguments], capture_output=True,
                            timeout=15, check=False)
    require(result.returncode == 0 and len(result.stdout) <= 1048576 and len(result.stderr) <= 1048576)
    return result.stdout.decode('utf-8').strip()


def repository(raw):
    require(isinstance(raw, str) and raw != '' and '\x00' not in raw)
    path = pathlib.Path(raw)
    require(path.is_absolute() and not path.is_symlink() and path.resolve(strict=True) == path)
    require(path.is_dir() and git(path, 'rev-parse', '--show-toplevel') == str(path))
    return path


def source_file(root, relative):
    require(isinstance(relative, str) and re.fullmatch(r'[A-Za-z0-9_.\-/]+', relative) is not None)
    parts = pathlib.PurePosixPath(relative)
    require(not parts.is_absolute() and '..' not in parts.parts and '.' not in parts.parts)
    path = root.joinpath(*parts.parts)
    require(path.is_file() and not path.is_symlink() and path.resolve(strict=True) == path)
    require(path.stat().st_size <= 4194304)
    return path.read_bytes()


def verify_sources():
    require(len(sys.argv) == 1 and os.environ.get('GITHUB_ACTIONS') == 'true'
            and os.environ.get('APP_ENV') == 'testing'
            and os.environ.get('SITES_PORTAL_INTAKE_RECOVERY_CI') == '1')
    sites = repository(os.environ.get('SITES_PORTAL_RECOVERY_SOURCE'))
    portal = repository(os.environ.get('SITES_PORTAL_RECOVERY_PORTAL_SOURCE'))
    require(sites == pathlib.Path(__file__).resolve().parents[3] and sites != portal)
    sites_head = os.environ.get('SITES_PORTAL_RECOVERY_HEAD', '')
    require(re.fullmatch(r'[0-9a-f]{40}', sites_head) is not None)
    require(git(sites, 'rev-parse', 'HEAD') == sites_head and git(portal, 'rev-parse', 'HEAD') == PORTAL_COMMIT)
    for root, app in ((sites, 'sites'), (portal, 'portal')):
        require(git(root, 'diff', '--exit-code', 'HEAD', '--') == '')
        for name in ('.env', '.env.testing', 'bootstrap/cache/config.php'):
            path = root / 'apps' / app / name
            require(not os.path.lexists(path))
    raw = source_file(sites, 'tests/fixtures/portal-intake-recovery/portal-source-pins.json')
    manifest = json.loads(raw)
    require(isinstance(manifest, dict) and set(manifest) == {
        'schema', 'repository', 'commit', 'files', 'sitesContractSha256'})
    require(manifest['schema'] == 'SITES_PORTAL_INTAKE_RECOVERY_SOURCE_V1'
            and manifest['repository'] == PORTAL_REPOSITORY and manifest['commit'] == PORTAL_COMMIT)
    files = manifest['files']
    require(isinstance(files, list) and len(files) == 31)
    seen = set()
    for entry in files:
        require(isinstance(entry, dict) and set(entry) == {'path', 'gitBlobSha'})
        name, expected = entry['path'], entry['gitBlobSha']
        require(isinstance(name, str) and name not in seen and isinstance(expected, str)
                and re.fullmatch(r'[0-9a-f]{40}', expected) is not None)
        seen.add(name)
        content = source_file(portal, name)
        actual = hashlib.sha1(b'blob ' + str(len(content)).encode('ascii') + b'\x00' + content).hexdigest()
        require(actual == expected and git(portal, 'rev-parse', 'HEAD:' + name) == expected)
    expected_sites = {
        'apps/sites/app/Integration/PortalProjectIntakeRecorder.php',
        'src/Integration/SiteProjectCommand.php',
        'contracts/portal-sites/001-project-intake.sql',
        'contracts/portal-sites/v1/project-intake-receipt.schema.json',
    }
    pins = manifest['sitesContractSha256']
    require(isinstance(pins, dict) and set(pins) == expected_sites)
    for name, expected in pins.items():
        require(isinstance(expected, str) and re.fullmatch(r'[0-9a-f]{64}', expected) is not None)
        require(hashlib.sha256(source_file(sites, name)).hexdigest() == expected)
    print('SITES PORTAL RECOVERY SOURCE PINS PASS: 31 Portal blobs, four native Sites contracts and both exact unchanged checkouts')


if __name__ == '__main__':
    try:
        verify_sources()
    except Exception:
        print('SITES PORTAL RECOVERY SOURCE PINS DENIED', file=sys.stderr)
        sys.exit(1)
