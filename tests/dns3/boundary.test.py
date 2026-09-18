#!/usr/bin/env python3
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[2]
CLASSES=ROOT/'layers/dns3/functions/classes'
service_path=CLASSES/'class.IntegrationDnsCollectorControlService.php'
repo_path=CLASSES/'class.IntegrationDnsCollectorControlRepository.php'
service=service_path.read_text()

runtime=subprocess.check_output([
    'php','-r',
    """foreach(array_slice($argv,1) as $p) {
        foreach(token_get_all(file_get_contents($p)) as $t) {
            if (is_array($t)) {
                if (!in_array($t[0],[T_COMMENT,T_DOC_COMMENT],true)) echo $t[1];
            } else echo $t;
        }
    }""",
    str(service_path),str(repo_path)
],text=True)

n=0
def check(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for required in [
    "dnsctl1.","hash('sha256'","hash_equals","random_bytes(32)",
    "'test_connection'","'list_zones'","'control_token'","'token_digest'",
    "'configuration_revision'","'binding_epoch'","'credential_version'",
    "'obsolete'","removeAssignment","collector_auth_failed"
]:
    check(required in runtime,'required control contract: '+required)

for forbidden in [
    'IntegrationDnsCipher','secret_envelope','->open(','decrypt','password',
    'Invoke-RestMethod','Get-DnsServer','Set-DnsServer','Add-DnsServer',
    'Remove-DnsServer','shell_exec','exec(','system(','curl_exec',
    'srv-dc01.rbank.local','srv-dhcp01.rbank.local','powershell',
    'scheduledtask','SELECT ','INSERT ','UPDATE ','DELETE '
]:
    check(forbidden.lower() not in runtime.lower(),'control layer forbidden dependency: '+forbidden)

check("['test_connection','list_zones']" in service,'fixed job allowlist')
check("'collect_selected_zones'" not in service,'collection job not enabled early')
check("array_intersect_key" in service,'public projections are allowlisted')
check("control_token'=>$token" in service,'registration returns token once')
check("'token_digest'=>self::digest($token)" in service,'repository stores digest')
check("'profile_id'" not in service,'control jobs do not expose/choose profile id')
check("count($this->repo->collectors())>=64" in service,'collector count bounded')
check("count($jobs)>=512" in service,'job count bounded')
print(f'DNS3_CONTROL_BOUNDARY_ASSERTIONS={n}')
print('DNS3_CONTROL_BOUNDARY_TESTS_OK=1')
