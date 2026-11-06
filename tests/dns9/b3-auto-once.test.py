#!/usr/bin/env python3
import contextlib,importlib.util,io,json,pathlib,sys,types,unittest
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
if __name__=='__main__':unittest.main()
