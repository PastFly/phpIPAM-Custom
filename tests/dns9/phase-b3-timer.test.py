#!/usr/bin/env python3
import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/live'))
spec = importlib.util.spec_from_file_location('phase_b3', ROOT / 'tools/live/dns9-phase-b3-first-cycle.py')
b3 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b3)

class TimerStateTests(unittest.TestCase):
    def test_only_known_timer_states_are_accepted(self):
        for func, code, output, expected in [
            (b3.timer_enabled, 1, 'disabled\n', False),
            (b3.timer_enabled, 0, 'enabled\n', True),
            (b3.timer_active, 3, 'inactive\n', False),
            (b3.timer_active, 0, 'active\n', True),
        ]:
            with self.subTest(output=output), patch.object(b3.subprocess, 'run', return_value=subprocess.CompletedProcess([], code, output, '')):
                self.assertEqual(func(), expected)

    def test_unknown_or_failed_systemctl_does_not_mean_disabled(self):
        for func, code, output in [
            (b3.timer_enabled, 1, 'not-found\n'),
            (b3.timer_enabled, 1, ''),
            (b3.timer_active, 3, 'failed\n'),
            (b3.timer_active, 1, ''),
        ]:
            with self.subTest(output=output), patch.object(b3.subprocess, 'run', return_value=subprocess.CompletedProcess([], code, output, 'error')):
                with self.assertRaises(RuntimeError):
                    func()

if __name__ == '__main__':
    unittest.main()
