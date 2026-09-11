#!/usr/bin/env python3
"""Validate generated local wrappers and actual authorization rejection before any file access."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('native_generator', ROOT / 'tools/build-phase5g2-native.py')
gen = importlib.util.module_from_spec(spec); spec.loader.exec_module(gen)
outputs = gen.generate()
assert len(outputs) == 21
assert outputs == gen.generate(), 'nondeterministic generation'
controller = (ROOT / 'layers/phase5g2/app/integrations/vmware/mirror/native.php').read_text()
view = (ROOT / 'layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php').read_text()
for content in [controller, view, *(raw.decode() for raw in outputs.values())]:
    for forbidden in ['curl_init(', '->read_topology(', '->read_inventory(', '->read_detail(', '->tick(', 'InventorySource', 'workerRuntime(', 'load_runtime_config(', 'file_put_contents(', 'mkdir(']:
        assert forbidden not in content, forbidden
assert controller.index('check_user_session()') < controller.index("$csrf =") < controller.index('new IntegrationVmwareInventoryContext(')
assert 'session_write_close()' in controller and 'no-store' in controller
with tempfile.TemporaryDirectory(prefix='native-http-') as d:
    root = Path(d)
    for path, data in outputs.items():
        dest = root / path; dest.parent.mkdir(parents=True, exist_ok=True); dest.write_bytes(data)
    native = root / 'app/integrations/vmware/mirror/native.php'; native.write_text(controller)
    (root / 'functions').mkdir()
    (root / 'functions/functions.php').write_text('''<?php
function disable_php_errors() {}
class Database_PDO {}
class FakeCrypto { function csrf_cookie(...$args) { return getenv('CASE') !== 'csrf'; } }
class User {
 public $Crypto;
 function __construct($db) { $this->Crypto = new FakeCrypto; }
 function check_user_session() { if (getenv('CASE') === 'session') { http_response_code(401); print 'session_required'; exit; } }
 function is_admin($unused) { return getenv('CASE') !== 'admin'; }
}
$POST = (object)['csrf_cookie' => 'fixture', 'config_id' => '1'];
''')
    cases = 0
    for relative, data in outputs.items():
        p = subprocess.run(['php', '-l', str(root / relative)], capture_output=True, text=True)
        assert p.returncode == 0, p.stderr
        if relative.endswith('native-views.php'): continue
        for case, method, code, text in [('session', 'POST', 401, 'session_required'), ('admin', 'POST', 403, 'administrative_privileges_required'),
            ('csrf', 'POST', 403, 'invalid_csrf'), ('method', 'GET', 405, 'post_required')]:
            source = "$_SERVER['REQUEST_METHOD']=" + json.dumps(method) + "; register_shutdown_function(function(){echo '|'.http_response_code();}); require " + json.dumps(str(root / relative)) + ';'
            p = subprocess.run(['php', '-r', source], env={**os.environ, 'CASE': case}, capture_output=True, text=True, timeout=10)
            assert p.returncode == 0 and p.stderr == '', (relative, case, p.stderr)
            assert p.stdout.endswith('|' + str(code)) and text in p.stdout, (relative, case, p.stdout)
            cases += 1
    print('PHASE5G2_NATIVE_ROUTE_AUTH_CASES=' + str(cases))
    print('PHASE5G2_LOCAL_ONLY_ROUTES=20')
for path in (ROOT / 'layers/phase5g2').rglob('*.php'):
    subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
if shutil.which('systemd-analyze'):
    subprocess.run(['systemd-analyze', 'verify', '--man=no', str(ROOT / 'deployment/systemd/phpipam-vmware-inventory@1.service'),
        str(ROOT / 'deployment/systemd/phpipam-vmware-inventory@1.timer')], check=True)
print('PHASE5G2_GENERATED_SOURCE_AND_DORMANT_UNITS_OK')
