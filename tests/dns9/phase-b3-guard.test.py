#!/usr/bin/env python3
import importlib.util
import multiprocessing
import sys
import tempfile
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
b3=importlib.util.module_from_spec(spec)
spec.loader.exec_module(b3)

def reserve(root, sha, start, results):
    start.wait()
    try:
        b3.reserve_attempt(Path(root),sha)
        results.put('reserved')
    except (RuntimeError, FileExistsError):
        results.put('blocked')

class GuardTests(unittest.TestCase):
    def test_cross_sha_concurrent_attempts_reserve_at_most_once(self):
        with tempfile.TemporaryDirectory() as root:
            start=multiprocessing.Event()
            results=multiprocessing.Queue()
            workers=[multiprocessing.Process(target=reserve,args=(root,c*40,start,results)) for c in 'ab']
            for worker in workers: worker.start()
            start.set()
            values=[results.get(timeout=5) for _ in workers]
            for worker in workers:
                worker.join(5)
                self.assertEqual(worker.exitcode,0)
            self.assertCountEqual(values,['reserved','blocked'])
            guards=list(Path(root).glob('dns9-b3-live-attempt-*'))
            self.assertEqual(len(guards),1)
            self.assertIn(guards[0].read_text(),['a'*40+'\n','b'*40+'\n'])

    def test_old_attempt_is_never_removed_or_overwritten(self):
        with tempfile.TemporaryDirectory() as root:
            old=Path(root)/('dns9-b3-live-attempt-'+'c'*40)
            old.write_text('evidence\n')
            with self.assertRaises(RuntimeError): b3.reserve_attempt(Path(root),'a'*40)
            self.assertEqual(old.read_text(),'evidence\n')
            self.assertEqual(len(list(Path(root).glob('dns9-b3-live-attempt-*'))),1)

if __name__=='__main__': unittest.main()
