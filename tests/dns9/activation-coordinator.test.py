#!/usr/bin/env python3
import importlib.util
import contextlib
import io
import pathlib
import os
import sqlite3
import threading
from types import SimpleNamespace
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
PATH = ROOT / 'tools/live/dns9-activation-coordinator.py'

def load_coordinator():
    if not PATH.is_file():
        return None
    spec = importlib.util.spec_from_file_location('activation_coordinator', PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class FakeBackend:
    def __init__(self, fail=None):
        self.events = []
        self.fail = fail

    def call(self, name):
        self.events.append(name)
        if name == self.fail:
            raise RuntimeError('injected_failure')

    def preflight(self): self.call('preflight')
    def reserve(self): self.call('reserve')
    def lock_and_backup(self): self.call('lock_and_backup')
    def recheck_windows(self): self.call('recheck_windows')
    def enable_windows(self): self.call('enable_windows')
    def enable_timer(self): self.call('enable_timer')
    def commit_connection(self): self.call('commit_connection')
    def verify_cycle(self): self.call('verify_cycle'); return {'job_id': 'fixture', 'zone_count': 172}
    def abort_lock(self): self.call('abort_lock')
    def disable_timer(self): self.call('disable_timer')
    def disable_windows(self): self.call('disable_windows')
    def finish(self, result): self.call('finish')

class ActivationCoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.coordinator = load_coordinator()
        self.assertIsNotNone(self.coordinator, 'activation coordinator is not implemented')

    def test_success_follows_backup_lock_enable_commit_and_verification(self):
        backend = FakeBackend()
        result = self.coordinator.run_sequence(backend)
        self.assertEqual(result, {'job_id': 'fixture', 'zone_count': 172})
        self.assertEqual(backend.events, ['preflight', 'reserve', 'lock_and_backup', 'recheck_windows', 'enable_windows',
            'enable_timer', 'commit_connection', 'verify_cycle', 'finish'])

    def test_failures_stop_and_compensate_even_when_change_outcome_is_unknown(self):
        for failure, expected in {
            'preflight': [], 'reserve': [], 'lock_and_backup': ['abort_lock'],
            'recheck_windows': ['abort_lock'],
            'enable_windows': ['disable_windows', 'abort_lock'],
            'enable_timer': ['disable_timer', 'disable_windows', 'abort_lock'],
            'commit_connection': ['disable_timer', 'disable_windows', 'abort_lock'],
            'verify_cycle': ['disable_timer', 'disable_windows', 'abort_lock'],
            'finish': ['disable_timer', 'disable_windows', 'abort_lock'],
        }.items():
            with self.subTest(failure=failure):
                backend = FakeBackend(failure)
                with self.assertRaises(RuntimeError): self.coordinator.run_sequence(backend)
                self.assertEqual(backend.events[backend.events.index(failure)+1:], expected)
                self.assertEqual(backend.events.count(failure), 1)

    def test_cleanup_continues_when_one_disable_fails(self):
        backend = FakeBackend('verify_cycle')
        def failing_timer():
            backend.events.append('disable_timer')
            raise RuntimeError('timer_state_unknown')
        backend.disable_timer = failing_timer
        output = io.StringIO()
        with contextlib.redirect_stderr(output), self.assertRaises(RuntimeError):
            self.coordinator.run_sequence(backend)
        self.assertEqual(backend.events[-3:], ['disable_timer', 'disable_windows', 'abort_lock'])
        self.assertIn('DNS9_ACTIVATION_CLEANUP_UNKNOWN=disable_timer', output.getvalue())

    def test_interrupt_after_remote_enable_stops_future_schedules(self):
        backend = FakeBackend()
        def interrupt():
            backend.events.append('enable_windows')
            raise KeyboardInterrupt()
        backend.enable_windows = interrupt
        with self.assertRaises(KeyboardInterrupt): self.coordinator.run_sequence(backend)
        self.assertEqual(backend.events[-2:], ['disable_windows', 'abort_lock'])

    def test_cycle_acceptance_requires_all_authoritative_evidence(self):
        evidence = {'job_id': 'a' * 32, 'state': 'succeeded', 'attempts': 1,
                    'zone_count': 172, 'old_jobs_expired': 2,
                    'timer_enabled': True, 'timer_active': True,
                    'windows_enabled': True, 'windows_ran': True, 'service_ran': True}
        self.coordinator.validate_cycle_evidence(evidence)
        for key, value in [('job_id', 'bad'), ('state', 'pending'), ('attempts', 2),
                           ('zone_count', -1), ('old_jobs_expired', 1),
                           ('timer_enabled', False), ('timer_active', False),
                           ('windows_enabled', False), ('windows_ran', False), ('service_ran', False)]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.coordinator.validate_cycle_evidence({**evidence, key: value})

    def test_task_reply_must_be_typed_and_match_requested_state(self):
        self.coordinator.validate_task_state({'enabled': True, 'last_run_epoch': 1000}, True)
        for reply in ({'enabled': False, 'last_run_epoch': 1000},
                      {'enabled': 1, 'last_run_epoch': 1000},
                      {'enabled': True, 'last_run_epoch': True},
                      {'enabled': True}, None):
            with self.subTest(reply=reply), self.assertRaises(RuntimeError):
                self.coordinator.validate_task_state(reply, True)

    def test_live_verifier_binds_job_to_new_revision_and_rejects_expired_job_loss(self):
        job = 'a' * 32
        backend = self.coordinator.LiveBackend()
        backend.start_epoch = 2000000000
        backend.revision = 8
        backend.binding = 5
        backend.service_before = 42
        backend.b3 = SimpleNamespace(PILOT_CONNECTION_ID='b' * 32, PILOT_COLLECTOR_ID='c' * 32,
            SERVER='fixture.example', checked_job_id=lambda value:value,
            timer_enabled=lambda:True, timer_active=lambda:True)
        backend.b4 = SimpleNamespace(read_result=lambda db, selected:(172, 'd' * 64))
        backend.task = lambda action, expected:{'enabled':True,'last_run_epoch':2000000001}
        backend.service_start = lambda:43
        queries = []
        def query(sql):
            queries.append(sql)
            if 'autonomous_job_queued' in sql:
                self.assertIn('configuration_revision=8', sql)
                self.assertIn('binding_epoch=5', sql)
                return job
            if "CONCAT(state,'|'" in sql: return 'succeeded|1'
            return '2|2'
        backend.query = query
        result = backend.verify_cycle()
        self.assertEqual(result['job_id'], job)
        self.assertEqual(result['zone_count'], 172)
        backend.query = lambda sql: job if 'autonomous_job_queued' in sql else ('succeeded|1' if "CONCAT(state,'|'" in sql else '1|1')
        with self.assertRaisesRegex(RuntimeError, 'cycle_evidence_invalid'):
            backend.verify_cycle()

    def test_live_verifier_executes_against_real_sql_fixture(self):
        db = sqlite3.connect(':memory:')
        self.addCleanup(db.close)
        db.create_function('CONCAT', -1, lambda *items: ''.join(map(str, items)))
        db.executescript('''CREATE TABLE custom_dns_control_jobs (id TEXT,connection_id TEXT,collector_id TEXT,
            job_type TEXT,configuration_revision INTEGER,binding_epoch INTEGER,created_at INTEGER,state TEXT,
            attempt_count INTEGER,lease_id TEXT,credential_delivered_at INTEGER,result_code TEXT);
            CREATE TABLE custom_dns_control_events (object_id TEXT,action TEXT,actor_id INTEGER);''')
        job = 'a' * 32; conn = 'b' * 32; collector = 'c' * 32
        db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                   (job, conn, collector, 'list_zones', 8, 5, 2000000001, 'succeeded', 1, None, 1, 'ok'))
        db.execute('INSERT INTO custom_dns_control_events VALUES (?,?,?)', (job, 'autonomous_job_queued', 0))
        for old in ('0f5fbf81fc71164cc7c624fc6101925f', 'edfcd75935083e51c4536a390174057e'):
            db.execute('INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                       (old, conn, collector, 'list_zones', 7, 4, 1, 'expired', 0, None, None, 'job_expired'))
        backend = self.coordinator.LiveBackend()
        backend.start_epoch = 2000000000; backend.revision = 8; backend.binding = 5; backend.service_before = 42
        backend.b3 = SimpleNamespace(PILOT_CONNECTION_ID=conn, PILOT_COLLECTOR_ID=collector,
            SERVER='fixture.example', checked_job_id=lambda value:value, timer_enabled=lambda:True, timer_active=lambda:True)
        backend.b4 = SimpleNamespace(read_result=lambda repo, selected:(172, 'd' * 64))
        backend.query = lambda sql: str(db.execute(sql).fetchone()[0])
        backend.task = lambda action, expected:{'enabled':True, 'last_run_epoch':2000000001}
        backend.service_start = lambda:43
        self.assertEqual(backend.verify_cycle()['job_id'], job)

    def test_partial_mutex_output_cannot_block_past_deadline(self):
        read_fd, write_fd = os.pipe()
        reader = os.fdopen(read_fd, 'rb', buffering=0)
        backend = self.coordinator.LiveBackend()
        backend.child = SimpleNamespace(stdout=reader)
        os.write(write_fd, b'partial')
        errors = []
        def read():
            try: backend.child_line(0.05)
            except Exception as exc: errors.append(str(exc))
        thread = threading.Thread(target=read, daemon=True)
        try:
            thread.start(); thread.join(0.5)
            self.assertFalse(thread.is_alive(), 'partial output blocked beyond deadline')
            self.assertEqual(errors, ['activation_mutex_timeout'])
        finally:
            os.close(write_fd)
            thread.join(1)
            reader.close()

if __name__ == '__main__':
    unittest.main()
