#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
ACTION=ROOT/"layers/dns1/app/admin/integrations/dns-config-action.php"
PANEL=ROOT/"layers/dns1/app/admin/integrations/dns-config-panel.php"
APPEND=ROOT/"layers/dns1/app/admin/integrations/index-dns1-append.php"
JS=ROOT/"layers/dns1/app/admin/integrations/dns/config.js"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for p in (ACTION,PANEL,APPEND,JS):
    ok(p.is_file(), f"missing source file: {p}")

action=ACTION.read_text()
panel=PANEL.read_text()
append=APPEND.read_text()
js=JS.read_text()

ok("$User->check_user_session();" in action,"host route establishes phpIPAM session")
ok(action.index("$User->check_user_session();") < action.index("php://input"),"session check precedes body read")
ok("stream_get_contents($stream,16385)" in action,"body read is bounded before JSON parsing")
ok("new IntegrationDnsPhpIpamConfigRepository($Database)" in action,"Database_PDO bridge used")
ok("IntegrationDnsCipher::fromKeyringFile()" in action,"DNS-only keyring used")
ok("IntegrationDnsPdoConfigRepository" not in action,"raw PDO repository not used by phpIPAM route")
ok("CREATE TABLE" not in action and "ALTER TABLE" not in action,"route performs no DDL")
ok("$_POST" not in action and "$POST" not in action,"route does not mix form globals into trusted JSON")
ok("authenticated" not in action and "csrf_valid" not in action and "actor_id" not in action,
   "trusted flags are not read from request route")

ok("csrf_cookie('create','integration_microsoft_dns_config')" in panel,"panel creates dedicated CSRF token")
ok("new IntegrationDnsPhpIpamConfigRepository($Database)" in panel,"panel uses phpIPAM database bridge")
ok("IntegrationDnsCipher::fromKeyringFile()" in panel,"panel fails closed on DNS keyring")
ok("IntegrationDnsAdminView" in panel and "->render(" in panel,"panel renders tested view")
ok("secret_envelope" not in panel and "password" not in panel.lower(),"panel has no saved-secret handling")

ok("PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1" in append,"append has deterministic marker")
ok("dns-config-panel.php" in append,"append includes DNS panel")
ok("PhpIpamDnsConfig.mount" in append,"append mounts tested component")
ok("dns-config-action.php" in append,"append uses dedicated action endpoint")
ok("app/admin/integrations/dns/config.js" in append,"append loads DNS JS")

ok("credentials: 'same-origin'" in js,"browser sends session same-origin")
ok("X-Phpipam-Dns-Csrf" in js,"browser sends dedicated CSRF header")
ok("redirect: 'error'" in js,"browser rejects redirects")
ok("url.origin !== global.location.origin" in js and "url.protocol !== 'https:'" in js,
   "browser enforces same-origin HTTPS")

print(f"DNS1_PHPIPAM_HOST_SOURCE_ASSERTIONS={n}")
print("DNS1_PHPIPAM_HOST_SOURCE_TESTS_OK=1")
