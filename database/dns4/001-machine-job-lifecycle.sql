-- DNS4 source-only extension for machine poll/result lifecycle.
-- Requires DNS1 + DNS3 schemas first. Never execute from a browser request.
-- Deployment preflight MUST require custom_dns_control_jobs to be empty before this migration.
-- Production execution requires explicit approval, backup and exact live-schema review.

ALTER TABLE custom_dns_control_jobs
    ADD COLUMN assignment_revision INT UNSIGNED NOT NULL AFTER connection_id,
    ADD COLUMN lease_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER credential_version,
    ADD COLUMN lease_expires_at BIGINT UNSIGNED NULL AFTER lease_id,
    ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_expires_at,
    ADD COLUMN result_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER attempt_count,
    ADD COLUMN result_payload TEXT NULL AFTER result_code,
    ADD COLUMN finished_at BIGINT UNSIGNED NULL AFTER result_payload,
    ADD CONSTRAINT custom_dns_job_assignment_revision CHECK (assignment_revision > 0),
    ADD CONSTRAINT custom_dns_job_attempt_count CHECK (attempt_count BETWEEN 0 AND 1),
    ADD CONSTRAINT custom_dns_job_lease_pair CHECK (
        (lease_id IS NULL AND lease_expires_at IS NULL) OR
        (lease_id IS NOT NULL AND lease_expires_at IS NOT NULL)
    );
