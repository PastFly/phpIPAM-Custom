# DNS10 Snapshot Storage Candidate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define and verify additive source-scoped snapshot tables without touching production.

**Architecture:** A versioned selected-zone dataset owns immutable generations and normalized record rows; a head with composite foreign keys identifies at most the current and previous generation. Future application code handles authentication, ordering, shared-guard transactions and authorized publication.

**Tech Stack:** MariaDB 10.11 isolated CI database, PHP PDO fixtures.

**Spec:** `design/DNS10_ZONE_SNAPSHOT_STORAGE.md` and prior DNS10 validation/publication contracts.

## Global Constraints

- Source-only DDL; never execute against production without separate approval and rollback planning.
- Preserve the existing DNS9 scheduler and source-specific identity boundaries.
- Explicitly selected default-scope zones; 4 MiB and 10,000 records per generation.
- Database foreign keys must reject cross-dataset head pointers and preserve pointed generations.
- DDL cannot replace service-side authorization or atomic publication.

## Review Focus

- Equal zone names on different sources remain independent; duplicates on one source fail.
- A head cannot reference another dataset's generation or the same generation twice.
- Failed publication transaction leaves the old head and records intact.
- Current and previous generations cannot be deleted while referenced.
- Invalid sequence, payload size, record count and selection state are rejected.

### Task 1: Additive schema and real MariaDB fixtures

**Files:**
- Create: `database/dns10/001-zone-snapshots.sql`
- Create: `tests/dns10/mysql.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write an isolated MariaDB fixture that verifies the review-focus cases and refuses all nonlocal or nonempty databases.
- [ ] Observe a missing-schema failure or equivalent source fixture failure before adding the candidate.
- [ ] Add migration with scoped keys and explicit checks; do not add an automatic migration path.
- [ ] Run PHP syntax/source fixtures and the isolated MariaDB workflow on the exact source commit.
- [ ] Record the result as source-only and preserve the active DNS9 schedules.
