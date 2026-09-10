#!/usr/bin/env python3
"""Isolated PHP endpoint cases; no phpIPAM DB or vCenter connections."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = 'app/integrations/vmware/object-folders-result.php'
with tempfile.TemporaryDirectory() as tmp:
    target = Path(tmp)
    dst = target / ENDPOINT
    dst.parent.mkdir(parents=True)
    shutil.copyfile(ROOT / 'layers/phase5f332' / ENDPOINT, dst)
    classes = target / 'functions/classes'
    classes.mkdir(parents=True)
    (target / 'functions/functions.php').write_text('''<?php
function disable_php_errors() {}
class Database_PDO {}
class FixtureCrypto { function csrf_cookie($a,$b,$c) { return $c === 'good'; } }
class User {
  public $Crypto;
  function __construct($d) {$this->Crypto = new FixtureCrypto;}
  function check_user_session() { if (getenv('DENY_SESSION')) {http_response_code(401); echo '{"ok":false}'; exit;} }
  function is_admin($unused) {return !getenv('DENY_ADMIN');}
}
$POST = json_decode(getenv('POST_DATA'));
''')
    stubs = {
        'IntegrationVmwareVcenter': '',
        'IntegrationSecretCipher': 'static function from_keyring_file() {return null;}',
        'IntegrationVmwareVcenterConfigStore': '''function __construct($d,$c) {} function load_runtime_config($id,$enabled) {
            if ($id !== 1 || $enabled !== true) throw new Exception('SECRET disabled');
            if (getenv('DISABLED')) throw new Exception('SECRET disabled');
            return ['endpoint'=>'https://test.invalid','username'=>'fixture','password'=>'SECRET','verify_tls'=>false];
        }''',
        'IntegrationVmwareVcenterDirectTransport': 'function __construct($n=0) {}',
        'IntegrationVmwareVcenterDirectRestTransport': '',
        'IntegrationVmwareVcenterObjectFolders': '''function __construct($t) {} function read_inventory($e,$u,$p,$v) {
            if(getenv('THROW_READER')) throw new Exception('SECRET password=raw XML');
            if($v !== false) throw new Exception('saved policy lost');
            return ['ok'=>true,'partial'=>false,'domains'=>['vm'=>['nodes'=>[],'objects'=>[]],'datastore'=>['nodes'=>[],'objects'=>[]]]];
        }''',
        'IntegrationVmwareVcenterDatastore': '''function __construct($t) {} function read_inventory($e,$u,$p,$d,$v) {
            if ($d !== [] || $v !== false) throw new Exception('wrong bulk policy');
            return ['datastores'=>[['external_id'=>'datastore-1','name'=>'X','type'=>'vmfs','capacity_bytes'=>42,'free_space_bytes'=>7,'secret'=>'SECRET']]];
        }'''
    }
    for name, code in stubs.items():
        (classes / ('class.'+name+'.php')).write_text('<?php class '+name+' {'+code+'}')
    runner = target / 'run.php'
    runner.write_text('<?php $_SERVER["REQUEST_METHOD"] = getenv("METHOD") ?: "POST"; require '+json.dumps(str(dst))+'; echo "\\nSTATUS=".http_response_code();')
    import os
    def case(expected, post=None, **flags):
        env = dict(os.environ, POST_DATA=json.dumps(post or {'config_id':'1','csrf_cookie':'good'}), **flags)
        result = subprocess.run(['php', str(runner)], env=env, capture_output=True, text=True, check=True)
        assert 'SECRET' not in result.stdout and 'SECRET' not in result.stderr, result
        if 'STATUS=' not in result.stdout:
            assert expected == 401
            return {}
        body, status = result.stdout.rsplit('\nSTATUS=',1)
        assert int(status) == expected, result.stdout
        return json.loads(body)
    case(403, DENY_ADMIN='1')
    case(401, DENY_SESSION='1')
    case(405, METHOD='GET')
    case(403, {'config_id':'1','csrf_cookie':'wrong'})
    case(400, {'config_id':[],'csrf_cookie':'good'})
    case(400, {'config_id':'1','csrf_cookie':'good','mode':'delete'})
    case(502, DISABLED='1')
    case(502, THROW_READER='1')
    value=case(200)
    assert value['connector_id'] == 1 and value['verify_tls'] is False
    assert value['persistent_inventory_written'] is False and value['vcenter_objects_modified'] is False
    value=case(200, {'config_id':'1','csrf_cookie':'good','mode':'datastores'})
    assert value['datastores'][0]['capacity_bytes'] == 42 and 'secret' not in value['datastores'][0]
print('PHASE5F332_ENDPOINT_FIXTURES_OK')
