# DNS10 Zone Selection Database Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist and audit explicitly selected DNS-zone settings under the shared guard in a disposable MariaDB fixture.

**Architecture:** Compose the DNS10 publication database repository for guarded transactions and source/collector/assignment reads; implement canonical discovery parsing, immutable dataset identity, revisioned writes and audit using the same database connection.

**Tech Stack:** PHP 8, phpIPAM `Database_PDO`, MariaDB 10.11 CI fixtures.

**Spec:** `design/DNS10_ZONE_SELECTION_DATABASE.md` and the DNS10 selection-service contract.

## Global Constraints

- No live migration, administrative endpoint, scheduler change, DNS write or native IPAM mutation.
- Selecting never enables record collection; separate enablement is explicit and revisioned.
- Validate current, correctly bound discovery and reject malformed or noncanonical stored payloads.
- Never retrieve credential ciphertext; disabling/deselection must not require a working source credential.
- All mutations and audit share the existing configuration guard and roll back together.

## Review Focus

- Stale/foreign discovery and malformed or duplicate-key payloads cannot authorize selection.
- Concurrent or stale dataset revisions cannot override a newer change.
- Dataset source/zone/scope and creation identity cannot be changed by update.
- Audit or database failure retains the previous selection and data.
- Selection, enabling, disabling and deselection must work in a real isolated MariaDB.

### Task 1: Database adapter and integration fixture

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionPhpIpamRepository.php`
- Modify: `tests/dns10/mysql.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Add real MariaDB fixture cases for selection, enable/disable, stale discovery, immutable identity and rollback.
- [ ] Observe the missing-adapter failure before implementing it.
- [ ] Implement parameterized reads/writes on the existing guarded database transaction.
- [ ] Run PHP lint, isolated MariaDB CI and the full PR checks.
- [ ] Record source-only evidence; do not deploy or create jobs.
