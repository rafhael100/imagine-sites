#!/usr/bin/env python3
"""Reserve request CLI inputs and one new private receipt directory in disposable CI."""
import importlib.util
import json
import os
import pathlib
import stat
import subprocess
import sys

sys.dont_write_bytecode = True
material_spec = importlib.util.spec_from_file_location(
    'sites_fixture_private_material', pathlib.Path(__file__).resolve().parent.parent / 'release-registry' / 'private_material.py')
material = importlib.util.module_from_spec(material_spec)
material_spec.loader.exec_module(material)
stamp, read_private, synthetic_material = material.stamp, material.read_private, material.synthetic_material

FILES = (
    '/run/secrets/publication_request',
    '/run/secrets/sites_publication_request_writer',
    '/run/secrets/sites_publication_request_ca',
)
DIRECTORY = '/var/lib/imagine-sites/publication-requests'
ALLOWED = set(FILES + (DIRECTORY,))
# Only the declared fixture receipts and its two failure probes may be removed.
# A failed apply may have left its own empty reservation, so zero bytes is valid.
OUTPUT_NAMES = {
    'ci-request-main.json', 'ci-request-requested.json', 'ci-request-dispatched.json',
    'ci-request-acknowledged.json', 'ci-request-inconclusive.json', 'ci-request-epoch.json',
    'ci-request-historical.json', 'ci-request-postcommit.json', 'ci-request-postcommit-reserved.json',
    'ci-request-recovered.json', 'ci-request-existing.json', 'ci-request-conflict-release.json',
    'ci-request-conflict-acceptance.json', 'ci-request-cross-company.json',
    'ci-request-inactive.json', 'ci-request-revoked.json',
}


def identity(path):
    value = os.lstat(path)
    return {'dev': value.st_dev, 'ino': value.st_ino,
            'type': stat.S_IFMT(value.st_mode), 'uid': value.st_uid}


def exists_at(directory_fd, name):
    try:
        os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        return True
    except FileNotFoundError:
        return False


def main():
    if (os.geteuid() != 0 or os.environ.get('GITHUB_ACTIONS') != 'true'
            or os.environ.get('SITES_RELEASE_REGISTRY_CI') != '1'
            or os.environ.get('SITES_PUBLICATION_REQUEST_CI') != '1'):
        raise RuntimeError('disposable root request fixture required')
    operation, lab_arg, uid_arg, gid_arg = sys.argv[1:]
    lab = pathlib.Path(lab_arg)
    uid, gid = int(uid_arg), int(gid_arg)
    if (uid <= 0 or gid < 0 or lab.is_symlink() or lab.resolve() != lab
            or lab.parent != pathlib.Path('/tmp') or not lab.name.startswith('sites-release-registry-')):
        raise RuntimeError('owned temporary laboratory required')
    info = lab.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('laboratory custody mismatch')
    # Existing parents belong to the package fixture or the runner. This helper
    # neither adopts nor removes them, including /var/lib/imagine-sites.
    for raw in ('/run', '/run/secrets', '/var', '/var/lib', '/var/lib/imagine-sites'):
        parent = pathlib.Path(raw)
        if (parent.is_symlink() or parent.resolve() != parent or not parent.is_dir()
                or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022):
            raise RuntimeError('safe existing parent required')
    lab_fd = os.open(lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if stamp(os.fstat(lab_fd)) != stamp(info):
            raise RuntimeError('laboratory replaced before open')
        run(operation, lab, uid, gid, lab_fd)
    finally:
        os.close(lab_fd)


def run(operation, lab, uid, gid, lab_fd):
    def current_lab():
        current = lab.lstat()
        opened = os.fstat(lab_fd)
        if (current.st_dev != opened.st_dev or current.st_ino != opened.st_ino
                or not stat.S_ISDIR(current.st_mode) or current.st_uid != uid
                or stat.S_IMODE(current.st_mode) != 0o700):
            raise RuntimeError('laboratory path changed')
    current_lab()
    receipt = 'publication-request-paths.json'
    if operation in ('cleanup', 'verify-mismatch'):
        if not exists_at(lab_fd, receipt):
            return
        saved, recorded = read_private(lab_fd, receipt, uid, 1, 16384)
        created = json.loads(saved)
        if not isinstance(created, list):
            raise RuntimeError('invalid ownership receipt')
        paths = []
        for entry in created:
            if (not isinstance(entry, dict) or set(entry) != {'path', 'identity'}
                    or entry['path'] not in ALLOWED or not isinstance(entry['identity'], dict)
                    or set(entry['identity']) != {'dev', 'ino', 'type', 'uid'}
                    or any(type(value) is not int or value < 0 for value in entry['identity'].values())
                    or entry['identity']['type'] != (stat.S_IFDIR if entry['path'] == DIRECTORY else stat.S_IFREG)
                    or entry['identity']['uid'] != uid):
                raise RuntimeError('invalid recorded ownership')
            paths.append(entry['path'])
        if len(paths) != len(set(paths)):
            raise RuntimeError('duplicate ownership receipt')
        # Verify the whole ownership receipt before opening or removing a child.
        for entry in created:
            if os.path.lexists(entry['path']) and identity(entry['path']) != entry['identity']:
                raise RuntimeError('fixture path was replaced; preserve every path')
        if operation == 'verify-mismatch':
            if set(paths) != ALLOWED or any(not os.path.lexists(path) for path in paths):
                raise RuntimeError('complete fresh fixture required')
            invalid = json.loads(saved)
            invalid[0]['identity']['ino'] += 1
            receipt_fd = os.open(receipt, os.O_RDWR | os.O_NOFOLLOW, dir_fd=lab_fd)
            with os.fdopen(receipt_fd, 'r+b') as record:
                if stamp(os.fstat(record.fileno())) != stamp(recorded):
                    raise RuntimeError('receipt replaced before negative test')
                try:
                    current_lab()
                    record.seek(0)
                    record.truncate()
                    record.write(json.dumps(invalid).encode())
                    record.flush()
                    os.fsync(record.fileno())
                    refused = subprocess.run(
                        [sys.executable, __file__, 'cleanup', str(lab), str(uid), str(gid)],
                        capture_output=True, check=False,
                    )
                    if refused.returncode == 0 or any(not os.path.lexists(path) for path in paths):
                        raise RuntimeError('identity mismatch did not preserve every path')
                    if any(identity(entry['path']) != entry['identity'] for entry in created):
                        raise RuntimeError('negative cleanup changed a fixture object')
                finally:
                    record.seek(0)
                    record.truncate()
                    record.write(saved)
                    record.flush()
                    os.fsync(record.fileno())
                current_lab()
                if stamp(os.fstat(record.fileno())) != stamp(os.stat(receipt, dir_fd=lab_fd, follow_symlinks=False)):
                    raise RuntimeError('receipt replaced during negative test')
            print('REQUEST CLI PATHS NEGATIVE OK: inode mismatch refused before any removal')
            return

        output_fd = None
        output_files = {}
        try:
            if DIRECTORY in paths and os.path.lexists(DIRECTORY):
                info = os.lstat(DIRECTORY)
                if stat.S_IMODE(info.st_mode) != 0o700:
                    raise RuntimeError('private output directory required')
                output_fd = os.open(DIRECTORY, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                if stamp(os.fstat(output_fd)) != stamp(info):
                    raise RuntimeError('output directory replaced before inventory')
                names = set(os.listdir(output_fd))
                if not names <= OUTPUT_NAMES:
                    raise RuntimeError('unexpected output; preserve the whole fixture')
                for name in sorted(names):
                    _, output_files[name] = read_private(output_fd, name, uid, 0, 4096)
                if set(os.listdir(output_fd)) != names or identity(DIRECTORY) != next(
                        entry['identity'] for entry in created if entry['path'] == DIRECTORY):
                    raise RuntimeError('output inventory changed')
            current_lab()
            # All recorded paths and every output are checked before the first
            # unlink. No recursive removal and no following a symlink child.
            for entry in created:
                if os.path.lexists(entry['path']) and identity(entry['path']) != entry['identity']:
                    raise RuntimeError('fixture identity changed before removal')
            for name, info in output_files.items():
                if stamp(os.stat(name, dir_fd=output_fd, follow_symlinks=False)) != stamp(info):
                    raise RuntimeError('output changed before removal')
            for name, info in output_files.items():
                if stamp(os.stat(name, dir_fd=output_fd, follow_symlinks=False)) != stamp(info):
                    raise RuntimeError('output changed during cleanup')
                os.unlink(name, dir_fd=output_fd)
            for entry in reversed(created):
                raw = entry['path']
                if not os.path.lexists(raw):
                    continue
                if identity(raw) != entry['identity']:
                    raise RuntimeError('fixture object changed during cleanup')
                if raw == DIRECTORY:
                    os.rmdir(raw)
                else:
                    os.unlink(raw)
        finally:
            if output_fd is not None:
                os.close(output_fd)
        print('REQUEST CLI PATHS CLEANUP OK: only recorded inputs and declared private fixture receipts removed')
        return

    if operation != 'prepare' or exists_at(lab_fd, receipt):
        raise RuntimeError('fresh fixture preparation required')
    if any(os.path.lexists(path) for path in ALLOWED):
        raise RuntimeError('fixed path already exists; refusing reuse')
    password, ca = synthetic_material(lab_fd, uid)
    current_lab()
    created = []
    fd = os.open(receipt, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=lab_fd)
    os.fchown(fd, uid, gid)
    with os.fdopen(fd, 'w') as record:
        def remember(path):
            created.append({'path': path, 'identity': identity(path)})
            record.seek(0)
            record.truncate()
            json.dump(created, record)
            record.flush()
            os.fsync(record.fileno())
            current_lab()
            if stamp(os.fstat(record.fileno())) != stamp(os.stat(receipt, dir_fd=lab_fd, follow_symlinks=False)):
                raise RuntimeError('receipt replaced during preparation')
        os.mkdir(DIRECTORY, 0o700)
        os.chown(DIRECTORY, uid, gid)
        remember(DIRECTORY)
        for raw, payload in zip(FILES, (b'{}\n', password, ca)):
            mode = 0o600 if raw == FILES[0] else 0o400
            out = os.open(raw, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
            os.fchown(out, uid, gid)
            remember(raw)
            with os.fdopen(out, 'wb') as stream:
                stream.write(payload)
    print('REQUEST CLI PATHS READY: three private inputs and one exclusive empty receipt directory')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('REQUEST CLI PATHS DENIED: preserve existing resources and private receipt', file=sys.stderr)
        sys.exit(1)
