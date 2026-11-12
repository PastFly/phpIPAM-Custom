#!/usr/bin/env python3
import importlib.util
import pathlib
import sys
import unittest

PATH=pathlib.Path(__file__).resolve().parents[2]/'tools/live/dns9-phase-b3-transport-probe.py'
sys.path.insert(0,str(PATH.parent))
spec=importlib.util.spec_from_file_location('probe',PATH)
probe=importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)

class ProbeTests(unittest.TestCase):
    def test_log_aggregation_only_relevant_post_and_window(self):
        lines=[
          '10.0.0.1 - - [06/Nov/2026:10:39:30 +0300] "POST /app/integrations/dns/machine-control.php HTTP/1.1" 500 0',
          '10.0.0.2 - - [06/Nov/2026:07:39:32 +0000] "POST /phpipam/app/integrations/dns/machine-control.php HTTP/1.1" 401 0',
          '10.0.0.3 - - [06/Nov/2026:07:40:00 +0000] "POST /app/integrations/dns/machine-control.php HTTP/1.1" 200 0',
          '10.0.0.4 - - [06/Nov/2026:07:39:31 +0000] "GET /app/integrations/dns/machine-control.php HTTP/1.1" 405 0',
        ]
        self.assertEqual(probe.count_access_lines(lines),{'401':1,'500':1})
    def test_remote_document_allowlist(self):
        valid={'identity':'winrm','default':{'outcome':'http','status':405},'tls12':{'outcome':'network','status':'SecureChannelFailure'}}
        self.assertEqual(probe.validate_remote(valid),valid)
        for bad in ({**valid,'token':'secret'}, {**valid,'default':{'outcome':'http','status':999}}, {**valid,'tls12':{'outcome':'network','status':'secret'}}):
            with self.assertRaises(ValueError): probe.validate_remote(bad)
    def test_probe_does_not_use_token_or_queue(self):
        script=probe.POWERSHELL
        self.assertIn("$request.Method='GET'",script)
        self.assertNotIn('control-token.dpapi',script)
        self.assertNotIn('Invoke-PhpIpamDnsCollectorScheduled',script)
        self.assertNotIn('Authorization',script)
        self.assertNotIn("Method='POST'",script)

if __name__=='__main__': unittest.main()
