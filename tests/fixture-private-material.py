#!/usr/bin/env python3
"""Exercise source-file custody in owned temporary files, without CI or /run."""
import importlib.util
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location(
    'sites_fixture_private_material',
    pathlib.Path(__file__).resolve().parent / 'fixtures' / 'release-registry' / 'private_material.py',
)
material = importlib.util.module_from_spec(spec)
spec.loader.exec_module(material)


class PrivateMaterialTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sites-private-source-test-')
        self.lab = pathlib.Path(self.temporary.name)
        self.tls = self.lab / 'tls'
        self.tls.mkdir(mode=0o700)
        self.password = self.lab / 'password'
        self.ca = self.tls / 'ca.pem'
        self.password_bytes = b'a3' * 32
        # Deliberately not a usable TLS certificate; these tests prove custody.
        self.ca_bytes = b'-----BEGIN CERTIFICATE-----\n' + b'A' * 128 + b'\n-----END CERTIFICATE-----\n'
        self.password.write_bytes(self.password_bytes)
        self.ca.write_bytes(self.ca_bytes)
        self.password.chmod(0o600)
        self.ca.chmod(0o600)
        self.lab_fd = os.open(self.lab, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(self.temporary.cleanup)
        self.addCleanup(os.close, self.lab_fd)

    def read(self, uid=None):
        return material.synthetic_material(self.lab_fd, os.geteuid() if uid is None else uid)

    def denied(self):
        with self.assertRaises((RuntimeError, OSError)):
            self.read()

    def test_valid_owned_sources_return_exact_bytes(self):
        self.assertEqual(self.read(), (self.password_bytes, self.ca_bytes))

    def test_password_symlink_is_denied(self):
        saved = self.lab / 'saved-password'
        self.password.rename(saved)
        self.password.symlink_to(saved)
        self.denied()

    def test_ca_symlink_is_denied(self):
        saved = self.lab / 'saved-ca'
        self.ca.rename(saved)
        self.ca.symlink_to(saved)
        self.denied()

    def test_tls_directory_symlink_is_denied(self):
        saved = self.lab / 'saved-tls'
        self.tls.rename(saved)
        self.tls.symlink_to(saved, target_is_directory=True)
        self.denied()

    def test_password_hardlink_is_denied(self):
        os.link(self.password, self.lab / 'password-alias')
        self.denied()

    def test_ca_hardlink_is_denied(self):
        os.link(self.ca, self.lab / 'ca-alias')
        self.denied()

    def test_password_with_group_access_is_denied(self):
        self.password.chmod(0o640)
        self.denied()

    def test_ca_with_world_access_is_denied(self):
        self.ca.chmod(0o604)
        self.denied()

    def test_tls_directory_with_group_access_is_denied(self):
        self.tls.chmod(0o750)
        self.denied()

    def test_wrong_owner_is_denied(self):
        with self.assertRaises(RuntimeError):
            self.read(os.geteuid() + 1)

    def test_non_hex_password_is_denied(self):
        self.password.write_bytes(b'z' * 64)
        self.denied()

    def test_trailing_password_newline_is_denied(self):
        self.password.write_bytes(self.password_bytes + b'\n')
        self.denied()

    def test_private_key_is_denied(self):
        self.ca.write_bytes(self.ca_bytes + b'-----BEGIN PRIVATE KEY-----')
        self.denied()

    def test_missing_certificate_marker_is_denied(self):
        self.ca.write_bytes(b'A' * 150)
        self.denied()

    def test_oversized_ca_is_denied(self):
        self.ca.write_bytes(self.ca_bytes + b'A' * 65536)
        self.denied()

    def test_missing_source_is_denied(self):
        self.ca.unlink()
        self.denied()

    def test_replacement_between_stat_and_open_is_denied(self):
        original_open = os.open
        replaced = False

        def replace_then_open(name, *args, **kwargs):
            nonlocal replaced
            if name == 'password' and not replaced:
                replaced = True
                self.password.rename(self.lab / 'previous-password')
                self.password.write_bytes(self.password_bytes)
                self.password.chmod(0o600)
            return original_open(name, *args, **kwargs)

        with mock.patch.object(material.os, 'open', side_effect=replace_then_open):
            self.denied()
        self.assertTrue(replaced)

    def test_replacement_after_read_is_denied(self):
        original_stat = os.stat
        calls = 0

        def replace_after_read(name, *args, **kwargs):
            nonlocal calls
            if name == 'password':
                calls += 1
                if calls == 2:
                    self.password.rename(self.lab / 'previous-password')
                    self.password.write_bytes(self.password_bytes)
                    self.password.chmod(0o600)
            return original_stat(name, *args, **kwargs)

        with mock.patch.object(material.os, 'stat', side_effect=replace_after_read):
            self.denied()
        self.assertEqual(calls, 2)


if __name__ == '__main__':
    unittest.main(verbosity=1)
