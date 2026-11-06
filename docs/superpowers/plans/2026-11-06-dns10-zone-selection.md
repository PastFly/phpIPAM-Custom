# DNS10 Zone Selection Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a trusted administrative boundary to select source-scoped discovered zones separately from explicitly enabling record collection.

**Architecture:** A source-only service operates against an injected shared-guard repository, checks current source/collector/discovery bindings and optimistic dataset revisions, and audits atomic selection or enablement changes. Real phpIPAM route, database adapter and scheduler are later increments.

**Tech Stack:** PHP 8, memory fixture, GitHub Actions.

**Spec:** `design/DNS10_ZONE_SELECTION_SERVICE.md` and the PR67 DNS first-release contract.

## Global Constraints

- Selection alone never enables record collection; all new datasets start disabled.
- At most 64 selected datasets per source; only current discovered primary/secondary zones.
- Deselection and disabling preserve existing observations and sequence state.
- No production mutation, API route, DNS write or native IPAM mutation in this increment.
- Admin session, CSRF and reauthentication are enforced by the future host route, not an actor integer.

## Review Focus

- Stale or foreign discovery, collector, assignment, source or dataset revisions cannot authorize selection.
- Stub, forwarder, unsupported and undiscovered zones cannot be enabled.
- A selected zone does not automatically collect; an explicit second action is required.
- Disabling and deselection remain possible without discovery and retain last-good state.
- At the 64-zone limit, selection fails without changes; audit failures roll back.

### Task 1: Selection and enablement service

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionRepository.php`
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionService.php`
- Create: `tests/dns10/selection-memory-repository.php`
- Create: `tests/dns10/selection-service.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write behavioral fixtures for each review-focus case, boundaries and explicit enable/disable.
- [ ] Observe the missing-service failure, then implement the minimal transaction-bound service.
- [ ] Run PHP lint and fixtures followed by native PHP CI on the exact feature-branch commit.
- [ ] Retain the source-only status and the separate production rollout gate.
