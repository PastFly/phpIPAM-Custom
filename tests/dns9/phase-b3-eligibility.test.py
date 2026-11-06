#!/usr/bin/env python3
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools/live'))
spec=importlib.util.spec_from_file_location('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
b3=importlib.util.module_from_spec(spec); spec.loader.exec_module(b3)

class PilotEligibilityTests(unittest.TestCase):
    def test_expected_unexpired_pilot_is_eligible(self):
        row='test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|1'
        with patch.object(b3,'one',return_value=row):
            self.assertEqual(b3.pilot_ttl(),600)
    def test_expired_wrong_identity_and_other_jobs_are_refused(self):
        rows=[
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|-1|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|179|1',
            'list_zones|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|1',
            'test_connection|wrong|33b6fade9323f15aa7b1f56a8a865a9d|600|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|wrong|600|1',
            'test_connection|f57057d6677e1ca7a032aab8b68796ac|33b6fade9323f15aa7b1f56a8a865a9d|600|2',
            'invalid',
        ]
        for row in rows:
            with self.subTest(row=row), patch.object(b3,'one',return_value=row), self.assertRaises(RuntimeError):
                b3.pilot_ttl()

if __name__=='__main__': unittest.main()
