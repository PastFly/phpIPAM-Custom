#!/usr/bin/env python3
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('b3_recover',ROOT/'tools/live/dns9-phase-b3-recover.py')
recover=importlib.util.module_from_spec(spec)
spec.loader.exec_module(recover)

class GuardRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.root=Path(self.temp.name)/'guards'; self.root.mkdir(mode=0o700)
        self.backups=Path(self.temp.name)/'backups'; self.backups.mkdir(mode=0o700)
        self.guard=self.root/recover.GUARD_NAME
        self.guard.write_text(recover.OLD_SHA+'\n'); self.guard.chmod(0o600)
        self.owner=(os.getuid(),os.getgid())
    def tearDown(self): self.temp.cleanup()
    def test_exact_guard_is_preserved_in_private_backup_and_cannot_be_recovered_twice(self):
        archived=recover.archive_guard(self.root,self.backups,*self.owner)
        self.assertFalse(self.guard.exists())
        self.assertEqual(archived.read_text(),recover.OLD_SHA+'\n')
        self.assertEqual(archived.stat().st_mode & 0o777,0o600)
        self.assertEqual(archived.parent.stat().st_mode & 0o777,0o700)
        with self.assertRaises((RuntimeError,OSError)): recover.archive_guard(self.root,self.backups,*self.owner)
    def test_other_guards_block_without_removing_anything(self):
        other=self.root/'dns9-b3-live-attempt-another'; other.write_text('evidence')
        with self.assertRaises(RuntimeError): recover.archive_guard(self.root,self.backups,*self.owner)
        self.assertTrue(self.guard.exists()); self.assertEqual(other.read_text(),'evidence')
    def test_failed_archive_does_not_remove_guard(self):
        with patch.object(recover.os,'rename',side_effect=OSError('unavailable')):
            with self.assertRaises(OSError): recover.archive_guard(self.root,self.backups,*self.owner)
        self.assertEqual(self.guard.read_text(),recover.OLD_SHA+'\n')
    def test_wrong_contents_and_unsafe_metadata_are_refused(self):
        for variant in ('contents','mode','symlink','hardlink'):
            with self.subTest(variant=variant):
                self.guard.unlink(missing_ok=True)
                target=self.root/'outside'; target.unlink(missing_ok=True)
                if variant=='symlink':
                    target.write_text(recover.OLD_SHA+'\n'); self.guard.symlink_to(target)
                else:
                    self.guard.write_text(('wrong' if variant=='contents' else recover.OLD_SHA)+'\n')
                    self.guard.chmod(0o644 if variant=='mode' else 0o600)
                    if variant=='hardlink': os.link(self.guard,target)
                with self.assertRaises((RuntimeError,OSError)): recover.archive_guard(self.root,self.backups,*self.owner)
                self.assertTrue(self.guard.is_symlink() or self.guard.exists())
    def test_unsafe_remote_evidence_blocks_recovery(self):
        valid={'permanent_task_state':'Disabled','pilot_task_count':0,'collector_process_count':0,'powershell_unknown_commandline_count':0}
        recover.check_remote(valid)
        for name in valid:
            value=dict(valid); value[name]='Enabled' if name=='permanent_task_state' else 1
            with self.subTest(name=name), self.assertRaises(RuntimeError): recover.check_remote(value)
        with self.assertRaises(RuntimeError): recover.check_remote({})

if __name__=='__main__': unittest.main()
