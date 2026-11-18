#!/usr/bin/env python3
"""Exercise only request-fixture cleanup against owned temporary synthetic files.

No main(), root fixture, /run path, CI flag, credential or SQL call is used.
"""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import stat
import sys
import tempfile

sys.dont_write_bytecode = True
SOURCE = pathlib.Path(__file__).resolve().parent / 'fixtures' / 'publication-request' / 'cli-paths.py'
spec = importlib.util.spec_from_file_location('sites_request_custody_test_helper', SOURCE)
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


def blocked_main():
    raise AssertionError('The operational fixture entry point is disabled in this test')


helper.main = blocked_main


def create_file(path, payload, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(payload)
        stream.flush()
        os.fsync(stream.fileno())


def tree_snapshot(root):
    result = {}

    def visit(path):
        value = path.lstat()
        identity = (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
                    value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
        if stat.S_ISREG(value.st_mode):
            content = path.read_bytes()
        elif stat.S_ISLNK(value.st_mode):
            content = os.readlink(path)
        else:
            content = None
        result[str(path.relative_to(root))] = (identity, content)
        if stat.S_ISDIR(value.st_mode):
            for child in sorted(path.iterdir()):
                visit(child)

    visit(root)
    return result


checks = 0
for scenario in ('complete', 'last-inode', 'unexpected', 'symlink', 'hardlink', 'mode'):
    with tempfile.TemporaryDirectory(prefix='sites-request-custody-', dir='/tmp') as temporary:
        root = pathlib.Path(temporary)
        lab = root / 'lab'
        inputs = root / 'inputs'
        output = root / 'receipts'
        for directory in (lab, inputs, output):
            directory.mkdir(mode=0o700)
        files = tuple(str(inputs / name) for name in ('manifest', 'password', 'ca'))
        # Override every destination before invoking run. Preparation/main and
        # synthetic credential loading are never invoked by these cleanup cases.
        helper.FILES = files
        helper.DIRECTORY = str(output)
        helper.ALLOWED = set(files + (str(output),))
        helper.OUTPUT_NAMES = {'complete.json', 'reserved.json'}
        sentinel = root / 'sentinel'
        create_file(sentinel, b'SYNTHETIC-UNCHANGED-SENTINEL')
        for path in files:
            create_file(path, b'SYNTHETIC-INPUT-NOT-A-CREDENTIAL\n')
        create_file(output / 'complete.json', json.dumps({
            'schema': 'SITES_PUBLICATION_REQUEST_RECEIPT_V1',
            'planSha256': 'a' * 64,
            'operationRef': '70000000-0000-4000-8000-000000005050',
        }, separators=(',', ':')).encode() + b'\n')
        create_file(output / 'reserved.json', b'')
        created = [{'path': path, 'identity': helper.identity(path)} for path in (str(output),) + files]
        expected_error = None
        if scenario == 'last-inode':
            # The final recorded object must be checked before deleting the
            # output directory or any earlier input in this ownership receipt.
            created[-1]['identity']['ino'] += 1
            expected_error = 'fixture path was replaced; preserve every path'
        elif scenario == 'unexpected':
            create_file(output / 'keep-me.json', b'UNEXPECTED-PRIVATE-SENTINEL')
            expected_error = 'unexpected output; preserve the whole fixture'
        elif scenario in ('symlink', 'hardlink'):
            (output / 'complete.json').unlink()
            if scenario == 'symlink':
                (output / 'complete.json').symlink_to(sentinel)
            else:
                os.link(sentinel, output / 'complete.json')
            expected_error = 'private source custody mismatch'
        elif scenario == 'mode':
            (output / 'complete.json').chmod(0o644)
            expected_error = 'private source custody mismatch'
        receipt = lab / 'publication-request-paths.json'
        create_file(receipt, json.dumps(created).encode())
        before = tree_snapshot(root)
        captured = io.StringIO()
        error = None
        lab_fd = os.open(lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            with contextlib.redirect_stdout(captured):
                try:
                    helper.run('cleanup', lab, os.geteuid(), os.getegid(), lab_fd)
                except Exception as caught:
                    error = caught
        finally:
            os.close(lab_fd)
        if scenario == 'complete':
            assert error is None, 'the positive cleanup must succeed before negative cases are trusted'
            assert not output.exists() and all(not os.path.lexists(path) for path in files)
            assert captured.getvalue() == (
                'REQUEST CLI PATHS CLEANUP OK: only recorded inputs and declared private fixture receipts removed\n')
            after = tree_snapshot(root)
            assert after['sentinel'] == before['sentinel']
            assert after['lab/publication-request-paths.json'] == before['lab/publication-request-paths.json']
        else:
            assert type(error) is RuntimeError and str(error) == expected_error, scenario + ' reached the wrong refusal'
            assert captured.getvalue() == '', scenario + ' emitted a premature cleanup success'
            assert tree_snapshot(root) == before, scenario + ' changed a path before complete validation'
        checks += 1

print(f'PUBLICATION REQUEST CUSTODY OFFLINE PASS: {checks} cases; owned temporary IO only, no main, CI or SQL')
