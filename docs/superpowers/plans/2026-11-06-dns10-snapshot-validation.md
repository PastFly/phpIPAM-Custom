# DNS10 Snapshot Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate a complete source-bound selected-zone observation without side effects or changing the live DNS9 flow.

**Architecture:** A pure PHP validator accepts a decoded snapshot and separately supplied server-authorized dataset context and returns normalized records. Authentication, strict JSON and body limits, ordered transactional storage, selection UI and rollout remain separate increments.

**Tech Stack:** PHP 8, PHPUnit-free repository fixture runner, GitHub Actions.

**Spec:** `design/DNS10_ZONE_SNAPSHOT_VALIDATION.md`; prior product security contract is PR67 `cb83531ac5a122b978378e2568a5407b7e551eba`.

## Global Constraints

- No deployment, schema mutation, DNS write, native IPAM write or automatic selection.
- At most 10,000 records; complete empty zones are valid; no partial replacement.
- The caller authenticates and supplies expected source/dataset/revisions independently.
- HTTP body limit of 4 MiB and duplicate-key rejection belong to the future receiver.
- Windows collector compatibility remains PowerShell 5.1; no change to the live worker.

## Review Focus

- Invalid, foreign or stale dataset and binding values must be rejected.
- Hostile types, duplicate record identities and malformed names must be rejected.
- Apex, root, wildcard, underscore, IPv4 and IPv6 are valid when in scope.
- Empty complete snapshots are valid; partial, oversized and incorrect counts are rejected.
- Timestamps and bounded sequence handling must not make old observations appear new.

### Task 1: Pure snapshot validation

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneSnapshotValidator.php`
- Create: `tests/dns10/snapshot-validator.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write PHP fixtures for all review cases, including normalization, malformed data, identity and future clock bounds.
- [ ] Run the fixture and observe missing-validator failure.
- [ ] Implement the pure validator against the spec and run PHP lint plus fixtures.
- [ ] Run the dedicated CI workflow on the exact feature-branch commit and verify all existing PR checks remain green.
- [ ] Record that this is validated source only; no receiver/storage/production claim.
