#!/usr/bin/env python3
"""Disposable filesystem simulation; ancestor DB/keyring verifier is explicitly stubbed."""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('global_gate', ROOT / 'tools/phase5f331-global-search.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
NATIVE = Path(os.environ['UPSTREAM_SEARCH']).read_bytes()
assert gate.blob(NATIVE) == gate.NATIVE_BLOB, 'Upstream pinned native search fixture mismatch'


class Deployment(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp.name) / 'repo'; self.repo.mkdir()
        self.target = Path(self.temp.name) / 'target'; self.target.mkdir()
        for rel in gate.FILES:
            dest = self.repo / 'layers/phase5f331' / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / 'layers/phase5f331' / rel, dest)
        verifier = self.repo / 'installer/verify-vmware-vcenter-phase5f33-enriched-search.sh'
        verifier.parent.mkdir()
        verifier.write_text('#!/bin/bash\nset -e\nif [[ -f "$1/force-post-failure" && -f "$1/app/tools/search/vmware-global-panel.php" ]]; then exit 51; fi\necho FIXTURE_ANCESTOR_VERIFIED\n')
        for args in (['init', '-q'], ['config', 'user.name', 'Fixture'], ['config', 'user.email', 'fixture@example.invalid'], ['add', '.'], ['commit', '-qm', 'fixture']):
            subprocess.run(['git', '-C', str(self.repo)] + args, check=True)
        gate.REPO = self.repo
        self.originals = {gate.NATIVE: NATIVE, gate.WORKSPACE: b'<?php echo "BASE"; ?>\n' + gate.AUTOLOAD + b'\n<!-- Phase 5F.3.3 fixture -->\n'}
        for rel, data in self.originals.items():
            dest = self.target / rel; dest.parent.mkdir(parents=True, exist_ok=True); dest.write_bytes(data)

    def tearDown(self):
        self.temp.cleanup()

    def assert_original(self):
        for rel, data in self.originals.items(): self.assertEqual((self.target / rel).read_bytes(), data)
        for rel in gate.FILES: self.assertFalse((self.target / rel).exists())

    def test_preflight_is_read_only(self):
        before = sorted(str(p.relative_to(self.target)) for p in self.target.rglob('*'))
        gate.preflight(self.target)
        self.assert_original()
        self.assertEqual(before, sorted(str(p.relative_to(self.target)) for p in self.target.rglob('*')))

    def test_apply_verify_standalone_rollback(self):
        backup = gate.install(self.target)
        gate.verify(self.target)
        self.assertEqual((self.target / gate.NATIVE).read_bytes().replace(gate.NATIVE_HOOK, b'', 1), NATIVE)
        workspace = (self.target / gate.WORKSPACE).read_bytes()
        self.assertLess(workspace.index(gate.WORKSPACE_HOOK), workspace.index(gate.AUTOLOAD))
        with self.assertRaises(RuntimeError): gate.preflight(self.target)
        shutil.rmtree(self.repo)
        subprocess.run(['bash', str(backup / 'rollback.sh')], check=True)
        self.assert_original()

    def test_post_verify_failure_rolls_back(self):
        (self.target / 'force-post-failure').touch()
        with self.assertRaises(subprocess.CalledProcessError): gate.install(self.target)
        self.assert_original()

    def test_native_drift_fails_closed(self):
        (self.target / gate.NATIVE).write_bytes(NATIVE + b'LOCAL_CUSTOMIZATION')
        with self.assertRaises(RuntimeError): gate.preflight(self.target)
        self.assertFalse(list(self.target.glob('.phpipam-custom*')))

    def test_uncommitted_source_rejected(self):
        (self.repo / 'layers/phase5f331' / gate.FILES[0]).write_text('source drift')
        with self.assertRaises(RuntimeError): gate.preflight(self.target)
        self.assert_original()

    def test_rollback_refuses_foreign_update(self):
        backup = gate.install(self.target)
        path = self.target / gate.FILES[0]; path.write_bytes(path.read_bytes() + b'foreign change')
        with self.assertRaises(RuntimeError): gate.rollback(backup)
        self.assertIn(gate.NATIVE_HOOK, (self.target / gate.NATIVE).read_bytes())

    def test_symlink_is_rejected(self):
        (self.target / gate.NATIVE).unlink()
        (self.target / gate.NATIVE).symlink_to(Path(os.environ['UPSTREAM_SEARCH']).resolve())
        with self.assertRaises(RuntimeError): gate.preflight(self.target)


if __name__ == '__main__':
    if os.geteuid() != 0: raise SystemExit('Run the disposable deployment test as root; it exercises the standalone root-only rollback CLI.')
    unittest.main(verbosity=2)
