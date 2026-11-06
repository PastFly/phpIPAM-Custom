#!/usr/bin/env python3
import importlib.util, os, pathlib, sys, tempfile, unittest
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('b3',root/'tools/live/dns9-phase-b3-first-cycle.py')
b3=importlib.util.module_from_spec(spec); spec.loader.exec_module(b3)
class GuardTest(unittest.TestCase):
    def test_only_one_retry_preserves_original(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp); old=root/'dns9-b3-live-attempt-global'
            old.write_text(b3.PREVIOUS_ATTEMPT_SHA+'\n'); old.chmod(0o600)
            b3.reserve_retry(root,'a'*40,os.getuid(),os.getgid())
            self.assertEqual(old.read_text(),b3.PREVIOUS_ATTEMPT_SHA+'\n')
            b3.validate_retry(root,'a'*40,os.getuid(),os.getgid())
            with self.assertRaises(RuntimeError): b3.reserve_retry(root,'a'*40,os.getuid(),os.getgid())
            with self.assertRaises(RuntimeError): b3.validate_retry(root,'b'*40,os.getuid(),os.getgid())
    def test_invalid_previous_guard_cannot_reserve(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp); (root/'dns9-b3-live-attempt-global').symlink_to('/dev/null')
            with self.assertRaises(RuntimeError): b3.reserve_retry(root,'a'*40,os.getuid(),os.getgid())
            self.assertFalse((root/'dns9-b3-live-attempt-retry-global').exists())
if __name__=='__main__': unittest.main()
