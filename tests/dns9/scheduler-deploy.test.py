#!/usr/bin/env python3
import importlib.util
import pathlib
import json
import sys
import types
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('deploy',ROOT/'tools/live/dns9-scheduler-deploy.py')
deploy=importlib.util.module_from_spec(spec); spec.loader.exec_module(deploy)

class SchedulerDeployTests(unittest.TestCase):
    def test_only_expected_files_are_updated(self):
        targets=[path for _,path,_ in deploy.TARGETS]
        self.assertEqual(targets,[
            'functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php',
            'functions/classes/class.IntegrationDnsAutonomousScheduler.php',
            'functions/scripts/dns-autonomous-scheduler.php',
            'functions/classes/class.IntegrationDnsAdminView.php'])
        self.assertEqual(len(targets),len(set(targets)))

    def test_active_or_unknown_timer_refuses(self):
        class B3:
            @staticmethod
            def timer_enabled(): return True
            @staticmethod
            def timer_active(): return False
        with self.assertRaisesRegex(RuntimeError,'timer_changed'): deploy.check_timer(B3())

    def test_preflight_requires_exact_unchanged_result_and_disabled_connection(self):
        marker=[]
        b3=types.SimpleNamespace(validate_followup=lambda *a:marker.append('b3'),_checked_guard=lambda *a:marker.append('guard'),
            timer_enabled=lambda:False,timer_active=lambda:False,PILOT_CONNECTION_ID='a'*32,one=lambda q:'1')
        ui=types.SimpleNamespace(B3_SHA='b'*40,B4_SHA='c'*40,JOB='d'*32,PAYLOAD_SHA='e'*64)
        b4=types.SimpleNamespace(read_result=lambda *a:(172,ui.PAYLOAD_SHA))
        receipt={'source_sha':deploy.UI_SHA,'job_id':ui.JOB,'files':[{}, {}, {}, {}]}
        pdo=types.SimpleNamespace(marker_doc=lambda *a:None,snapshot=lambda *a:{'uid':0,'gid':0,'mode':0o600,'data':json.dumps(receipt).encode()},
            BOUNDARY={},fake_poll=lambda *a:(401,'collector_auth_failed'))
        post=types.SimpleNamespace(local_process_state=lambda:{'matching_processes':0,'unreadable':0})
        with patch.object(deploy,'no_scheduler_process'):
            deploy.preflight(b3,b4,pdo,ui,post)
            b4.read_result=lambda *a:(171,ui.PAYLOAD_SHA)
            with self.assertRaisesRegex(RuntimeError,'zone_result_changed'): deploy.preflight(b3,b4,pdo,ui,post)
        self.assertIn('b3',marker)

if __name__=='__main__': unittest.main()
