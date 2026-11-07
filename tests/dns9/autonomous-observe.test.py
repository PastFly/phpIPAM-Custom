#!/usr/bin/env python3
import importlib.util
import pathlib
import sqlite3
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
PATH = ROOT / 'tools/live/dns9-autonomous-observe.py'

def load():
    if not PATH.exists(): return None
    spec = importlib.util.spec_from_file_location('observe', PATH)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module

class ObservationTests(unittest.TestCase):
    def setUp(self):
        self.obs = load()
        self.assertIsNotNone(self.obs, 'read-only observation is not implemented')
        self.db = sqlite3.connect(':memory:'); self.addCleanup(self.db.close)
        self.db.create_function('CONCAT', -1, lambda *xs: ''.join(map(str, xs)))
        self.db.executescript('''
          CREATE TABLE custom_dns_connections(id TEXT,enabled INTEGER,server TEXT,credential_mode TEXT,interval_seconds INTEGER,revision INTEGER,binding_epoch INTEGER);
          CREATE TABLE custom_dns_collector_assignments(connection_id TEXT,collector_id TEXT,revision INTEGER);
          CREATE TABLE custom_dns_collectors(id TEXT,revoked INTEGER);
          CREATE TABLE custom_dns_control_events(object_id TEXT,action TEXT,actor_id INTEGER);
          CREATE TABLE custom_dns_control_jobs(id TEXT,connection_id TEXT,collector_id TEXT,assignment_revision INTEGER,
             configuration_revision INTEGER,binding_epoch INTEGER,credential_version INTEGER,created_at INTEGER,job_type TEXT,
             state TEXT,attempt_count INTEGER,lease_id TEXT,credential_delivered_at INTEGER,result_code TEXT,finished_at INTEGER);
        ''')
        c = self.obs.CONNECTION; k = self.obs.COLLECTOR
        self.db.execute('INSERT INTO custom_dns_connections VALUES (?,?,?,?,?,?,?)',(c,1,self.obs.SERVER,'collector_identity',300,8,5))
        self.db.execute('INSERT INTO custom_dns_collector_assignments VALUES (?,?,?)',(c,k,3))
        self.db.execute('INSERT INTO custom_dns_collectors VALUES (?,?)',(k,0))
        self.add_job(self.obs.FIRST_JOB,1000)

    def add_job(self, job, created, state='succeeded', attempts=1, revision=8, auto=True):
        self.db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            (job,self.obs.CONNECTION,self.obs.COLLECTOR,3,revision,5,None,created,'list_zones',state,attempts,None,created+1,'ok',created+2))
        if auto: self.db.execute('INSERT INTO custom_dns_control_events VALUES (?,?,?)',(job,'autonomous_job_queued',0))

    def one(self, sql):
        row = self.db.execute(sql).fetchone()
        return '' if row is None else str(row[0])

    def test_followup_requires_a_separate_correctly_bound_automatic_success(self):
        self.assertEqual(self.obs.inspect_jobs(self.one,8,5), {'successful':1,'failed':0,'active':0,'followup':None})
        self.add_job('a'*32,1300)
        self.assertEqual(self.obs.inspect_jobs(self.one,8,5), {'successful':2,'failed':0,'active':0,'followup':'a'*32})
        self.add_job('b'*32,1600,state='failed')
        self.assertEqual(self.obs.inspect_jobs(self.one,8,5)['failed'],1)

    def test_wrong_binding_or_manual_jobs_cannot_be_followup(self):
        self.add_job('a'*32,1300,revision=9)
        self.add_job('b'*32,1400,auto=False)
        self.add_job('c'*32,1500,attempts=2)
        self.assertIsNone(self.obs.inspect_jobs(self.one,8,5)['followup'])

    def test_malformed_success_is_reported_as_failed_evidence(self):
        self.add_job('a'*32,1300)
        self.db.execute("UPDATE custom_dns_control_jobs SET credential_delivered_at=NULL WHERE id=?",('a'*32,))
        summary=self.obs.inspect_jobs(self.one,8,5)
        self.assertEqual(summary['failed'],1)
        self.assertIsNone(summary['followup'])

    def test_disabled_or_reassigned_source_fails_closed(self):
        self.db.execute('UPDATE custom_dns_connections SET enabled=0')
        with self.assertRaisesRegex(RuntimeError,'baseline_or_binding_changed'): self.obs.inspect_jobs(self.one,8,5)
        self.db.execute('UPDATE custom_dns_connections SET enabled=1')
        self.db.execute('UPDATE custom_dns_collector_assignments SET revision=4')
        with self.assertRaisesRegex(RuntimeError,'baseline_or_binding_changed'): self.obs.inspect_jobs(self.one,8,5)

    def test_baseline_receipt_rejects_unknown_or_mismatched_evidence(self):
        receipt = {'source_sha':self.obs.SOURCE_SHA,'backup_sha256':self.obs.BACKUP_SHA,'revision':8,'binding_epoch':5,
                   'dns_write':False,'cycle':{'job_id':self.obs.FIRST_JOB,'state':'succeeded','attempts':1,
                       'zone_count':172,'old_jobs_expired':2,'timer_enabled':True,'timer_active':True,
                       'windows_enabled':True,'windows_ran':True,'service_ran':True,'payload_sha256':self.obs.PAYLOAD_SHA}}
        self.obs.validate_receipt(receipt)
        for change in ({'source_sha':'0'*40},{'revision':True},{'dns_write':True}, {'cycle':{}}):
            with self.subTest(change=change),self.assertRaises(RuntimeError): self.obs.validate_receipt({**receipt,**change})

    def test_failure_output_exposes_only_allowlisted_safe_reasons(self):
        self.assertEqual(self.obs.safe_error(RuntimeError('timer_not_active')),'timer_not_active')
        self.assertEqual(self.obs.safe_error(RuntimeError('baseline_or_binding_changed')),'baseline_or_binding_changed')
        self.assertEqual(self.obs.safe_error(RuntimeError('secret from database')),'read_only_check_stopped')

if __name__ == '__main__': unittest.main()
