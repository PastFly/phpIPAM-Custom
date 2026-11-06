#!/usr/bin/env python3
import contextlib,importlib.util,io,pathlib,sys,tempfile,types,unittest
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
