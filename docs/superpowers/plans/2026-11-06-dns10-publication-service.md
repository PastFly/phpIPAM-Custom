# DNS10 Snapshot Publication Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate and atomically publish an authorized source-bound selected-zone snapshot, including safe replay after a lost acknowledgment.

**Architecture:** A source-only PHP service uses an injected repository whose transaction holds the shared DNS config guard, checks upload-token scope/current configuration and the exact job lease, then combines the DNS10 validator and ordering/resource policy with atomic generation/head/job operations. A memory transaction fixture exercises the contract; the production database adapter and host route are later increments.

**Tech Stack:** PHP 8, in-memory transaction fixture, GitHub Actions.

**Spec:** `design/DNS10_ZONE_PUBLICATION_SERVICE.md`, PR67 and the DNS10 validator/policy/storage contracts.

## Global Constraints

- No production writes, receiver route, token provisioning or Windows deployment in this slice.
- Separate upload credential; reauthenticate and check source, selection, collector assignment, versions and active lease under the transaction.
- Complete snapshot plus success job transition are atomic; exceptions retain last-good state.
- Exact same-sequence/digest/length replay requires current authorization but does not refresh timestamps or need the cleared successful-job lease.
- Enforce 4 MiB/10,000 records, 256 MiB logical accounting and 512 MiB free-space floor.

## Review Focus

- Revocation, reassignment, deselection and changed configuration/credential revisions during parsing must reject the upload.
- An expired/wrong/foreign job or lease and wrong dataset must never publish.
- Exact replay after success must preserve every stored field; altered replay must conflict.
- A storage, head, prune or job-completion failure must roll back all writes.
- Capacity and disk exhaustion must retain the old generation and not complete the job.

### Task 1: Transactional publication contract

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZonePublicationRepository.php`
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZonePublicationService.php`
- Create: `tests/dns10/publication-memory-repository.php`
- Create: `tests/dns10/publication-service.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write behavioral fixtures for every review focus, including an HTTP authentication-to-publication revocation race.
- [ ] Run the fixtures and observe a missing-service failure.
- [ ] Implement the service only against the guarded repository contract and verify all fixtures.
- [ ] Run PHP lint, native PHP CI, and all applicable PR checks; explicitly record that the real DB adapter and live worker remain unimplemented.
