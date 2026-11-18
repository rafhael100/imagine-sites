#!/usr/bin/env python3
"""Reserve exact CLI paths in a disposable GitHub runner; never reuse a path."""
import importlib.util
import json
import os
import pathlib
import stat
import subprocess
import sys

# Fixed repository module; no material is read during import.
sys.dont_write_bytecode = True
_material_spec = importlib.util.spec_from_file_location(
    'sites_fixture_private_material',
    pathlib.Path(__file__).resolve().parent.parent / 'release-registry' / 'private_material.py',
)
_material = importlib.util.module_from_spec(_material_spec)
_material_spec.loader.exec_module(_material)

FILES = (
    '/run/secrets/release_preparation',
    '/run/secrets/sites_release_reader',
    '/run/secrets/sites_release_ca',
)
LEAVES = ('/run/sites-release-input', '/var/lib/imagine-sites/release-candidates')
PARENTS = ('/run/secrets', '/var/lib/imagine-sites')
ALLOWED = set(FILES + LEAVES + PARENTS)


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
    if os.geteuid() != 0 or os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('SITES_RELEASE_PACKAGE_CI') != '1':
        raise RuntimeError('disposable root fixture required')
    operation, lab_arg, uid_arg, gid_arg = sys.argv[1:]
    lab = pathlib.Path(lab_arg)
    uid, gid = int(uid_arg), int(gid_arg)
    if (uid <= 0 or gid < 0 or lab.is_symlink() or lab.resolve() != lab
            or lab.parent != pathlib.Path('/tmp') or not lab.name.startswith('sites-release-registry-')):
        raise RuntimeError('owned temporary laboratory required')
    info = lab.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('laboratory custody mismatch')
    lab_fd = os.open(lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if _material.stamp(os.fstat(lab_fd)) != _material.stamp(info):
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
    receipt = 'cli-paths.json'
    if operation in ('cleanup', 'verify-mismatch'):
        if not exists_at(lab_fd, receipt):
            return
        saved, recorded = _material.read_private(lab_fd, receipt, uid, 1, 16384)
        current_lab()
        created = json.loads(saved)
        if not isinstance(created, list):
            raise RuntimeError('invalid ownership receipt')
        paths = []
        for entry in created:
            if (not isinstance(entry, dict) or set(entry) != {'path', 'identity'} or
                    entry['path'] not in ALLOWED or not isinstance(entry['identity'], dict) or
                    set(entry['identity']) != {'dev', 'ino', 'type', 'uid'} or
                    any(type(v) is not int or v < 0 for v in entry['identity'].values())):
                raise RuntimeError('invalid ownership receipt')
            expected_type = stat.S_IFREG if entry['path'] in FILES else stat.S_IFDIR
            expected_uid = uid if entry['path'] in FILES + LEAVES else 0
            if entry['identity']['type'] != expected_type or entry['identity']['uid'] != expected_uid:
                raise RuntimeError('unexpected recorded ownership or object type')
            paths.append(entry['path'])
        if len(paths) != len(set(paths)):
            raise RuntimeError('duplicate ownership receipt')
        # Check every existing object before deleting even the first file. An
        # unrelated replacement at a formerly owned path must be preserved.
        for entry in created:
            if os.path.lexists(entry['path']) and identity(entry['path']) != entry['identity']:
                raise RuntimeError('fixture object was replaced; cleanup refused')
        if operation == 'verify-mismatch':
            if not created or any(not os.path.lexists(p) for p in paths):
                raise RuntimeError('complete fresh fixture required')
            invalid = json.loads(saved)
            invalid[0]['identity']['ino'] += 1
            receipt_fd = os.open(receipt, os.O_RDWR | os.O_NOFOLLOW, dir_fd=lab_fd)
            with os.fdopen(receipt_fd, 'r+b') as record:
                if _material.stamp(os.fstat(record.fileno())) != _material.stamp(recorded):
                    raise RuntimeError('receipt replaced before negative test')
                current_lab()
                try:
                    record.seek(0)
                    record.truncate()
                    record.write(json.dumps(invalid).encode())
                    record.flush()
                    os.fsync(record.fileno())
                    refused = subprocess.run(
                        [sys.executable, __file__, 'cleanup', str(lab), str(uid), str(gid)],
                        capture_output=True, check=False,
                    )
                    if refused.returncode == 0 or any(not os.path.lexists(p) for p in paths):
                        raise RuntimeError('wrong identity did not preserve every resource')
                    for entry in created:
                        if identity(entry['path']) != entry['identity']:
                            raise RuntimeError('negative cleanup changed a fixture object')
                finally:
                    # Restore only the opened receipt, never a replacement at
                    # its pathname while the negative subprocess was running.
                    record.seek(0)
                    record.truncate()
                    record.write(saved)
                    record.flush()
                    os.fsync(record.fileno())
                current_lab()
                if _material.stamp(os.fstat(record.fileno())) != _material.stamp(
                        os.stat(receipt, dir_fd=lab_fd, follow_symlinks=False)):
                    raise RuntimeError('receipt replaced during negative test')
            print('PACKAGE CLI PATHS NEGATIVE OK: mismatched inode receipt refused before any removal')
            return
        current_lab()
        for entry in reversed(created):
            raw = entry['path']
            path = pathlib.Path(raw)
            if not os.path.lexists(raw):
                continue
            # Recheck immediately before removing. Never follow a changed link.
            if identity(raw) != entry['identity']:
                raise RuntimeError('fixture object changed during cleanup')
            if path.is_symlink() or path.is_file():
                path.unlink()
            elif path.is_dir() and raw in LEAVES:
                # Contents were created only inside this originally absent,
                # uid-owned directory. Symlinks are unlinked, never traversed.
                import shutil
                shutil.rmtree(path)
            elif path.is_dir():
                # Parent directories may have unrelated later additions: never
                # recursively remove them and never delete someone else's file.
                try:
                    path.rmdir()
                except OSError:
                    pass
        print('PACKAGE CLI PATHS CLEANUP OK: only fixture-owned resources removed')
        return
    if operation != 'prepare':
        raise RuntimeError('unknown fixture operation')
    if exists_at(lab_fd, receipt):
        raise RuntimeError('fixture already prepared')
    for raw in FILES + LEAVES:
        if os.path.lexists(raw):
            raise RuntimeError('fixed CLI path already exists; refusing reuse')
    for raw in PARENTS:
        path = pathlib.Path(raw)
        if path.is_symlink() or (path.exists() and (not path.is_dir() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022)):
            raise RuntimeError('unsafe existing parent')
    password, ca = _material.synthetic_material(lab_fd, uid)
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
            if _material.stamp(os.fstat(record.fileno())) != _material.stamp(
                    os.stat(receipt, dir_fd=lab_fd, follow_symlinks=False)):
                raise RuntimeError('receipt replaced during preparation')
        for raw in PARENTS:
            if not pathlib.Path(raw).exists():
                os.mkdir(raw, 0o755)
                os.chmod(raw, 0o755)
                remember(raw)
        for raw in LEAVES:
            os.mkdir(raw, 0o700)
            os.chown(raw, uid, gid)
            remember(raw)
        payloads = (b'{}\n', password, ca)
        for raw, payload in zip(FILES, payloads):
            mode = 0o600 if raw == FILES[0] else 0o400
            out = os.open(raw, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
            os.fchown(out, uid, gid)
            remember(raw)
            with os.fdopen(out, 'wb') as stream:
                stream.write(payload)
    current_lab()
    print('PACKAGE CLI PATHS READY: private exact paths reserved; no operational credentials')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('PACKAGE CLI PATHS DENIED: preserve pre-existing resources; private receipt retained', file=sys.stderr)
        sys.exit(1)
