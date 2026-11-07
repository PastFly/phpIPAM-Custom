#!/usr/bin/env python3
import contextlib,importlib.util,io,json,pathlib,sys,tempfile,types,unittest
from unittest.mock import patch
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('auto',root/'tools/live/dns9-b3-auto-once.py')
auto=importlib.util.module_from_spec(spec); spec.loader.exec_module(auto)
class AutoTests(unittest.TestCase):
    def diag(self,**changes):
        doc={'permanent_task_state':'Disabled','pilot_task_count':0,'collector_process_count':0,'powershell_unknown_commandline_count':0,'status_epoch_consistent':True,'status':{'state':'error','result_code':'dns9_runtime_error','updated_at':1793950771}}
        doc.update(changes)
        def main(): print('DNS9_PHASE_B3_DIAG_JSON='+json.dumps(doc))
        return types.SimpleNamespace(getpass=types.SimpleNamespace(getpass=lambda p:'untouched'),main=main)
    def test_preflight_refuses_running_task_and_changed_status(self):
        auto.windows_preflight(self.diag(),'hidden')
        for kwargs in ({'collector_process_count':1},{'permanent_task_state':'Ready'},{'status_epoch_consistent':False},{'status':{'state':'idle'}}):
            with self.assertRaises(RuntimeError): auto.windows_preflight(self.diag(**kwargs),'hidden')
    def test_cli_output_is_strictly_validated(self):
        with patch.object(auto.subprocess,'run',return_value=types.SimpleNamespace(returncode=0,stdout='DNS9_CLI_JSON={"actor_id":17,"job_id":"'+('a'*32)+'"}\n')):
            self.assertEqual(auto.run_cli('--queue')['actor_id'],17)
        with patch.object(auto.subprocess,'run',return_value=types.SimpleNamespace(returncode=0,stdout='DNS9_CLI_JSON={"actor_id":17,"job_id":"wrong"}\n')):
            with self.assertRaises(RuntimeError):auto.run_cli('--queue')
        with self.assertRaises(ValueError):auto.run_cli('--other')
    def test_reservation_precedes_single_job_and_single_run(self):
        events=[]
        with tempfile.TemporaryDirectory() as temp:
            root=pathlib.Path(temp); (root/'EXPECTED_SHA').write_text('a'*40)
            def call_cli(mode):
                events.append(mode)
                return {'actor_id':17} if mode=='--preflight' else {'actor_id':17,'job_id':'b'*32}
            def run_b3(**kwargs):
                events.append('run')
                self.assertEqual(kwargs['job_override'],'b'*32)
                self.assertTrue(kwargs['reserved_retry'])
            b3=types.SimpleNamespace(reserve_retry=lambda *x:events.append('reserve'),main=run_b3,validate_retry=lambda *x:events.append('validate'))
            with patch.object(auto,'ROOT',root), patch.object(auto.os,'geteuid',return_value=0), \
                 patch.object(auto,'module',side_effect=lambda name,path:b3 if name=='phase_b3' else object()), \
                 patch.object(auto,'local_preflight',side_effect=lambda *x:events.append('local')), \
                 patch.object(auto,'windows_preflight',side_effect=lambda *x:events.append('windows')), \
                 patch.object(auto,'run_cli',side_effect=call_cli), patch.object(auto,'private_plan',side_effect=lambda *x:root), \
                 patch.object(auto,'ensure_ntlm_md4_runtime',return_value='native'), \
                 patch.object(auto.getpass,'getpass',return_value='sensitive-password'), contextlib.redirect_stdout(io.StringIO()) as output:
                auto.main()
            self.assertEqual(events,['local','--preflight','windows','local','--preflight','reserve','--queue','run','validate'])
            self.assertNotIn('sensitive-password',output.getvalue())
if __name__=='__main__':unittest.main()
