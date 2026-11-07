# DNS10 Snapshot Publication Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decide whether a complete validated selected-zone snapshot is a new publication, idempotent replay, or rejection without mutating state.

**Architecture:** A pure PHP policy checks trusted stored sequence/digest and current resource counters against receiver-computed request evidence. A future authenticated transactional storage layer supplies these inputs and enforces the decision.

**Tech Stack:** PHP 8, repository fixture runner, GitHub Actions.

**Spec:** `design/DNS10_ZONE_PUBLICATION_POLICY.md` and PR67 snapshot design.

## Global Constraints

- No production database/schema/Windows/DNS changes; no receiver or credential implementation in this slice.
- Preserve last-good data; exact same-sequence payload is replay without timestamp updates.
- Maximum 4 MiB request, 256 MiB accounted payload and 512 MiB free-space floor.
- Authenticate and revalidate source, dataset and binding before applying this policy in future code.

## Review Focus

- Old sequence and equal sequence with changed bytes must be rejected.
- Same sequence and same bytes must remain an unmodified replay even when disk is full.
- Accounting must include temporary new-payload space and remove only the previous generation.
- Invalid counters, forged lengths and unsafe integer boundaries must fail closed.
- A new valid dataset and a newer sequence must make the correct publication decision.

### Task 1: Pure publication decision

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPolicy.php`
- Create: `tests/dns10/publication-policy.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write fixtures covering every review focus, exact quota boundaries and empty state.
- [ ] Run the fixture and observe the missing-policy failure.
- [ ] Implement minimal pure policy and execute PHP lint and fixture tests.
- [ ] Run dedicated CI on the exact branch commit and record source-only status.
