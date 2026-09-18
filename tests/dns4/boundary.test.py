#!/usr/bin/env python3
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[2]
SERVICE=ROOT/'layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php'
MIGRATION=ROOT/'database/dns4/001-machine-job-lifecycle.sql'
CONTROL=ROOT/'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php'
n=0
def check(v,label):
    global n
    n+=1
    if not v: raise AssertionError(label)

for p in (SERVICE,MIGRATION,CONTROL):
    check(p.is_file(),'required source '+str(p))

runtime=subprocess.check_output([
    'php','-r',
    """foreach(array_slice($argv,1) as $p) foreach(token_get_all(file_get_contents($p)) as $t) {
        if(is_array($t)) { if(!in_array($t[0],[T_COMMENT,T_DOC_COMMENT],true)) echo $t[1]; }
        else echo $t;
    }""",
    str(SERVICE)
],text=True)

for required in [
    'collector_auth_failed','hash_equals','assignment_revision','lease_id','lease_expires_at',
    'attempt_count','result_code','result_payload','finished_at','job_obsolete','assignment_changed',
    'lease_expired','job_expired','result_invalid','authentication','authorization',
    'removeJob','86400','120','65536','1024'
]:
    check(required in runtime,'required machine contract '+required)

for forbidden in [
    'IntegrationDnsCipher','secret_envelope','profile_id','password','->open(','decrypt',
    'Invoke-RestMethod','Invoke-WebRequest','Get-DnsServer','Set-DnsServer','Add-DnsServer',
    'Remove-DnsServer','shell_exec','exec(','system(','curl_exec','powershell','scheduledtask',
    'srv-dc01.rbank.local','srv-dhcp01.rbank.local','SELECT ','INSERT ','UPDATE ','DELETE '
]:
    check(forbidden.lower() not in runtime.lower(),'machine runtime forbidden '+forbidden)

sql=MIGRATION.read_text()
check(sql.count('ALTER TABLE custom_dns_control_jobs')==1,'one lifecycle table alteration')
for col in ['assignment_revision','lease_id','lease_expires_at','attempt_count','result_code','result_payload','finished_at']:
    check(('ADD COLUMN '+col) in sql,'migration column '+col)
check('attempt_count BETWEEN 0 AND 1' in sql,'schema forbids automatic retry count above one')
check('custom_dns_job_lease_pair' in sql,'schema lease pair invariant')

control=CONTROL.read_text()
check("'assignment_revision'=>$assignment['revision']" in control,'queue binds assignment revision')
for field in ["'lease_id'=>null","'attempt_count'=>0","'result_payload'=>null","'finished_at'=>null"]:
    check(field in control,'queue initializes machine field '+field)

print(f'DNS4_MACHINE_BOUNDARY_ASSERTIONS={n}')
print('DNS4_MACHINE_BOUNDARY_TESTS_OK=1')
