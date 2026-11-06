#!/usr/bin/env python3
import importlib.util
import pathlib
import sys
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
p=ROOT/'tools/live/dns9-runner-tls-upgrade.py'
spec=importlib.util.spec_from_file_location('upgrade',p)
u=importlib.util.module_from_spec(spec); spec.loader.exec_module(u)

class UpgradeTest(unittest.TestCase):
    def test_control_rejects_live_environment_and_backs_up_atomically(self):
        s=u.control_script('a'*16,{'Invoke-PhpIpamDnsWorkerOnce.ps1':'1'*64,'Invoke-PhpIpamDnsPreview.ps1':'2'*64,'Invoke-PhpIpamDnsCollectorScheduled.ps1':'3'*64},'4'*64)
        self.assertIn("-ne 'Disabled'",s)
        self.assertIn('CommandLine',s)
        self.assertIn('Get-FileHash',s)
        self.assertIn('[IO.File]::Replace($Replacement,$Runner,$Backup)',s)
        self.assertLess(s.index('replacement_acl_mismatch'),s.index('[IO.File]::Replace($Replacement,$Runner,$Backup)'))
        self.assertIn('[IO.FileMode]::CreateNew',s)
        self.assertIn('replacement_acl_mismatch',s)
        self.assertIn('backup_hash_mismatch',s)
        self.assertNotIn('Start-ScheduledTask',s)
        self.assertNotIn('schtasks.exe',s)
        self.assertNotIn('control-token.dpapi',s)
    def test_malformed_hashes_are_rejected(self):
        with self.assertRaises(ValueError): u.control_script('x'*16,{},'4'*64)
        with self.assertRaises(ValueError): u.control_script('a'*16,{},'not-a-hash')
    def test_bootstrap_receipt_copy_preserves_original(self):
        original={'stage':'installed_disabled','package':{u.RUNNER_KEY:{'sha256':'a'*64,'bytes':10},'other':{'sha256':'b'*64,'bytes':20}}}
        result=u.updated_receipt(original,b'new-runner','c'*16)
        self.assertEqual(original['package'][u.RUNNER_KEY]['sha256'],'a'*64)
        self.assertEqual(result['package'][u.RUNNER_KEY]['bytes'],10)
        self.assertNotEqual(result['package'][u.RUNNER_KEY]['sha256'],'a'*64)
        self.assertEqual(result['upgrade']['nonce'],'c'*16)

if __name__=='__main__': unittest.main()
