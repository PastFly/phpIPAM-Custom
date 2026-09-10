#!/usr/bin/env python3
"""Pure environment-survey tests. No production/runtime or vCenter access."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

source = Path(__file__).resolve().parents[1] / 'tools/live-vmware-phase5f34-cache-preflight.py'
spec = importlib.util.spec_from_file_location('cache_preflight', source)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


class EnvironmentTests(unittest.TestCase):
    def test_read_only_metadata(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            cache = root / 'cache' / 'vmware'
            key = root / 'etc' / 'cache.key'
            target = root / 'www'
            before = list(root.iterdir())
            self.assertEqual(preflight.inspect_path(str(cache), target)['state'], 'ABSENT')
            self.assertEqual(preflight.inspect_path(str(key), target, True)['state'], 'ABSENT')
            self.assertEqual(list(root.iterdir()), before)

    def test_unsafe_locations(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            target = root / 'www'
            target.mkdir()
            with self.assertRaisesRegex(RuntimeError, 'outside_webroot'):
                preflight.inspect_path(str(target / 'cache'), target)
            alias = root / 'alias'
            alias.symlink_to(target)
            with self.assertRaisesRegex(RuntimeError, 'symlink'):
                preflight.inspect_path(str(alias / 'cache'), target)
            with self.assertRaises(RuntimeError):
                preflight.checked_path('../cache')
            with self.assertRaises(RuntimeError):
                preflight.checked_path('/tmp/../cache')
            public = root / 'public'
            public.mkdir(mode=0o777)
            public.chmod(0o777)
            with self.assertRaisesRegex(RuntimeError, 'writable'):
                preflight.inspect_path(str(public / 'cache'), target)

    def test_present_is_review_not_overwrite(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            key = root / 'cache.key'
            key.write_bytes(b'fixture-key-not-read-by-survey')
            key.chmod(0o600)
            result = preflight.inspect_path(str(key), root / 'www', True)
            self.assertEqual(result['state'], 'EXISTS_REQUIRES_REVIEW')
            self.assertNotIn('fixture', str(result))
            self.assertEqual(key.read_bytes(), b'fixture-key-not-read-by-survey')

    def test_worker_user_is_not_guessed(self):
        class Result:
            stdout = '0 apache2\n33 apache2\n33 apache2\n0 php-fpm8.2\n99 php-fpm8.2\n44 unrelated\n'
        class User:
            pw_gid = 123
            pw_name = 'fixture-web'
        with patch.object(preflight.subprocess, 'run', return_value=Result()), patch.object(preflight.pwd, 'getpwuid', return_value=User()):
            users = preflight.web_workers()
        self.assertEqual([x['uid'] for x in users], [33, 99])


if __name__ == '__main__':
    unittest.main()
