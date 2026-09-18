-- DNS6 source-only one-shot credential-delivery marker.
-- Requires DNS1 + DNS3 + DNS4 schemas. Never run from a request.
-- Production execution requires explicit approval, backup and exact live-schema review.
ALTER TABLE custom_dns_control_jobs
    ADD COLUMN credential_delivered_at BIGINT UNSIGNED NULL AFTER attempt_count;
