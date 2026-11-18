#!/usr/bin/env python3
"""Reserve the brief snapshot receiver CLI's five private paths in the disposable CI lab."""
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
    '/run/secrets/sites_brief_snapshot_receive_target',
    '/run/secrets/sites_brief_snapshot_receive_manifest',
    '/run/secrets/sites_brief_snapshot_receive_body',
    '/run/secrets/sites_brief_snapshot_receive_password',
    '/run/secrets/sites_brief_snapshot_receive_ca',
)


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
            or os.environ.get('SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI') != '1'):
        raise RuntimeError('disposable root brief snapshot receiver fixture required')
    operation, lab_arg, uid_arg, gid_arg = sys.argv[1:]
    lab = pathlib.Path(lab_arg)
    uid, gid = int(uid_arg), int(gid_arg)
    if (uid <= 0 or gid < 0 or lab.is_symlink() or lab.resolve() != lab
            or lab.parent != pathlib.Path('/tmp') or not lab.name.startswith('sites-release-registry-')):
        raise RuntimeError('owned temporary laboratory required')
    info = lab.lstat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid != uid
            or stat.S_IMODE(info.st_mode) != 0o700):
        raise RuntimeError('laboratory custody mismatch')
    # The existing package fixture owns this parent, if it created it. This
    # fixture neither creates nor removes any directory or pre-existing file.
    parent = pathlib.Path('/run/secrets')
    if (parent.is_symlink() or parent.resolve() != parent or not parent.is_dir()
            or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022):
        raise RuntimeError('safe existing secrets parent required')
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
    receipt = 'brief-snapshot-receiver-paths.json'
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
            if (not isinstance(entry, dict) or set(entry) != {'path', 'identity'}
                    or entry['path'] not in FILES or not isinstance(entry['identity'], dict)
                    or set(entry['identity']) != {'dev', 'ino', 'type', 'uid'}
                    or any(type(v) is not int or v < 0 for v in entry['identity'].values())
                    or entry['identity']['type'] != stat.S_IFREG
                    or entry['identity']['uid'] != uid):
                raise RuntimeError('invalid recorded ownership')
            paths.append(entry['path'])
        if len(paths) != len(set(paths)):
            raise RuntimeError('duplicate ownership receipt')
        # Validate the complete receipt before deleting even the first path.
        for entry in created:
            if os.path.lexists(entry['path']) and identity(entry['path']) != entry['identity']:
                raise RuntimeError('fixture path was replaced; preserve all paths')
        if operation == 'verify-mismatch':
            if set(paths) != set(FILES) or any(not os.path.lexists(p) for p in paths):
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
                        raise RuntimeError('identity mismatch did not preserve every path')
                    if any(identity(entry['path']) != entry['identity'] for entry in created):
                        raise RuntimeError('negative cleanup changed a fixture object')
                finally:
                    # The pathname may have changed during the subprocess;
                    # restore only the original file already held by this FD.
                    record.seek(0)
                    record.truncate()
                    record.write(saved)
                    record.flush()
                    os.fsync(record.fileno())
                current_lab()
                if _material.stamp(os.fstat(record.fileno())) != _material.stamp(
                        os.stat(receipt, dir_fd=lab_fd, follow_symlinks=False)):
                    raise RuntimeError('receipt replaced during negative test')
            print('BRIEF SNAPSHOT RECEIVER CLI PATHS NEGATIVE OK: inode mismatch refused before any removal')
            return
        current_lab()
        for entry in reversed(created):
            raw = entry['path']
            if not os.path.lexists(raw):
                continue
            if identity(raw) != entry['identity']:
                raise RuntimeError('fixture object changed during cleanup')
            os.unlink(raw)
        print('BRIEF SNAPSHOT RECEIVER CLI PATHS CLEANUP OK: only five fixture-owned file paths considered')
        return
    if operation != 'prepare' or exists_at(lab_fd, receipt):
        raise RuntimeError('fresh fixture preparation required')
    if any(os.path.lexists(path) for path in FILES):
        raise RuntimeError('fixed path already exists; refusing reuse')
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
        for raw, payload in zip(FILES, (b'{}\n', b'{}\n', b'{}\n', password, ca)):
            mode = 0o600 if raw in FILES[:3] else 0o400
            out = os.open(raw, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
            os.fchown(out, uid, gid)
            remember(raw)
            with os.fdopen(out, 'wb') as stream:
                stream.write(payload)
    current_lab()
    print('BRIEF SNAPSHOT RECEIVER CLI PATHS READY: private exact paths reserved with synthetic material')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('BRIEF SNAPSHOT RECEIVER CLI PATHS DENIED: preserve existing resources and private receipt', file=sys.stderr)
        sys.exit(1)
