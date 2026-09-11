#!/usr/bin/env python3
"""Syntax, dependency pins and HTTP denial ordering. Does not contact vCenter."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
def run(*args, expected=0):
    p = subprocess.run(args, capture_output=True, text=True, timeout=20)
    assert p.returncode == expected, (args, p.returncode, p.stdout, p.stderr)
    return p.stdout
pins = json.loads((ROOT / 'design/phase5g-source-pins.json').read_text())
for path, sha in pins['sources'].items():
    b = (ROOT / path).read_bytes()
    assert hashlib.sha1(b'blob ' + str(len(b)).encode() + b'\0' + b).hexdigest() == sha, path
for file in sorted((ROOT / 'layers/phase5g').rglob('*.php')):
    run('php', '-l', str(file))
run('php', '-l', str(ROOT / 'tools/vmware-inventory-worker.php'))
run('php', str(ROOT / 'tools/vmware-inventory-worker.php'), '--help')
run('php', str(ROOT / 'tools/vmware-inventory-worker.php'), '--tick', '--connector=../1', expected=64)
reader = (ROOT / 'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryReader.php').read_text()
endpoint = (ROOT / 'layers/phase5g/app/integrations/vmware/mirror/read.php').read_text()
for text in [reader, endpoint]:
    for forbidden in ['curl_init(', 'read_topology(', 'read_inventory(', 'read_detail(', '->tick(', 'InventorySource', 'workerRuntime(', 'file_put_contents(', 'mkdir(']:
        assert forbidden not in text, forbidden
assert endpoint.index('check_user_session()') < endpoint.index("$csrf =") < endpoint.index('new IntegrationVmwareInventoryContext(')
assert "'POST'" in endpoint and 'no-store' in endpoint
with tempfile.TemporaryDirectory(prefix='mirror-http-guards-') as d:
    root = Path(d)
    target = root / 'app/integrations/vmware/mirror/read.php'
    target.parent.mkdir(parents=True)
    target.write_text(endpoint)
    (root / 'functions').mkdir()
    (root / 'functions/functions.php').write_text('''<?php
function disable_php_errors() {}
class Database_PDO { function __construct() {} }
class FakeCrypto { function csrf_cookie(...$args) { return getenv('CASE') !== 'csrf'; } }
class User {
 public $Crypto;
 function __construct($db) { $this->Crypto = new FakeCrypto; }
 function check_user_session() { if (getenv('CASE') === 'session') { http_response_code(401); print '{"error":"session_required"}'; exit; } }
 function is_admin($unused) { return getenv('CASE') !== 'admin'; }
}
$POST = (object)['csrf_cookie' => 'fixture', 'config_id' => '1'];
''')
    import os
    for case, method, code, err in [
        ('session', 'POST', 401, 'session_required'), ('admin', 'POST', 403, 'administrative_privileges_required'),
        ('method', 'GET', 405, 'post_required'), ('csrf', 'POST', 403, 'invalid_csrf')]:
        script = "$_SERVER['REQUEST_METHOD']=" + json.dumps(method) + "; register_shutdown_function(function(){echo '|'.http_response_code();}); require " + json.dumps(str(target)) + ';'
        p = subprocess.run(['php', '-r', script], capture_output=True, text=True, env={**os.environ, 'CASE': case}, timeout=10)
        assert p.returncode == 0 and p.stderr == '', p.stderr
        payload, status = p.stdout.rsplit('|', 1)
        assert int(status) == code and json.loads(payload)['error'] == err, p.stdout
print('PHASE5G_SOURCE_PINS=' + str(len(pins['sources'])))
print('PHASE5G_HTTP_GUARDS_LOCAL_ONLY_CONTRACT_OK')
