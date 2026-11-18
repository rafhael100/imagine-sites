"""Read synthetic CI credentials through pinned, private file descriptors.

Importing this module performs no I/O. It never reserves or removes CLI paths.
"""
import os
import pathlib
import stat


def stamp(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def read_private(directory_fd, name, uid, minimum, maximum):
    before = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid != uid
            or stat.S_IMODE(before.st_mode) != 0o600 or not minimum <= before.st_size <= maximum):
        raise RuntimeError('private source custody mismatch')
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory_fd)
    with os.fdopen(fd, 'rb') as stream:
        if stamp(os.fstat(stream.fileno())) != stamp(before):
            raise RuntimeError('private source replaced before read')
        payload = stream.read(maximum + 1)
        after = os.fstat(stream.fileno())
        current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        if len(payload) != before.st_size or stamp(before) != stamp(after) or stamp(after) != stamp(current):
            raise RuntimeError('private source changed during read')
        return payload, before


def synthetic_material(lab_fd, uid):
    tls = os.stat('tls', dir_fd=lab_fd, follow_symlinks=False)
    if not stat.S_ISDIR(tls.st_mode) or tls.st_uid != uid or stat.S_IMODE(tls.st_mode) != 0o700:
        raise RuntimeError('private TLS directory required')
    tls_fd = os.open('tls', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=lab_fd)
    try:
        if stamp(os.fstat(tls_fd)) != stamp(tls):
            raise RuntimeError('TLS directory replaced before read')
        password, _ = read_private(lab_fd, 'password', uid, 64, 64)
        ca, _ = read_private(tls_fd, 'ca.pem', uid, 100, 65536)
        if (stamp(os.fstat(tls_fd)) != stamp(tls)
                or stamp(os.stat('tls', dir_fd=lab_fd, follow_symlinks=False)) != stamp(tls)):
            raise RuntimeError('TLS directory changed during read')
    finally:
        os.close(tls_fd)
    if (any(byte not in b'0123456789abcdef' for byte in password)
            or b'-----BEGIN CERTIFICATE-----' not in ca or b'PRIVATE KEY' in ca):
        raise RuntimeError('synthetic custody material invalid')
    return password, ca


def load_material(lab, uid):
    """Pin the original disposable laboratory for older fixture callers."""
    lab = pathlib.Path(lab)
    if (uid <= 0 or lab.parent != pathlib.Path('/tmp')
            or not lab.name.startswith('sites-release-registry-') or lab.resolve() != lab):
        raise RuntimeError('owned temporary laboratory required')
    before = lab.lstat()
    if (not stat.S_ISDIR(before.st_mode) or before.st_uid != uid
            or stat.S_IMODE(before.st_mode) != 0o700):
        raise RuntimeError('laboratory custody mismatch')
    lab_fd = os.open(lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if stamp(os.fstat(lab_fd)) != stamp(before):
            raise RuntimeError('laboratory replaced before open')
        material = synthetic_material(lab_fd, uid)
        if stamp(os.fstat(lab_fd)) != stamp(before) or stamp(lab.lstat()) != stamp(before):
            raise RuntimeError('laboratory changed during read')
        return material
    finally:
        os.close(lab_fd)
