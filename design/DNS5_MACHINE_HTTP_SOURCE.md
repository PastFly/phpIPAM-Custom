# DNS5 machine HTTP poll/result boundary — source milestone

Date: 2026-09-18
Base: DNS4 PR71 exact `cdffa61ec5d4761fc036b9fb4b51c6a48d1c8ba0`.
Status: source-only HTTP candidate; no deployment, credential delivery, Windows task or production token.

## Purpose

Expose only the already-reviewed DNS4 `poll` and `result` machine lifecycle through a strict machine endpoint. Authentication is the DNS collector Bearer token, not a phpIPAM user session.

## HTTP boundary

`IntegrationDnsCollectorMachineHttpController` rejects requests before service/database construction unless they satisfy:
- POST;
- trusted HTTPS=true supplied by host route;
- application/json;
- exact `Bearer dnsctl1.<collector-id>.<secret>` shape;
- body <=73728 bytes;
- strict JSON without duplicate object keys;
- exact action shape: `poll` or `result`.

Poll returns 204 for no work. Result requires exact 32-hex job/lease IDs. Domain errors are mapped to bounded machine statuses; unexpected exceptions are always sanitized. Responses are no-store/nosniff.

## phpIPAM host route

Candidate route: `layers/dns5/app/integrations/dns/machine-control.php`.

It:
- uses phpIPAM `Common_functions::isHttps()`; forwarded headers are trusted only according to the existing phpIPAM `trust_x_forwarded_headers` configuration;
- reads Authorization from server/header input only;
- bounds `php://input` to 73729 bytes before controller/service/database construction;
- has no user session/CSRF because this is a machine-token endpoint;
- creates one `Database_PDO` lazily inside the controller service factory;
- shares that same DB object between DNS3 control repository and DNS5 context reader.

The context reader intentionally starts no nested transaction. DNS4 machine service holds the shared `custom_dns_config_guard`; the reader obtains current connection/profile version metadata on the same DB session. It never selects credential ciphertext and never returns profile ID.

## Credential boundary

DNS5 does **not** decrypt or return source credentials. It does not load `IntegrationDnsCipher` or the DNS credential keyring. Poll returns server plus job/version/lease metadata only.

Credential delivery remains a separate future endpoint. It must resolve the profile server-side and revalidate collector, assignment, connection revision, binding epoch and profile secret version immediately before decrypt/delivery. Collector input must never choose profile/secret ID.

## Executed evidence

Local PHP 8.4.23 reconstructed-current-source:
- context reader: 15 assertions green;
- machine HTTP controller: 32 assertions green;
- both DNS5 classes lint green.

Exact GitHub route blob `3ea52c2662a88d45ba4ea2646195cae51627bee3` passed 43 source-boundary assertions.

The stock phpIPAM `Common_functions::isHttps()` source was read from upstream: it uses X-Forwarded-Proto/SSL only when `trust_x_forwarded_headers` is enabled; otherwise it relies on HTTPS/port443.

No live route, schema/key/token/task/credential or provider query was created.
