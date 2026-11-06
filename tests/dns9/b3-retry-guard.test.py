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
    def test_followup_retains_both_previous_guards_and_is_single_use(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp);old=root/'dns9-b3-live-attempt-global'
            old.write_text(b3.PREVIOUS_ATTEMPT_SHA+'\n');old.chmod(0o600)
            b3.reserve_retry(root,b3.PREVIOUS_RETRY_SHA,os.getuid(),os.getgid())
            b3.reserve_followup(root,'b'*40,os.getuid(),os.getgid())
            b3.validate_followup(root,'b'*40,os.getuid(),os.getgid())
            self.assertEqual(old.read_text(),b3.PREVIOUS_ATTEMPT_SHA+'\n')
            self.assertEqual((root/b3.RETRY_GUARD).read_text(),b3.PREVIOUS_RETRY_SHA+'\n')
            with self.assertRaises(RuntimeError):b3.reserve_followup(root,'b'*40,os.getuid(),os.getgid())
            with self.assertRaises(RuntimeError):b3.validate_followup(root,'c'*40,os.getuid(),os.getgid())
if __name__=='__main__': unittest.main()
