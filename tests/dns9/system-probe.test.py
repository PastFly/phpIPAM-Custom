#!/usr/bin/env python3
import importlib.util,pathlib,sys,unittest
from unittest.mock import patch
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('probe',root/'tools/live/dns9-system-probe.py')
p=importlib.util.module_from_spec(spec); spec.loader.exec_module(p)
class ProbeTests(unittest.TestCase):
    def test_probe_reports_only_bounded_fields(self):
        good={'phase':'complete','error':'none','token_valid':True,'token_reference_match':True,'http_status':400,'http_error_marker':True,'web_status':'ProtocolError'}
        self.assertEqual(p.validate_result(good),good)
        for bad in ({**good,'token':'secret'}, {**good,'error':'secret'}, {**good,'http_status':999}, {**good,'phase':'untrusted'}, {**good,'token_valid':'true'}):
            with self.assertRaises(ValueError): p.validate_result(bad)
    def test_real_token_never_sent_or_logged(self):
        source=(root/'tools/live/dns9-system-probe.ps1').read_text()
        self.assertIn("diagnostic_unsupported",source)
        self.assertIn("'Bearer '+$fakeToken",source)
        self.assertNotIn("'Bearer '+$plain",source)
        self.assertNotIn("action='poll'",source)
        self.assertNotIn('Invoke-PhpIpamDnsCollectorScheduled.ps1',source)
    def test_reference_requires_exact_token_and_digest(self):
        token='dnsctl1.'+'1'*32+'.'+'A'*43
        result=p.reference_hmac(token,p.control_digest(token),bytes(range(32)))
        self.assertRegex(result,r'^[0-9a-f]{64}$')
        with self.assertRaises(ValueError):p.reference_hmac(token,'0'*64,bytes(range(32)))
        with self.assertRaises(ValueError):p.reference_hmac('invalid','0'*64,bytes(range(32)))
    def test_control_only_removes_finished_task(self):
        source=(root/'tools/live/dns9-system-probe-control.ps1').read_text()
        self.assertIn("[string]$task.State -ne 'Running'",source)
        self.assertIn('$unknown.Count -eq 0 -and $active.Count -eq 0',source)
        self.assertIn("UserId 'SYSTEM'",source)
        self.assertNotIn('Enable-ScheduledTask',source)
        self.assertNotIn('Start-ScheduledTask',source)
if __name__=='__main__':unittest.main()
