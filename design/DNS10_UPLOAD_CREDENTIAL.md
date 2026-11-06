# DNS10 — snapshot-upload credential primitive

Status: source-only cryptographic primitive. It does not provision, store, deliver or authorize a credential, and it is not connected to any endpoint or production database. Separate Owner approval and reviewed administrative access, rotation and deployment procedures are required before production credentials can be provisioned.

Snapshot upload uses `dnsup1.<token-id>.<secret>`, distinct from the existing `dnsctl1` collector-control token. Generate a random 128-bit identifier and a separate random 256-bit secret with a cryptographically secure system RNG. Encode the secret as canonical unpadded base64url. Store only a SHA-256 verifier over a purpose-specific domain, token ID and decoded secret; never store the raw secret in the database. Comparing the verifier uses `hash_equals`.

Canonical decoding matters: reject malformed, padded, noncanonical or wrong-length encodings, alternate token families and invalid IDs. Issuance returns the token for a one-time trusted caller and separately returns its ID and digest for storage; no routine API may return it after creation. The token and raw secret are sensitive process data. No helper should log them, use them in URLs or expose them in exception messages.

This primitive does **not** establish which source or collector a token may use, enforce revocation or assignment/configuration/credential revisions, serialize publication, or validate user/admin permissions. Those belong to the future repository, authentication service, administrative controller and in-transaction reauthorization. A syntactically valid or digest-matching token alone is never proof that a snapshot is currently authorized.
