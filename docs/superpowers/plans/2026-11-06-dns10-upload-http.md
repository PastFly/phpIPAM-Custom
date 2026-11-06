# DNS10 Snapshot Upload HTTP Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define a machine-only bounded snapshot upload controller that authenticates before strict JSON parsing and never accepts the control credential.

**Architecture:** A source-only PHP controller applies HTTP and token-shape guards, calls an injected upload service for authentication, strictly decodes bounded JSON, then delegates exact-byte digest and publication to that service. The injected service must reauthenticate under the transaction; it is not implemented in this slice.

**Tech Stack:** PHP 8, fixture service and GitHub Actions.

**Spec:** `design/DNS10_ZONE_UPLOAD_HTTP.md` and PR67 snapshot/security design.

## Global Constraints

- No production route, credential creation, storage mutation, DNS write or native IPAM mutation.
- Snapshot upload and collector control credentials use distinct token types.
- Maximum 4 MiB raw request; duplicate JSON keys and malformed or deeply nested input fail closed.
- No response or diagnostic may contain a credential, snapshot body or raw exception.
- Initial authentication does not replace final in-transaction reauthorization.

## Review Focus

- Invalid method, HTTPS, content type, missing credential and oversized body stop before service construction.
- Invalid authorization stops before parsing and publication.
- Duplicate and escaped-equivalent keys, non-object roots, invalid UTF-8 and excessive depth fail after authentication.
- Publisher receives the exact raw-body digest and size, and only bounded decisions are returned.
- Publisher rejection and unexpected exceptions cannot leak data.

### Task 1: Controller boundary and source fixtures

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneUploadHttpController.php`
- Create: `tests/dns10/upload-http.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write fixtures for all HTTP guards, authentication order, strict JSON and redaction cases.
- [ ] Observe the missing-controller failure; implement the smallest source-only controller.
- [ ] Execute PHP lint and fixtures, then native PHP CI and all applicable PR checks.
- [ ] Do not add a live route or claim that the future upload service has been implemented.
