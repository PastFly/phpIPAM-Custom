#!/usr/bin/env python3
import importlib.util
import sqlite3
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
b3=importlib.util.module_from_spec(spec); spec.loader.exec_module(b3)

class PilotEligibilityTests(unittest.TestCase):
    def test_query_checks_assignment_and_expiry_against_database(self):
        db=sqlite3.connect(':memory:')
        self.addCleanup(db.close)
        db.create_function('UNIX_TIMESTAMP',0,lambda:1000)
        db.create_function('CONCAT',-1,lambda *args: ''.join(str(arg) for arg in args))
        db.executescript('''
            CREATE TABLE custom_dns_control_jobs (id TEXT, job_type TEXT, collector_id TEXT, connection_id TEXT, expires_at INTEGER, state TEXT, assignment_revision INTEGER, configuration_revision INTEGER, binding_epoch INTEGER, credential_version INTEGER);
            CREATE TABLE custom_dns_collector_assignments (connection_id TEXT, collector_id TEXT, revision INTEGER);
            CREATE TABLE custom_dns_connections (id TEXT, revision INTEGER, binding_epoch INTEGER, credential_mode TEXT, server TEXT);
            CREATE TABLE custom_dns_collectors (id TEXT, revoked INTEGER);
        ''')
        collector='f57057d6677e1ca7a032aab8b68796ac'; connection='33b6fade9323f15aa7b1f56a8a865a9d'
        db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?)',(b3.PILOT_JOB_ID,'test_connection',collector,connection,1600,'pending',1,2,3,None))
        db.execute('INSERT INTO custom_dns_collector_assignments VALUES (?,?,?)',(connection,collector,1))
        db.execute('INSERT INTO custom_dns_connections VALUES (?,?,?,?,?)',(connection,2,3,'collector_identity',b3.SERVER))
        db.execute('INSERT INTO custom_dns_collectors VALUES (?,?)',(collector,0))
        def one(query):
            rows=db.execute(query).fetchall()
            if len(rows)!=1: raise RuntimeError('database_row_count_invalid')
            return rows[0][0]
        with patch.object(b3,'one',side_effect=one):
            self.assertEqual(b3.pilot_ttl(),600)
            db.execute('UPDATE custom_dns_control_jobs SET expires_at=999')
            with self.assertRaises(RuntimeError): b3.pilot_ttl()
            db.execute('UPDATE custom_dns_control_jobs SET expires_at=1600')
            db.execute('UPDATE custom_dns_collector_assignments SET revision=2')
            with self.assertRaises(RuntimeError): b3.pilot_ttl()

    def test_new_job_can_be_selected_without_rearming_expired_original(self):
        db=sqlite3.connect(':memory:'); self.addCleanup(db.close)
        db.create_function('UNIX_TIMESTAMP',0,lambda:1000)
        db.create_function('CONCAT',-1,lambda *args: ''.join(str(arg) for arg in args))
        db.executescript('''
            CREATE TABLE custom_dns_control_jobs (id TEXT, job_type TEXT, collector_id TEXT, connection_id TEXT, expires_at INTEGER, state TEXT, assignment_revision INTEGER, configuration_revision INTEGER, binding_epoch INTEGER, credential_version INTEGER, attempt_count INTEGER, lease_id TEXT);
            CREATE TABLE custom_dns_collector_assignments (connection_id TEXT, collector_id TEXT, revision INTEGER);
            CREATE TABLE custom_dns_connections (id TEXT, revision INTEGER, binding_epoch INTEGER, credential_mode TEXT, server TEXT);
            CREATE TABLE custom_dns_collectors (id TEXT, revoked INTEGER);
        ''')
        collector=b3.PILOT_COLLECTOR_ID; connection=b3.PILOT_CONNECTION_ID; fresh='e'*32
        db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',(b3.PILOT_JOB_ID,'test_connection',collector,connection,999,'pending',1,2,3,None,0,None))
        db.execute('INSERT INTO custom_dns_collector_assignments VALUES (?,?,?)',(connection,collector,1))
        db.execute('INSERT INTO custom_dns_connections VALUES (?,?,?,?,?)',(connection,2,3,'collector_identity',b3.SERVER))
        db.execute('INSERT INTO custom_dns_collectors VALUES (?,?)',(collector,0))
        def one(query):
            rows=db.execute(query).fetchall()
            if len(rows)!=1: raise RuntimeError('database_row_count_invalid')
            return rows[0][0]
        with patch.object(b3,'one',side_effect=one):
            self.assertEqual(b3.legacy_pilot_expired(),-1)
            db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',(fresh,'test_connection',collector,connection,1600,'pending',1,2,3,None,0,None))
            self.assertEqual(b3.fresh_job_id(),fresh)
            self.assertEqual(b3.pilot_ttl(fresh),600)
            with self.assertRaises(RuntimeError): b3.legacy_pilot_expired()
            db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',('f'*32,'list_zones',collector,connection,1600,'pending',1,2,3,None,0,None))
            with self.assertRaises(RuntimeError): b3.pilot_ttl(fresh)
            db.execute("UPDATE custom_dns_control_jobs SET job_type='test_connection' WHERE id=?",('f'*32,))
            with self.assertRaises(RuntimeError): b3.fresh_job_id()
        self.assertEqual(db.execute('SELECT state,expires_at FROM custom_dns_control_jobs WHERE id=?',(b3.PILOT_JOB_ID,)).fetchone(),('pending',999))

    def test_untrusted_job_identifier_is_rejected_before_query(self):
        with patch.object(b3,'one') as one, self.assertRaises(ValueError):
            b3.job_state("x' OR 1=1 --")
        one.assert_not_called()

    def test_job_readback_does_not_expose_lease_or_unexpected_payload(self):
        db=sqlite3.connect(':memory:'); self.addCleanup(db.close)
        db.create_function('CONCAT',-1,lambda *args: ''.join(str(arg) for arg in args))
        db.create_function('IF',3,lambda condition,yes,no: yes if condition else no)
        db.create_function('OCTET_LENGTH',1,lambda value: len(value.encode()) if value is not None else None)
        db.execute('CREATE TABLE custom_dns_control_jobs (id TEXT,state TEXT,attempt_count INTEGER,lease_id TEXT,credential_delivered_at INTEGER,result_code TEXT,result_payload TEXT,finished_at INTEGER)')
        db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?)',(b3.PILOT_JOB_ID,'running',1,'fixture-secret-lease',None,'unexpected-code','fixture-secret-payload',None))
        with patch.object(b3,'one',side_effect=lambda query:db.execute(query).fetchone()[0]):
            result=b3.job_state()
        self.assertNotIn('fixture-secret',result)
        self.assertEqual(result,'running|1|set|0|other|nonempty|0')

    def test_expected_unexpired_pilot_is_eligible(self):
        row='test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|1'
        with patch.object(b3,'one',return_value=row):
            self.assertEqual(b3.pilot_ttl(),600)
    def test_list_zones_is_only_accepted_when_explicitly_expected(self):
        row='list_zones|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|1'
        with patch.object(b3,'one',return_value=row):
            self.assertEqual(b3.pilot_ttl('f'*32,'list_zones'),600)
            with self.assertRaises(RuntimeError): b3.pilot_ttl('f'*32)
            with self.assertRaises(ValueError): b3.pilot_ttl('f'*32,'arbitrary')
    def test_expired_wrong_identity_and_other_jobs_are_refused(self):
        rows=[
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|-1|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|179|1',
            'list_zones|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|1',
            'test_connection|wrong|33b6fade9323f15aa7b1f56a8a865a9d|600|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|wrong|600|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|2',
            'invalid',
        ]
        for row in rows:
            with self.subTest(row=row), patch.object(b3,'one',return_value=row), self.assertRaises(RuntimeError):
                b3.pilot_ttl()

if __name__=='__main__': unittest.main()
