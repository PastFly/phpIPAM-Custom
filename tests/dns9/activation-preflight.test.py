#!/usr/bin/env python3
import importlib.util
import pathlib
import sys
import unittest

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

    def test_only_empty_queue_and_no_upcoming_automatic_pruning_is_ready(self):
        activation.validate_queue('0|0|0|0|0')
        for row in ('1|0|0|0|0','0|1|0|0|0','0|0|1|0|0','0|0|0|1|0','0|0|0|0|1','broken'):
            with self.subTest(row=row),self.assertRaises(RuntimeError): activation.validate_queue(row)

    def test_windows_check_contains_no_task_or_file_mutations(self):
        source=(ROOT/'tools/live/dns9-activation-preflight.ps1').read_text().lower()
        for forbidden in ('enable-scheduledtask','disable-scheduledtask','start-scheduledtask',
                'register-scheduledtask','unregister-scheduledtask','set-scheduledtask',
                'remove-item','set-content','new-item','invoke-webrequest'):
            with self.subTest(command=forbidden): self.assertNotIn(forbidden,source)

if __name__=='__main__': unittest.main()
