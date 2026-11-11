# DNS1 configuration and profiles — first executable source increment

Approved design: MICROSOFT_DNS_V1.md revision 2, PR67 cb83531ac5a122b978378e2568a5407b7e551eba.
This branch starts independently from development master 1f6298c3f0896ea6b7af7b6dc8d67cbfc5f9876d. The PR does not merge PR67 or any VMware branch.

## Implemented source

- Separate DNS-only authenticated-encryption envelopes, random 24-byte nonce, profile ID + exact username + secret-version associated data, versioned algorithm/key ID. Uses PHP sodium's XChaCha20-Poly1305 primitive, not a custom cipher. No VMware key reuse.
- Strict private keyring reader, default `/etc/phpipam-custom/dns-credentials.key`: root:33,0640, regular single-link file, no symlink ancestors, bounded read and before/open/after identity checks. No generation/provisioning or bypass flag. The JSON schema is version=1, active=key ID, keys=map of key ID to 64 lowercase hex characters. Up to four separately managed keys. No actual key or credential is committed.
- Domain service for creating/editing/deleting DNS connections and credential profiles; explicit unchanged/replace semantics; empty/mask rejection; UPN or explicit domain-qualified usernames. Changing a username requires re-entry of its password. Plaintext is not returned, stored in audit metadata or passed to external processes. Best-effort zeroing is not a promise that PHP/browser memory has no copies.
- Optimistic revisions reject stale forms. Replacement/revocation requires confirmation of the complete current consumer-ID list, including a newly attached consumer. Shared profiles require explicit assignment. Profile and consumer version/epoch changes occur in one repository transaction. All affected connections become disabled/require revalidation. Replacing credentials does NOT change the AD password.
- Transport-independent controller checks trusted host-resolved method/session/admin/HTTPS/CSRF/actor context before lazy service/key/database construction. Only bounded config operations; no collector registration, test, discovery, enablement, secret-export/decrypt or machine endpoint.
- Russian administration forms and same-origin HTTPS JavaScript submission component, explicit password operation, no saved password in DOM, escaped display names, conflict handling, no auto retries. Provider/enable buttons are visibly unavailable until the next source stage.
- PDO MySQL/MariaDB repository with parameterized DML, one InnoDB configuration mutex, bounded 1000-entry redacted audit. Separate additive four-table schema candidate. Profiles/connections capped at 128/256. Schema additionally requires disabled connections and unassigned collectors in this source increment.

## Not yet integrated/deployed

There is deliberately no `action.php`, application bootstrap, navigation change or installer. The view/controller components are executable through fixtures, but not connected to the production phpIPAM session/Database_PDO lifecycle. A trusted host bridge must construct auth context separately from request JSON and invoke service creation only after existing User/session/admin/CSRF checks. It must enforce a body limit before parsing, reject duplicate JSON keys, require same-origin verified HTTPS and avoid logging password bodies. No request can supply the controller's trusted auth flags.

The public components must not be copied directly into a live webroot to improvise a route. The effective current admin layout/Database_PDO access needs an exact integration review. No SQL schema was executed on production. DDL has implicit commits on MySQL/MariaDB: separate migration preflight/rollback/removal design is still required. Key deployment/backup/rotation procedure is separate; losing the key means credentials cannot be recovered. No auto key creation or reading from environment.

Collector registration/assignment, typed async jobs, source-scoped machine authorization, credential delivery, selected-zone discovery/collection, DNS observation tables, IP-card enrichment and Windows execution are next increments. The source service currently permits only draft disabled/unassigned configuration; enabling requires a future explicitly reviewed schema/service extension, not removing a check ad hoc.

## Verification

Run `php tests/dns1/config.test.php`, root-owned temporary-file fixtures `php tests/dns1/keyring.test.php`, and `python3 tests/dns1/boundary.test.py`.
The configuration suite uses a test-only in-memory repository: it proves service transaction intent/rollback behavior, NOT actual InnoDB locking or durability. `tests/dns1/mysql.test.php` is opt-in, limited to an empty localhost database named phpipam_dns1_test. Missing PDO mysql/DSN returns77/NOT_RUN, never a green database claim. Its fixture trigger tests rollback after a consumer update fails; a second PDO connection tests mutex contention; audit retention is also exercised.

`tests/dns1/browser.test.py` uses real offline Chromium DOM/events and real PHP controller/service with in-memory test storage and mocked HTTPS fetch. No network, TLS handshake, actual phpIPAM session or Windows is involved. Managed-browser navigation initially returned ERR_BLOCKED_BY_ADMINISTRATOR even for the localhost fixture; the component test therefore uses set_content plus an explicit mocked window/transport, without changing browser policy or production source. This is component verification, not live-site acceptance.

Dedicated CI runs PHP/source checks, MariaDB10.11 tests and offline browser tests. Status must be inspected on the exact committed head; workflow presence is not execution evidence. Existing full-project regression suites are not claimed from the isolated connector-backed patch workspace.

## References

- PHP sodium AEAD: https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php
- PDO transactions/DDL caveat: https://www.php.net/manual/en/pdo.begintransaction.php
- Existing repository security/admin patterns reviewed: layers/phase5b/functions/classes/class.IntegrationSecretCipher.php; layers/phase5b/functions/classes/class.IntegrationVmwareVcenterConfigStore.php; layers/phase5b3/app/admin/integrations/vmware-config-action.php. Those files remain byte-identical.
