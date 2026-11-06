#!/usr/bin/env python3
import importlib.util
import contextlib
import io
import json
import sqlite3
import sys
import unittest
import tempfile
import types
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('b4',ROOT/'tools/live/dns9-b4-list-zones.py')
b4=importlib.util.module_from_spec(spec); spec.loader.exec_module(b4)

class ListZonesTests(unittest.TestCase):
    def test_payload_shape_and_count(self):
        zones=[{'name':'example.local','zone_type':'Primary','ds_integrated':True,'reverse_lookup':False}]
        self.assertEqual(b4.validate_payload(json.dumps({'zones':zones}).encode()),1)
        self.assertEqual(b4.validate_payload(b'{"zones":[]}'),0)
        for bad in [b'{}', b'{"zones":[{"name":"x"}]}', b'{"zones":[],"extra":1}', b'x'*65537]:
            with self.subTest(payload=bad[:60]), self.assertRaises(ValueError): b4.validate_payload(bad)

    def test_queue_policy_prevents_unrelated_expiry_and_pruning(self):
        db=sqlite3.connect(':memory:'); self.addCleanup(db.close)
        db.create_function('UNIX_TIMESTAMP',0,lambda:100000)
        db.execute('CREATE TABLE custom_dns_control_jobs (id TEXT,state TEXT,expires_at INTEGER,lease_expires_at INTEGER,finished_at INTEGER,updated_at INTEGER)')
        class B3:
            @staticmethod
            def one(query): return str(db.execute(query).fetchone()[0])
        b4.check_queue_safety(B3())
        db.execute("INSERT INTO custom_dns_control_jobs VALUES ('a','pending',100200,NULL,NULL,100000)")
        with self.assertRaises(RuntimeError): b4.check_queue_safety(B3())
        db.execute("UPDATE custom_dns_control_jobs SET state='succeeded',finished_at=100000")
        b4.check_queue_safety(B3())
        db.execute('UPDATE custom_dns_control_jobs SET finished_at=1')
        with self.assertRaises(RuntimeError): b4.check_queue_safety(B3())

    def test_one_job_and_guard_order(self):
        events=[]
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); (root/'EXPECTED_SHA').write_text('a'*40)
            def cli(mode):
                events.append(mode)
                return {'actor_id':17} if mode=='--b4-preflight' else {'actor_id':17,'job_id':'b'*32}
            b3=types.SimpleNamespace(main=lambda **kwargs:events.append(('run',kwargs['reserved_list_zones'],kwargs['job_override'])),
                validate_followup=lambda *a:events.append('validate'),_checked_guard=lambda *a:events.append('check_guard'))
            prior=types.SimpleNamespace(remote_preflight=lambda *a,**kw:events.append(('remote',kw['expect_success'])),RECEIPT='receipt',USER='user')
            real_spec=importlib.util.spec_from_file_location
            def fake_spec(name,path):
                if name in ('b3','post','pdo','auto','prior'):
                    obj=b3 if name=='b3' else prior if name=='prior' else object()
                    class Loader:
                        def create_module(self,spec): return obj
                        def exec_module(self,obj): pass
                    return importlib.util.spec_from_loader(name,Loader())
                return real_spec(name,path)
            with patch.object(b4,'ROOT',root),patch.object(b4.os,'geteuid',return_value=0), \
                 patch('importlib.util.spec_from_file_location',side_effect=fake_spec), \
                 patch.object(b4,'local_preflight',side_effect=lambda *a:events.append('local')), \
                 patch.object(b4,'run_cli',side_effect=cli),patch.object(b4,'reserve',side_effect=lambda sha:events.append('reserve')), \
                 patch.object(b4,'check_queue_safety',side_effect=lambda *a:events.append('queue_safety')), \
                 patch.object(b4,'read_result',return_value=(2,'c'*64)),patch.object(b4,'ensure_ntlm_md4_runtime',return_value='native'), \
                 patch.object(b4.getpass,'getpass',return_value='secret'),contextlib.redirect_stdout(io.StringIO()) as output:
                b4.main()
            self.assertEqual(events,['local','--b4-preflight',('remote',True),'local','--b4-preflight','reserve','--b4-queue','queue_safety',('run',True,'b'*32),'validate','check_guard'])
            self.assertNotIn('secret',output.getvalue())
            self.assertIn('DNS9_B4_ZONE_COUNT=2',output.getvalue())

if __name__=='__main__': unittest.main()
