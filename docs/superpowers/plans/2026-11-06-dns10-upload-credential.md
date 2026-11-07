# DNS10 Snapshot Upload Credential Primitive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate and verify purpose-separated snapshot-upload tokens without persistence or deployment.

**Architecture:** A pure helper uses system randomness, canonical base64url decoding and a purpose/id-bound SHA-256 digest; trusted application services remain responsible for storing verifiers and checking source, assignment and revocation in transactions.

**Tech Stack:** PHP 8, PHP fixture runner, GitHub Actions.

**Spec:** `design/DNS10_UPLOAD_CREDENTIAL.md` and PR67 snapshot-upload security contract.

## Global Constraints

- Never create production credentials, change tasks or enable a new endpoint in this source increment.
- Token IDs are 128-bit; secrets are 256-bit; store only a verifier.
- `dnsup1` and `dnsctl1` are not interchangeable.
- No secret, raw exception or token may be logged or returned by a routine read API.
- Authentication and source/collector authorization remain separate future obligations.

## Review Focus

- Wrong token family, invalid ID and malformed or noncanonical base64url are rejected.
- Changed secret, token ID or stored verifier cannot authenticate.
- Issued token has the exact encoding and verifies against its own stored digest.
- Issued tokens and IDs differ and the verifier is not a plaintext secret.
- Malformed input never leaks the supplied token in an exception.

### Task 1: Token generation and verification

**Files:**
- Create: `layers/dns10/functions/classes/class.IntegrationDnsZoneUploadToken.php`
- Create: `tests/dns10/upload-token.test.php`
- Modify: `.github/workflows/dns10-snapshot-validator.yml`

- [ ] Write fixtures for token encoding, uniqueness, purpose separation and failure cases.
- [ ] Observe the missing-helper failure and implement the primitive.
- [ ] Run PHP lint and fixture tests, then native PHP CI on the exact source commit.
- [ ] Record source-only status; do not imply source binding or revocation is implemented.
