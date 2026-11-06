#!/usr/bin/env python3
import importlib.util
import pathlib
import sys
import tempfile
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('ui',ROOT/'tools/live/dns9-ui-deploy.py')
ui=importlib.util.module_from_spec(spec); spec.loader.exec_module(ui)

class UiDeployTests(unittest.TestCase):
    def test_only_known_baselines_can_be_replaced(self):
        import hashlib
        old=b'known old'; new=b'new'
        allowed={hashlib.sha256(old).hexdigest()}
        ui.validate_target(old,new,allowed)
        with self.assertRaises(RuntimeError): ui.validate_target(b'unknown',new,allowed)
        with self.assertRaises(RuntimeError): ui.validate_target(old,old,allowed)

    def test_guard_is_exclusive_and_source_bound(self):
        with tempfile.TemporaryDirectory() as temp:
            path=pathlib.Path(temp)/'guard'
            ui.reserve(path,'a'*40)
            self.assertEqual(path.read_text(),'a'*40+'\n')
            with self.assertRaises(FileExistsError): ui.reserve(path,'a'*40)

if __name__=='__main__': unittest.main()
