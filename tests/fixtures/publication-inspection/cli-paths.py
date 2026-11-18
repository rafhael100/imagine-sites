#!/usr/bin/env python3
"""Reserve only the inspection CLI's three private paths in a disposable CI lab."""
import json
import os
import pathlib
import stat
import subprocess
import sys

FILES = (
    '/run/secrets/publication_inspection',
    '/run/secrets/sites_publication_reader',
    '/run/secrets/sites_publication_ca',
)


def identity(path):
    value = os.lstat(path)
    return {'dev': value.st_dev, 'ino': value.st_ino,
            'type': stat.S_IFMT(value.st_mode), 'uid': value.st_uid}


def main():
    if (os.geteuid() != 0 or os.environ.get('GITHUB_ACTIONS') != 'true'
            or os.environ.get('SITES_RELEASE_REGISTRY_CI') != '1'
            or os.environ.get('SITES_PUBLICATION_JOURNAL_CI') != '1'
            or os.environ.get('SITES_PUBLICATION_INSPECTION_CI') != '1'):
        raise RuntimeError('disposable root inspection fixture required')
    operation, lab_arg, uid_arg, gid_arg = sys.argv[1:]
    lab = pathlib.Path(lab_arg)
    uid, gid = int(uid_arg), int(gid_arg)
    if (uid <= 0 or gid < 0 or lab.is_symlink() or lab.resolve() != lab
            or not str(lab).startswith('/tmp/sites-release-registry-')):
        raise RuntimeError('owned temporary laboratory required')
    info = lab.stat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid != uid
            or stat.S_IMODE(info.st_mode) != 0o700):
        raise RuntimeError('laboratory custody mismatch')
    # The existing package fixture owns this parent, if it created it. This
    # fixture neither creates nor removes any directory or pre-existing file.
    parent = pathlib.Path('/run/secrets')
    if (parent.is_symlink() or parent.resolve() != parent or not parent.is_dir()
            or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022):
        raise RuntimeError('safe existing secrets parent required')
    receipt = lab / 'publication-inspection-paths.json'
    if operation in ('cleanup', 'verify-mismatch'):
        if not os.path.lexists(receipt):
            return
        recorded = receipt.lstat()
        if (not stat.S_ISREG(recorded.st_mode) or recorded.st_nlink != 1
                or recorded.st_uid != uid or stat.S_IMODE(recorded.st_mode) != 0o600):
            raise RuntimeError('private regular receipt required')
        created = json.loads(receipt.read_bytes())
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
            saved = receipt.read_bytes()
            invalid = json.loads(saved)
            invalid[0]['identity']['ino'] += 1
            try:
                receipt.write_text(json.dumps(invalid))
                refused = subprocess.run(
                    [sys.executable, __file__, 'cleanup', lab_arg, uid_arg, gid_arg],
                    capture_output=True, check=False,
                )
                if refused.returncode == 0 or any(not os.path.lexists(p) for p in paths):
                    raise RuntimeError('identity mismatch did not preserve every path')
                if any(identity(entry['path']) != entry['identity'] for entry in created):
                    raise RuntimeError('negative cleanup changed a fixture object')
            finally:
                receipt.write_bytes(saved)
            print('INSPECTION CLI PATHS NEGATIVE OK: inode mismatch refused before any removal')
            return
        for entry in reversed(created):
            raw = entry['path']
            if not os.path.lexists(raw):
                continue
            if identity(raw) != entry['identity']:
                raise RuntimeError('fixture object changed during cleanup')
            os.unlink(raw)
        print('INSPECTION CLI PATHS CLEANUP OK: only three fixture-owned file paths considered')
        return
    if operation != 'prepare' or os.path.lexists(receipt):
        raise RuntimeError('fresh fixture preparation required')
    if any(os.path.lexists(path) for path in FILES):
        raise RuntimeError('fixed path already exists; refusing reuse')
    password = (lab / 'password').read_bytes()
    ca = (lab / 'tls' / 'ca.pem').read_bytes()
    if (not 32 <= len(password) <= 256 or any(c in password for c in (b'\n', b'\r', b'\0'))
            or not 100 <= len(ca) <= 65536):
        raise RuntimeError('synthetic custody material invalid')
    created = []
    fd = os.open(receipt, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    os.fchown(fd, uid, gid)
    with os.fdopen(fd, 'w') as record:
        def remember(path):
            created.append({'path': path, 'identity': identity(path)})
            record.seek(0)
            record.truncate()
            json.dump(created, record)
            record.flush()
            os.fsync(record.fileno())
        for raw, payload in zip(FILES, (b'{}\n', password, ca)):
            mode = 0o600 if raw == FILES[0] else 0o400
            out = os.open(raw, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
            os.fchown(out, uid, gid)
            remember(raw)
            with os.fdopen(out, 'wb') as stream:
                stream.write(payload)
    print('INSPECTION CLI PATHS READY: private exact paths reserved with synthetic material')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('INSPECTION CLI PATHS DENIED: preserve existing resources and private receipt', file=sys.stderr)
        sys.exit(1)
