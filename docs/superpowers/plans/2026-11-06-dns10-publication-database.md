# DNS10 Publication Database Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement and verify source-only guarded MariaDB persistence of authenticated selected-zone snapshots.

**Architecture:** A stacked source-only migration binds jobs to selected datasets and stores digest-only upload-token records. An adapter implements the existing publication repository contract on phpIPAM's lazy `Database_PDO`, serializing all authorization and state transitions with the existing DNS configuration guard. Disposable real MariaDB fixtures exercise the service end to end.

**Tech Stack:** PHP 8, phpIPAM `Database_PDO` interface, MariaDB 10.11 isolated CI.

**Spec:** `design/DNS10_ZONE_PUBLICATION_DATABASE.md` and the DNS10 publication service/security contracts.

## Global Constraints

- No production DDL, database writes, token creation, DNS mutation or worker update in this slice.
- Raw upload token and lease never enter generation rows or logs; token storage contains only a purpose-bound digest.
- Job/dataset/source binding and all revisions are rechecked under the shared guard.
- Publish, prune, job completion and audit are one transaction; replay performs no writes.
- Only an empty localhost database named `phpipam_dns10_test` may be mutated by fixture code.

## Review Focus

- Cross-source job/dataset binding and invalid/revoked token must fail.
- Lazy connections must initialize before transaction and a second transaction cannot acquire the held guard.
- A real first publication, exact replay and new generation must preserve correct head, job and records.
- Failure during each publication phase must roll back head, generation, job and audit together.
- Existing source/collector/assignment revocation or changed config must reject publication.

### Task 1: Stacked schema and database repository

**Files:**
- Create: `database/dns10/002-upload-work.sql`
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPhpIpamRepository.php`
- Modify: `tests/dns10/mysql.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write real MariaDB fixtures for the review focus, including explicit failures and rollback.
- [ ] Confirm missing migration/adapter fails before implementation.
- [ ] Implement the candidate migration and adapter using parameterized SQL and the shared guard.
- [ ] Run PHP lint and existing source fixtures; run isolated MariaDB integration in CI.
- [ ] Preserve runtime evidence and describe the adapter as undeployed source only.
