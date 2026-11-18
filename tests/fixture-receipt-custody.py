#!/usr/bin/env python3
"""Prove receipt replacement safety using only each test's temporary files.

The privileged CLI main and real subprocesses are never invoked. Only the
importable core runs, with every destination replaced by an owned temp path.
"""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock


sys.dont_write_bytecode = True
FIXTURES = pathlib.Path(__file__).resolve().parent / 'fixtures'


def metadata(path):
    value = path.lstat()
    # Reading a sentinel may change atime, which is not a custody mutation.
    return tuple(getattr(value, name) for name in (
        'st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink',
        'st_size', 'st_mtime_ns', 'st_ctime_ns',
    ))


class ReceiptCustodyCases:
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sites-receipt-custody-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name)
        self.lab = self.root / 'lab'
        self.lab.mkdir(mode=0o700)
        self.resources = self.root / 'resources'
        self.resources.mkdir(mode=0o700)
        self.uid, self.gid = os.geteuid(), os.getegid()

        tls = self.lab / 'tls'
        tls.mkdir(mode=0o700)
        (self.lab / 'password').write_bytes(b'a3' * 32)
        (self.lab / 'password').chmod(0o600)
        # The source reader checks custody, not certificate validity. No
        # connection is attempted and no real credential enters this suite.
        ca = b'-----BEGIN CERTIFICATE-----\n' + b'A' * 128 + b'\n-----END CERTIFICATE-----\n'
        (tls / 'ca.pem').write_bytes(ca)
        (tls / 'ca.pem').chmod(0o600)
        self.lab_fd = os.open(self.lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close, self.lab_fd)

        spec = importlib.util.spec_from_file_location('receipt_helper_under_test', FIXTURES / self.helper_relative)
        self.helper = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.helper)
        patches = contextlib.ExitStack()
        self.addCleanup(patches.close)
        self.files = tuple(str(self.resources / name) for name in ('manifest', 'password', 'ca.pem'))
        patches.enter_context(mock.patch.object(self.helper, 'FILES', self.files))
        self.leaves = ()
        if self.package:
            self.leaves = tuple(str(self.resources / name) for name in ('input', 'output'))
            patches.enter_context(mock.patch.object(self.helper, 'LEAVES', self.leaves))
            # Optional root-owned parents are outside this regression. Our
            # existing private temp parent belongs to the invoking test user.
            patches.enter_context(mock.patch.object(self.helper, 'PARENTS', ()))
            patches.enter_context(mock.patch.object(self.helper, 'ALLOWED', set(self.files + self.leaves)))
        patches.enter_context(mock.patch.object(
            self.helper, 'main', side_effect=AssertionError('privileged main must never run in this test'),
        ))

        self.output = io.StringIO()
        self.invoke('prepare')
        self.receipt = self.lab / self.receipt_name
        self.saved_receipt = self.receipt.read_bytes()
        self.receipt_identity = metadata(self.receipt)[:2]
        recorded = json.loads(self.saved_receipt)
        self.assertEqual({entry['path'] for entry in recorded}, set(self.files + self.leaves))
        self.assertEqual((self.receipt.stat().st_mode & 0o777), 0o600)
        self.initial_resources = self.resource_snapshot()

        self.sentinel = self.root / 'unrelated-sentinel'
        self.sentinel.write_bytes(b'UNRELATED SENTINEL: never replace or truncate\n')
        self.sentinel.chmod(0o600)
        self.sentinel_bytes = self.sentinel.read_bytes()
        self.sentinel_metadata = metadata(self.sentinel)
        self.output.truncate(0)
        self.output.seek(0)

    def invoke(self, operation):
        with contextlib.redirect_stdout(self.output):
            return self.helper.run(operation, self.lab, self.uid, self.gid, self.lab_fd)

    def resource_snapshot(self):
        snapshot = {}
        for path in (self.resources, *sorted(self.resources.rglob('*'))):
            entry = {'metadata': metadata(path)}
            if stat.S_ISREG(path.lstat().st_mode):
                entry['bytes'] = path.read_bytes()
            snapshot[str(path.relative_to(self.root))] = entry
        return snapshot

    def assert_preserved_resources_and_sentinel(self):
        self.assertEqual(self.resource_snapshot(), self.initial_resources)
        self.assertEqual(self.sentinel.read_bytes(), self.sentinel_bytes)
        self.assertEqual(metadata(self.sentinel), self.sentinel_metadata)

    def assert_restored_original(self, path):
        self.assertEqual(metadata(path)[:2], self.receipt_identity)
        self.assertEqual(path.read_bytes(), self.saved_receipt)
        self.assertTrue(stat.S_ISREG(path.lstat().st_mode))

    def child_refusal(self, mutation=None):
        def callback(command, *, capture_output, check):
            self.assertTrue(capture_output)
            self.assertFalse(check)
            self.assertEqual(command[2:], ['cleanup', str(self.lab), str(self.uid), str(self.gid)])
            # Run the actual cleanup decision against the temporarily invalid
            # inode receipt. This is not a synthetic success/failure stub.
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('cleanup')
            self.assertNotEqual(self.receipt.read_bytes(), self.saved_receipt)
            self.assert_preserved_resources_and_sentinel()
            if mutation is not None:
                mutation()
            return subprocess.CompletedProcess(command, 1, stdout=b'', stderr=b'fixture cleanup refused\n')
        return mock.patch.object(self.helper.subprocess, 'run', side_effect=callback)

    def test_stable_prepare_negative_check_and_cleanup(self):
        with self.child_refusal() as child:
            self.invoke('verify-mismatch')
        child.assert_called_once()
        self.assertIn('NEGATIVE OK', self.output.getvalue())
        self.assert_restored_original(self.receipt)
        self.assert_preserved_resources_and_sentinel()
        self.invoke('cleanup')
        for raw in self.files + self.leaves:
            self.assertFalse(os.path.lexists(raw))
        self.assertTrue(self.resources.is_dir())
        self.assert_restored_original(self.receipt)
        self.assertEqual(self.sentinel.read_bytes(), self.sentinel_bytes)
        self.assertEqual(metadata(self.sentinel), self.sentinel_metadata)

    def test_symlink_swap_during_child_restores_original_without_touching_target(self):
        detached = self.lab / 'original-receipt'

        def substitute():
            self.receipt.rename(detached)
            self.receipt.symlink_to(self.sentinel)

        with self.child_refusal(substitute) as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_called_once()
        self.assertNotIn('NEGATIVE OK', self.output.getvalue())
        self.assertTrue(self.receipt.is_symlink())
        self.assertEqual(self.receipt.readlink(), self.sentinel)
        self.assert_restored_original(detached)
        self.assert_preserved_resources_and_sentinel()

    def test_regular_swap_during_child_preserves_replacement_and_original(self):
        detached = self.lab / 'original-receipt'
        replacement = b'REPLACEMENT RECEIPT SENTINEL: never restore over this inode\n'
        replacement_metadata = None

        def substitute():
            nonlocal replacement_metadata
            self.receipt.rename(detached)
            self.receipt.write_bytes(replacement)
            self.receipt.chmod(0o600)
            replacement_metadata = metadata(self.receipt)

        with self.child_refusal(substitute) as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_called_once()
        self.assertNotIn('NEGATIVE OK', self.output.getvalue())
        self.assertEqual(self.receipt.read_bytes(), replacement)
        self.assertEqual(metadata(self.receipt), replacement_metadata)
        self.assert_restored_original(detached)
        self.assert_preserved_resources_and_sentinel()

    def test_receipt_symlink_before_open_is_refused_without_following_target(self):
        detached = self.lab / 'original-receipt'
        self.receipt.rename(detached)
        # Valid JSON ensures a missing symlink guard cannot accidentally pass
        # this test merely by producing a JSON decoding error.
        self.sentinel.write_bytes(self.saved_receipt)
        self.sentinel_bytes = self.sentinel.read_bytes()
        self.sentinel_metadata = metadata(self.sentinel)
        self.receipt.symlink_to(self.sentinel)
        with mock.patch.object(self.helper.subprocess, 'run') as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_not_called()
        self.assert_restored_original(detached)
        self.assert_preserved_resources_and_sentinel()

    def test_nonprivate_receipt_is_refused_before_cleanup_or_child(self):
        self.receipt.chmod(0o640)
        before = metadata(self.receipt)
        with mock.patch.object(self.helper.subprocess, 'run') as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_not_called()
        self.assertEqual(self.receipt.read_bytes(), self.saved_receipt)
        self.assertEqual(metadata(self.receipt), before)
        self.assert_preserved_resources_and_sentinel()

    def test_lab_swap_during_child_restores_pinned_original_and_preserves_new_lab(self):
        detached = self.root / 'detached-lab'
        replacement = b'NEW LAB RECEIPT SENTINEL: must remain untouched\n'
        replacement_metadata = None

        def substitute():
            nonlocal replacement_metadata
            self.lab.rename(detached)
            self.lab.mkdir(mode=0o700)
            self.receipt.write_bytes(replacement)
            self.receipt.chmod(0o600)
            replacement_metadata = metadata(self.receipt)

        with self.child_refusal(substitute) as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_called_once()
        self.assertNotIn('NEGATIVE OK', self.output.getvalue())
        self.assertEqual(self.receipt.read_bytes(), replacement)
        self.assertEqual(metadata(self.receipt), replacement_metadata)
        self.assert_restored_original(detached / self.receipt_name)
        self.assert_preserved_resources_and_sentinel()

    def test_lab_replaced_before_entry_is_refused_without_touching_either_receipt(self):
        detached = self.root / 'detached-lab'
        self.lab.rename(detached)
        self.lab.mkdir(mode=0o700)
        self.receipt.write_bytes(self.saved_receipt)
        self.receipt.chmod(0o600)
        replacement_metadata = metadata(self.receipt)
        with mock.patch.object(self.helper.subprocess, 'run') as child:
            with self.assertRaises((RuntimeError, OSError)):
                self.invoke('verify-mismatch')
        child.assert_not_called()
        self.assertEqual(self.receipt.read_bytes(), self.saved_receipt)
        self.assertEqual(metadata(self.receipt), replacement_metadata)
        self.assert_restored_original(detached / self.receipt_name)
        self.assert_preserved_resources_and_sentinel()


class PackageReceiptCustodyTest(ReceiptCustodyCases, unittest.TestCase):
    helper_relative = pathlib.Path('release-registry/cli-paths.py')
    receipt_name = 'cli-paths.json'
    package = True


class InspectionReceiptCustodyTest(ReceiptCustodyCases, unittest.TestCase):
    helper_relative = pathlib.Path('publication-inspection/cli-paths.py')
    receipt_name = 'publication-inspection-paths.json'
    package = False


if __name__ == '__main__':
    unittest.main(verbosity=1)
