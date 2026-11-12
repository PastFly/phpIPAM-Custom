#!/usr/bin/env python3
import importlib.util
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('post_check',ROOT/'tools/live/dns9-phase-b3-post-check.py')
post=importlib.util.module_from_spec(spec); spec.loader.exec_module(post)

class PostCheckTests(unittest.TestCase):
    def test_job_readback_preserves_status_without_secrets(self):
        db=sqlite3.connect(':memory:'); self.addCleanup(db.close)
        db.create_function('CONCAT',-1,lambda *args: ''.join(str(a) for a in args))
        db.create_function('IF',3,lambda condition,yes,no: yes if condition else no)
        db.create_function('OCTET_LENGTH',1,lambda value: len(value.encode()) if value is not None else None)
        db.executescript('CREATE TABLE custom_dns_control_jobs (id TEXT, job_type TEXT, state TEXT, attempt_count INTEGER, lease_id TEXT, lease_expires_at INTEGER, credential_delivered_at INTEGER, result_code TEXT, result_payload TEXT, finished_at INTEGER, created_at INTEGER, updated_at INTEGER, expires_at INTEGER);')
        job='a'*32
        db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',(job,'test_connection','running',1,'secret-lease',1200,123,'provider','secret-payload',None,1000,1100,1300))
        row=post.read_job(lambda q:db.execute(q).fetchone()[0],job)
        self.assertNotIn('secret',row)
        self.assertEqual(row,'test_connection|running|1|1|1200|1|provider|14|0|1000|1100|1300')
        with self.assertRaises(ValueError): post.read_job(lambda q:self.fail('must not query'),"x' OR 1=1")
    def test_global_guard_read_is_non_mutating_and_flags_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); marker=root/'dns9-b3-live-attempt-global'
            marker.write_text(post.EXPECTED_SOURCE_SHA+'\n'); marker.chmod(0o600)
            state=post.guard_state(root,os.getuid(),os.getgid())
            self.assertEqual(state['count'],1); self.assertTrue(state['global_matches'])
            self.assertEqual(marker.read_text(),post.EXPECTED_SOURCE_SHA+'\n')
            marker.unlink(); marker.symlink_to('/dev/null')
            state=post.guard_state(root,os.getuid(),os.getgid())
            self.assertFalse(state['global_matches']); self.assertTrue(marker.is_symlink())

if __name__=='__main__': unittest.main()
