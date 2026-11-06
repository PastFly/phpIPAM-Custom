#!/usr/bin/env python3
import contextlib,importlib.util,io,pathlib,sqlite3,sys,tempfile,types,unittest
from unittest.mock import patch
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('followup',root/'tools/live/dns9-b3-after-pdo.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)
class FollowupTests(unittest.TestCase):
    def test_unsafe_queue_prevents_automatic_pruning(self):
        queries=[]
        db=types.SimpleNamespace(one=lambda q:(queries.append(q) or '0'),PILOT_COLLECTOR_ID='a'*32,PILOT_CONNECTION_ID='b'*32)
        f.check_queue_safety(db)
        self.assertIn('86400',queries[0]);self.assertIn('lease_expires_at',queries[0]);self.assertIn('job_type',queries[0])
        db.one=lambda q:'1'
        with self.assertRaisesRegex(RuntimeError,'unrelated_queue_or_pruning_risk'):f.check_queue_safety(db)
    def test_queue_safety_allows_own_expired_jobs_but_blocks_other_side_effects(self):
        db=sqlite3.connect(':memory:');self.addCleanup(db.close)
        db.create_function('UNIX_TIMESTAMP',0,lambda:100000)
        db.execute('CREATE TABLE custom_dns_control_jobs (state TEXT,finished_at INTEGER,updated_at INTEGER,expires_at INTEGER,collector_id TEXT,connection_id TEXT,job_type TEXT,lease_expires_at INTEGER)')
        pilot='a'*32;connection='b'*32
        b3=types.SimpleNamespace(one=lambda q:str(db.execute(q).fetchone()[0]),PILOT_COLLECTOR_ID=pilot,PILOT_CONNECTION_ID=connection)
        insert='INSERT INTO custom_dns_control_jobs VALUES (?,?,?,?,?,?,?,?)'
        db.execute(insert,('pending',None,90000,90000,pilot,connection,'test_connection',None))
        db.execute(insert,('pending',None,100000,100500,'other','other','test_connection',None))
        f.check_queue_safety(b3)
        for row in [
            ('pending',None,100000,100200,'other','other','test_connection',None),
            ('succeeded',13000,13000,13000,'other','other','test_connection',None),
            ('running',None,100000,100500,'other','other','test_connection',100200),
        ]:
            db.execute(insert,row)
            with self.assertRaisesRegex(RuntimeError,'unrelated_queue_or_pruning_risk'):f.check_queue_safety(b3)
            db.execute('DELETE FROM custom_dns_control_jobs WHERE rowid=(SELECT MAX(rowid) FROM custom_dns_control_jobs)')
    def test_only_one_new_job_after_guard_reservation(self):
        events=[]
        with tempfile.TemporaryDirectory() as temp:
            root=pathlib.Path(temp);(root/'EXPECTED_SHA').write_text('a'*40)
            def call_cli(mode):
                events.append(mode)
                return {'actor_id':17} if mode=='--preflight' else {'actor_id':17,'job_id':'b'*32}
            def run_b3(**kwargs):
                events.append('run');self.assertEqual(kwargs['job_override'],'b'*32);self.assertTrue(kwargs['reserved_followup'])
            b3=types.SimpleNamespace(reserve_followup=lambda *x:events.append('reserve'),main=run_b3,
                validate_followup=lambda *x:events.append('validate'),SERVER='srv-dc01.rbank.local',
                PILOT_JOB_ID='c'*32,one=lambda q:'3')
            auto=types.SimpleNamespace(run_cli=call_cli)
            def mod(name,path):return b3 if name=='phase_b3' else auto if name=='auto' else object()
            with patch.object(f,'ROOT',root),patch.object(f.os,'geteuid',return_value=0),patch.object(f,'module',side_effect=mod), \
                 patch.object(f,'local_preflight',side_effect=lambda *x:events.append('local')), \
                 patch.object(f,'check_actor_audit',side_effect=lambda *x:events.append('audit')), \
                 patch.object(f,'remote_preflight',side_effect=lambda *x:events.append('remote')), \
                 patch.object(f,'private_plan',side_effect=lambda *x:root), \
                 patch.object(f,'ensure_ntlm_md4_runtime',return_value='native'),patch.object(f.getpass,'getpass',return_value='hidden'), \
                 contextlib.redirect_stdout(io.StringIO()) as out:
                f.main()
            self.assertEqual(events,['local','--preflight','audit','remote','local','--preflight','audit','reserve','--queue','run','validate'])
            self.assertNotIn('hidden',out.getvalue())
if __name__=='__main__':unittest.main()
