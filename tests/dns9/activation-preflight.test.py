#!/usr/bin/env python3
import importlib.util
import base64
import hashlib
import pathlib
import sqlite3
import sys
import unittest
from types import SimpleNamespace

ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('activation',ROOT/'tools/live/dns9-activation-preflight.py')
activation=importlib.util.module_from_spec(spec); spec.loader.exec_module(activation)

class ActivationPreflightTests(unittest.TestCase):
    def test_remote_readiness_rejects_unsafe_or_uncertain_state(self):
        good={'permanent_disabled':True,'principal_system':True,'action_expected':True,'trigger_count':1,
            'trigger_enabled':True,'interval_seconds':60,'repetition_active':True,'start_when_available':True,
            'ignore_new':True,'execution_limit_seconds':180,'files_match':True,'config_expected':True,'token_blob_present':True,'status_expected':True,
            'pilot_task_count':0,'unknown_process_count':0,'collector_process_count':0,'server_epoch':2000000000}
        activation.validate_remote(good,2000000000)
        for key,value in [('permanent_disabled',False),('principal_system',False),('action_expected',False),
            ('trigger_count',2),('trigger_enabled',False),('interval_seconds',900),('repetition_active',False),
            ('start_when_available',False),('ignore_new',False),('execution_limit_seconds',181),
            ('files_match',False),('config_expected',False),('token_blob_present',False),('status_expected',False),('pilot_task_count',1),
            ('unknown_process_count',1),('collector_process_count',1),('server_epoch',1999999800)]:
            with self.subTest(key=key),self.assertRaises(RuntimeError): activation.validate_remote({**good,key:value},2000000000)

    def test_only_two_identified_expired_jobs_and_no_other_risk_is_ready(self):
        activation.validate_queue('2|0|0|0|0|2')
        for row in ('0|0|0|0|0|0','1|0|0|0|0|1','2|1|0|0|0|2','2|0|1|0|0|2',
                    '2|0|0|1|0|2','2|0|0|0|1|2','2|0|0|0|0|1','3|0|0|0|0|2','broken'):
            with self.subTest(row=row),self.assertRaises(RuntimeError): activation.validate_queue(row)

    def test_database_checks_exact_rows_and_audits_without_mutating_them(self):
        db=sqlite3.connect(':memory:'); self.addCleanup(db.close)
        db.create_function('UNIX_TIMESTAMP',0,lambda:2000000000)
        db.create_function('CONCAT',-1,lambda *values:''.join(str(value) for value in values))
        db.create_function('IF',3,lambda condition,yes,no:yes if condition else no)
        db.executescript('''
            CREATE TABLE custom_dns_control_jobs (id TEXT,job_type TEXT,state TEXT,collector_id TEXT,connection_id TEXT,
                expires_at INTEGER,attempt_count INTEGER,lease_id TEXT,lease_expires_at INTEGER,credential_delivered_at INTEGER,
                result_code TEXT,result_payload TEXT,finished_at INTEGER,created_at INTEGER,updated_at INTEGER,updated_by INTEGER,
                assignment_revision INTEGER,configuration_revision INTEGER,binding_epoch INTEGER,credential_version INTEGER);
            CREATE TABLE custom_dns_connections (id TEXT,enabled INTEGER,revision INTEGER,binding_epoch INTEGER,credential_mode TEXT,server TEXT);
            CREATE TABLE custom_dns_collector_assignments (connection_id TEXT,collector_id TEXT,revision INTEGER);
            CREATE TABLE custom_dns_collectors (id TEXT,revoked INTEGER);
            CREATE TABLE custom_dns_control_events (object_id TEXT,action TEXT,actor_id INTEGER);
        ''')
        connection='33b6fade9323f15aa7b1f56a8a865a9d'; collector='f57057d6677e1ca7a032aab8b68796ac'
        db.execute('INSERT INTO custom_dns_connections VALUES (?,?,?,?,?,?)',(connection,0,7,4,'collector_identity',activation.SERVER))
        db.execute('INSERT INTO custom_dns_collector_assignments VALUES (?,?,?)',(connection,collector,3))
        db.execute('INSERT INTO custom_dns_collectors VALUES (?,?)',(collector,0))
        for job_id,kind,created in activation.EXPIRED_UI_JOBS:
            db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                (job_id,kind,'pending',collector,connection,created+300,0,None,None,None,None,None,None,created,created,4,3,7,4,None))
            db.execute('INSERT INTO custom_dns_control_events VALUES (?,?,?)',(job_id,'control_job_queued',4))
        b3=SimpleNamespace(PILOT_CONNECTION_ID=connection,PILOT_COLLECTOR_ID=collector,one=lambda sql:db.execute(sql).fetchone()[0])
        self.assertEqual(activation.queue_summary(b3),'2|0|0|0|0|2')
        changes=[
            "UPDATE custom_dns_control_jobs SET expires_at=2000000001 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET attempt_count=1 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET lease_id='secret' WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET credential_delivered_at=1 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET result_payload='fixture' WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET credential_version=1 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET updated_by=5 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET updated_at=updated_at+1 WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_control_jobs SET state='running' WHERE id='"+activation.EXPIRED_UI_JOBS[0][0]+"'",
            "UPDATE custom_dns_collectors SET revoked=1",
            "UPDATE custom_dns_collector_assignments SET revision=4",
            "UPDATE custom_dns_connections SET binding_epoch=5",
            "INSERT INTO custom_dns_control_events VALUES ('"+activation.EXPIRED_UI_JOBS[0][0]+"','control_job_cli_queued',4)",
            "INSERT INTO custom_dns_control_events VALUES ('"+activation.EXPIRED_UI_JOBS[0][0]+"','autonomous_job_queued',0)",
            "INSERT INTO custom_dns_control_events VALUES ('"+activation.EXPIRED_UI_JOBS[0][0]+"','control_job_queued',4)",
        ]
        for change in changes:
            with self.subTest(change=change):
                db.execute('SAVEPOINT fixture'); db.execute(change)
                with self.assertRaises(RuntimeError): activation.validate_queue(activation.queue_summary(b3))
                db.execute('ROLLBACK TO fixture'); db.execute('RELEASE fixture')
        self.assertEqual(db.execute("SELECT COUNT(*) FROM custom_dns_control_jobs WHERE state='pending'").fetchone()[0],2)

    def test_windows_check_contains_no_task_or_file_mutations(self):
        source=(ROOT/'tools/live/dns9-activation-preflight.ps1').read_text().lower()
        for forbidden in ('enable-scheduledtask','disable-scheduledtask','start-scheduledtask',
                'register-scheduledtask','unregister-scheduledtask','set-scheduledtask',
                'remove-item','set-content','new-item','invoke-webrequest'):
            with self.subTest(command=forbidden): self.assertNotIn(forbidden,source)

    def test_winrm_loaders_fit_windows_command_boundary(self):
        source=(ROOT/'tools/live/dns9-activation-preflight.ps1').read_text()
        names=('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')
        hashes={name:hashlib.sha256(name.encode()).hexdigest() for name in names}
        loaders=activation.remote_loaders(source,hashes)
        self.assertEqual(len(loaders),2)
        for loader in loaders:
            self.assertLessEqual(len(base64.b64encode(loader.encode('utf-16le'))),7500)

if __name__=='__main__': unittest.main()
