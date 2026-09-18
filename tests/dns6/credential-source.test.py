#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
route=ROOT/'layers/dns6/app/integrations/dns/machine-credential.php'
service=ROOT/'layers/dns6/functions/classes/class.IntegrationDnsCredentialDeliveryService.php'
reader=ROOT/'layers/dns6/functions/classes/class.IntegrationDnsCredentialContextReader.php'
controller=ROOT/'layers/dns6/functions/classes/class.IntegrationDnsCredentialHttpController.php'
control_service=ROOT/'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php'
control_repo=ROOT/'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php'
migration=ROOT/'database/dns6/001-credential-delivery-marker.sql'

n=0
def check(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for p in (route,service,reader,controller,control_service,control_repo,migration):
    check(p.is_file(),f'missing {p}')

rt=route.read_text()
sv=service.read_text()
rd=reader.read_text()
ct=controller.read_text()
cs=control_service.read_text()
cr=control_repo.read_text()
sql=migration.read_text()
alltext='\n'.join((rt,sv,rd,ct,cs,cr))

check('profile_id' not in ct,'HTTP request/controller cannot select profile')
check('connection_id' not in ct,'HTTP request/controller cannot select connection')
check("['job_id','lease_id']" in ct,'request limited exactly to job+lease')
check('Cache-Control' in ct and 'no-store' in ct,'sensitive response no-store')
check('Pragma' in ct and 'no-cache' in ct,'sensitive response pragma no-cache')
check('4096' in ct and '73728' not in ct,'credential request body tightly bounded')
check("'deliver'" in ct and "->deliver(" in ct,'HTTP controller uses one-shot delivery service')
check('credential_already_delivered' in ct,'repeat delivery has explicit conflict mapping')

check('public function deliver(' in sv,'one-shot service entrypoint exists')
check('credential_delivered_at' in sv,'service enforces one-shot marker')
check('credential_already_delivered' in sv,'repeat delivery rejected')
check('assignment_revision' in sv,'assignment revision revalidated')
check('configuration_revision' in sv and 'binding_epoch' in sv,'configuration versions revalidated')
check('credential_version' in sv and 'secret_version' in sv,'credential version revalidated')
check('lease_id' in sv and 'lease_expires_at' in sv,'running lease revalidated')
check('hash_equals' in sv,'token and lease comparisons use constant-time equality')
check("'credential_delivered'" in sv,'delivery is audited')
check("'profile_id'" not in sv.split('public function deliver',1)[0],'no request-facing profile selector')

check('profile_id' in rd and 'secret_envelope' in rd and 'secret_version' in rd,'reader derives secure context from server-side rows')
check('SELECT id,username,secret_envelope,secret_version' in rd,'reader fetches exact current profile row')
check('IntegrationDnsCipher' not in rd and '->open(' not in rd,'reader does not decrypt before delivery authorization')

check('IntegrationDnsCipher::fromKeyringFile()' in rt,'route uses DNS keyring')
check('new IntegrationDnsCredentialContextReader($Database)' in rt,'route builds server-side context reader')
check('new IntegrationDnsCredentialDeliveryService($repo,$resolver,$decryptor)' in rt,'route uses canonical one-shot service')
check("$context['profile_id']" in rt and "$context['secret_envelope']" in rt,'decryptor uses only server-selected context')
check('$_POST' not in rt and '$POST' not in rt,'route uses raw JSON only')

check('credential_delivered_at' in cs,'new jobs initialize one-shot marker')
check('credential_delivered_at' in cr,'phpIPAM repository persists one-shot marker')
check('ALTER TABLE custom_dns_control_jobs' in sql and 'credential_delivered_at' in sql,'migration only extends control jobs')
check('CREATE TABLE' not in sql and 'DROP TABLE' not in sql and 'TRUNCATE' not in sql,'DNS6 migration has no destructive/table-create scope')

for bad in ['Get-DnsServer','PowerShell','Invoke-RestMethod','shell_exec(','exec(','system(','Start-Process','Register-ScheduledTask']:
    check(bad not in alltext,'forbidden runtime dependency '+bad)

print(f'DNS6_CREDENTIAL_SOURCE_ASSERTIONS={n}')
print('DNS6_CREDENTIAL_SOURCE_TESTS_OK=1')
