#!/usr/bin/env python3
"""Source scope assertions, not a substitute for executable PHP/MariaDB or browser tests."""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[2]
classes=ROOT/'layers/dns1/functions/classes'
runtime=subprocess.check_output(['php','-r', 'foreach(array_slice($argv,1) as $p) foreach(token_get_all(file_get_contents($p)) as $t) { if(is_array($t)) { if(!in_array($t[0],[T_COMMENT,T_DOC_COMMENT])) echo $t[1]; } else echo $t; }', *map(str,sorted(classes.glob('*.php')))],text=True)
service=(classes/'class.IntegrationDnsConfigService.php').read_text()
controller=(classes/'class.IntegrationDnsAdminController.php').read_text()
sql=(ROOT/'database/dns1/001-configuration.sql').read_text()
js=(ROOT/'layers/dns1/app/admin/integrations/dns/config.js').read_text()
n=0
def check(value,label):
    global n
    n+=1
    if not value: raise AssertionError(label)
for token in ['srv-dc01.rbank.local','srv-dhcp01.rbank.local','shell_exec(', 'exec(', 'system(', 'curl_exec(', 'SoapClient', 'QueryPerf', 'getenv(', '$_REQUEST', '$_POST', 'IntegrationVmware']:
    # PDO uses exec in tests/provisioning only; runtime repository uses query/prepare, no DDL.
    check(token not in runtime,'no runtime dependency/input: '+token)
check('CREATE TABLE' not in runtime and 'ALTER TABLE' not in runtime,'no runtime DDL')
check('decrypt' not in controller and "'open'" not in controller,'no admin secret read action')
check(controller.index("'csrf_valid'")<controller.index('($this->factory)()'),'auth before service construction')
check(controller.index("'admin'")<controller.index('($this->factory)()'),'admin before construction')
check("'Cache-Control'=>'no-store'" in controller,'secret-bearing responses not cached')
check("'enabled'=>false" in service and "'collector_id'=>null" in service,'configuration only')
check('hash_equals' not in service,'service does not invent machine authentication')
check('FOR UPDATE' in runtime and 'rollBack()' in runtime,'PDO lock and rollback')
check('beginTransaction()' in runtime and 'inTransaction()' in runtime,'PDO owns transaction')
check(sql.count('CREATE TABLE ')==4 and sql.count('ENGINE=InnoDB')==4,'four additive InnoDB tables')
for forbidden in ['DROP TABLE','TRUNCATE','ALTER TABLE','ipaddresses','custom_integrations','vmware']:
    check(forbidden not in sql,'schema scope: '+forbidden)
check('CHECK (enabled = 0 AND collector_id IS NULL)' in sql,'schema prohibits premature collection')
check("redirect: 'error'" in js and "cache: 'no-store'" in js,'browser fetch boundaries')
check("url.origin !== global.location.origin" in js and "url.protocol !== 'https:'" in js,'same-origin verified transport contract')
check("passwordInput.value = ''" in js,'input cleared after submission')
check('innerHTML' not in js,'no response HTML interpolation')
check('setInterval' not in js and 'setTimeout' not in js,'no automatic retries or polling')
check(not (ROOT/'layers/dns1/app/admin/integrations/dns/action.php').exists(),'no unreviewed live HTTP bootstrap')
print(f'DNS1_BOUNDARY_ASSERTIONS={n}')
print('DNS1_BOUNDARY_TESTS_OK=1')
