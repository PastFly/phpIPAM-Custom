#!/usr/bin/env python3
import importlib.util,os,pathlib,sys,tempfile,unittest
from unittest.mock import patch
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('hotfix',root/'tools/live/dns9-pdo-hotfix.py')
h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
class HotfixTests(unittest.TestCase):
    def test_replace_preserves_metadata_and_refuses_drift(self):
        with tempfile.TemporaryDirectory() as d:
            path=pathlib.Path(d)/'file.php';path.write_bytes(b'old');path.chmod(0o640)
            before=h.snapshot(path)
            h.atomic_replace(path,b'new',before)
            after=h.snapshot(path)
            self.assertEqual(after['data'],b'new');self.assertEqual(after['mode'],before['mode'])
            self.assertEqual((after['uid'],after['gid'],after['xattrs']),(before['uid'],before['gid'],before['xattrs']))
            with self.assertRaises(RuntimeError):h.atomic_replace(path,b'bad',before)
            self.assertEqual(path.read_bytes(),b'new')
    def test_refuses_symlink_and_hardlink(self):
        with tempfile.TemporaryDirectory() as d:
            root=pathlib.Path(d);p=root/'real';p.write_bytes(b'x');s=root/'link';s.symlink_to(p)
            with self.assertRaises(RuntimeError):h.snapshot(s)
            os.link(p,root/'hard')
            with self.assertRaises(RuntimeError):h.snapshot(p)
    def test_extended_attributes_preserved_when_supported(self):
        if not hasattr(os,'setxattr'):self.skipTest('extended attributes unavailable')
        with tempfile.TemporaryDirectory() as d:
            path=pathlib.Path(d)/'file';path.write_bytes(b'old')
            try:os.setxattr(path,'user.dns9_test',b'kept')
            except OSError:self.skipTest('filesystem does not support user attributes')
            old=h.snapshot(path);h.atomic_replace(path,b'new',old)
            self.assertEqual(os.getxattr(path,'user.dns9_test'),b'kept')
    def test_fake_response_sanitization(self):
        self.assertEqual(h.parse_response(b'{"ok":false,"error":"control_unavailable"}\nDNS9_HTTP:503\n'),(503,'control_unavailable'))
        self.assertEqual(h.parse_response(b'{"error":"secret-value"}\nDNS9_HTTP:400\n'),(400,'other'))
        with self.assertRaises(ValueError):h.parse_response(b'secret')
    def test_verification_failure_restores_both_files(self):
        with tempfile.TemporaryDirectory() as d:
            root=pathlib.Path(d); first=root/'first';second=root/'second'
            first.write_bytes(b'old-a');second.write_bytes(b'old-b')
            changes=[(first,h.snapshot(first),b'new-a'),(second,h.snapshot(second),b'new-b')]
            def fail():raise RuntimeError('injected_failure')
            with self.assertRaisesRegex(RuntimeError,'apply_failed_rolled_back'):h.apply_changes(changes,fail)
            self.assertEqual(first.read_bytes(),b'old-a');self.assertEqual(second.read_bytes(),b'old-b')
    def test_bounded_wait_for_php_opcode_refresh(self):
        with patch.object(h,'fake_poll',side_effect=[(503,'control_unavailable'),(401,'collector_auth_failed')]) as poll, patch.object(h.time,'sleep'):
            self.assertEqual(h.wait_for_fixed_response(None),(401,'collector_auth_failed'))
            self.assertEqual(poll.call_count,2)
    def test_failure_after_replace_still_restores_original(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'file';p.write_bytes(b'old');old=h.snapshot(p);original=h.atomic_replace
            calls=[]
            def injected(path,data,state):
                result=original(path,data,state);calls.append(data)
                if len(calls)==1:raise OSError('post_replace_failure')
                return result
            with patch.object(h,'atomic_replace',side_effect=injected):
                with self.assertRaisesRegex(RuntimeError,'apply_failed_rolled_back'):
                    h.apply_changes([(p,old,b'new')],lambda:None)
            self.assertEqual(p.read_bytes(),b'old');self.assertEqual(calls,[b'new',b'old'])
if __name__=='__main__':unittest.main()
