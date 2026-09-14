#!/usr/bin/env python3
"""No service/process is started; exercise the launcher's pause predicates and source contract."""
import copy
import hashlib
import importlib.util
from pathlib import Path
root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('provider_probe', root / 'tools/phase5g4-provider-probe.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
assert m.PHP_SHA256 == hashlib.sha256((root / 'tools/phase5g4-provider-probe.php').read_bytes()).hexdigest()
valid = {
    m.UNITS[0]: {'LoadState': 'loaded', 'ActiveState': 'active', 'SubState': 'running'},
    m.UNITS[1]: {'LoadState': 'loaded', 'ActiveState': 'inactive', 'UnitFileState': 'disabled'},
    m.UNITS[2]: {'LoadState': 'loaded', 'ActiveState': 'failed', 'MainPID': '0'},
}
m.enforce_units(valid)
for unit, key, value in [(0, 'ActiveState', 'failed'), (0, 'SubState', 'dead'), (1, 'ActiveState', 'active'),
        (1, 'UnitFileState', 'enabled'), (2, 'MainPID', '1234'), (2, 'ActiveState', 'activating'), (2, 'LoadState', 'not-found')]:
    data = copy.deepcopy(valid); data[m.UNITS[unit]][key] = value
    try: m.enforce_units(data)
    except RuntimeError: pass
    else: raise AssertionError('unsafe_unit_state_accepted')
wire = (root / 'tools/phase5g4-provider-probe.py').read_text()
assert "r.receipt['stage'] != 'paused'" in wire and 'r.verify_resources()' in wire and 'r.assert_app()' in wire
assert 'p.wait(timeout=190)' in wire and 'os.killpg' in wire and 'fcntl.LOCK_EX | fcntl.LOCK_NB' in wire
for action in ['r.activate(', 'r.rollback(', 'r.prepare(', 'start_worker(', 'stop_worker(', 'start_web(', 'stop_web(', '.write_text(', '.write_bytes(']:
    assert action not in wire, action
print('PHASE5G4_TARGETED_PROBE_PAUSE_AND_SOURCE_GUARDS_OK')
