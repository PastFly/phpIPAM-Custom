#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
ROUTE=ROOT/'layers/dns5/app/integrations/dns/machine-control.php'
n=0
def check(v,label):
    global n
    n+=1
    if not v: raise AssertionError(label)

check(ROUTE.is_file(),'machine route source exists')
src=ROUTE.read_text()

for required in [
    "require_once(dirname(__FILE__).'/../../../functions/functions.php')",
    'new Common_functions',
    '->isHttps()',
    "REQUEST_METHOD",
    "CONTENT_TYPE",
    "HTTP_AUTHORIZATION",
    "REDIRECT_HTTP_AUTHORIZATION",
    "php://input",
    "stream_get_contents($stream,73729)",
    "new IntegrationDnsCollectorMachineHttpController",
    "new Database_PDO",
    "new IntegrationDnsCollectorControlPhpIpamRepository($Database)",
    "new IntegrationDnsMachineContextReader($Database)",
    "new IntegrationDnsCollectorMachineService",
    "[$context,'current']"
]:
    check(required in src,'required machine route '+required)

for forbidden in [
    'new User(','check_user_session','csrf_cookie','$_POST','$POST','$_GET','$_COOKIE','$_REQUEST',
    'IntegrationDnsCipher','dns-credentials.key','secret_envelope','profile_id','password',
    'CREATE TABLE','ALTER TABLE','DROP TABLE','shell_exec','exec(','system(','curl_exec',
    'Get-DnsServer','PowerShell','srv-dc01.rbank.local','srv-dhcp01.rbank.local'
]:
    check(forbidden not in src,'machine route forbidden '+forbidden)

body=src.index("stream_get_contents($stream,73729)")
controller=src.index("new IntegrationDnsCollectorMachineHttpController")
database=src.index("new Database_PDO")
check(body < controller < database,'bounded body/controller before lazy database construction')
check(src.count('new Database_PDO')==1,'one shared Database_PDO')
check(src.count('($Database)')>=2,'same Database_PDO shared by repo/context')
check("if ($response['body']!==null)" in src,'204 emits no JSON body')
check("JSON_THROW_ON_ERROR" in src,'response JSON errors handled')
print(f'DNS5_MACHINE_ROUTE_SOURCE_ASSERTIONS={n}')
print('DNS5_MACHINE_ROUTE_SOURCE_TESTS_OK=1')
