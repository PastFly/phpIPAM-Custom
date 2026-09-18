-- DNS3 source-only collector/control schema candidate.
-- Requires DNS1 001-configuration.sql first. Never execute from a browser request.
-- Production migration requires explicit approval, backup and exact live-schema review.

CREATE TABLE custom_dns_collectors (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    token_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    control_epoch INT UNSIGNED NOT NULL,
    revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    CONSTRAINT custom_dns_collector_versions CHECK (revision > 0 AND control_epoch > 0),
    CONSTRAINT custom_dns_collector_revoked CHECK (revoked IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE custom_dns_collector_assignments (
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    collector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    assigned_at BIGINT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    KEY custom_dns_assignment_collector (collector_id),
    CONSTRAINT custom_dns_assignment_connection_fk FOREIGN KEY (connection_id)
        REFERENCES custom_dns_connections (id) ON DELETE CASCADE,
    CONSTRAINT custom_dns_assignment_collector_fk FOREIGN KEY (collector_id)
        REFERENCES custom_dns_collectors (id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_assignment_revision CHECK (revision > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE custom_dns_control_jobs (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    collector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    configuration_revision INT UNSIGNED NOT NULL,
    binding_epoch INT UNSIGNED NOT NULL,
    credential_version INT UNSIGNED NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    KEY custom_dns_jobs_collector_state (collector_id,state,expires_at),
    KEY custom_dns_jobs_connection_type (connection_id,job_type,state),
    CONSTRAINT custom_dns_jobs_collector_fk FOREIGN KEY (collector_id)
        REFERENCES custom_dns_collectors (id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_jobs_connection_fk FOREIGN KEY (connection_id)
        REFERENCES custom_dns_connections (id) ON DELETE CASCADE,
    CONSTRAINT custom_dns_job_type CHECK (job_type IN ('test_connection','list_zones')),
    CONSTRAINT custom_dns_job_state CHECK (state IN ('pending','running','succeeded','failed','expired','obsolete')),
    CONSTRAINT custom_dns_job_versions CHECK (
        configuration_revision > 0 AND binding_epoch > 0 AND
        (credential_version IS NULL OR credential_version > 0) AND
        expires_at >= created_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE custom_dns_control_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    KEY custom_dns_control_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
