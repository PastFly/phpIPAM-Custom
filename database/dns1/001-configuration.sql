-- Candidate migration only. Explicit installation approval and exact existing-schema review required.
-- Run only as a separate deployment step; MySQL/MariaDB DDL is not transactionally rollbackable.
-- No IF NOT EXISTS: pre-existing tables are a review/stop condition, not an implicit success.
CREATE TABLE custom_dns_config_guard (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    CONSTRAINT custom_dns_guard_singleton CHECK (id = 1)
) ENGINE=InnoDB;
INSERT INTO custom_dns_config_guard (id) VALUES (1);

CREATE TABLE custom_dns_credential_profiles (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    username VARCHAR(255) NOT NULL,
    secret_envelope TEXT NULL,
    secret_version INT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    CONSTRAINT custom_dns_profile_version CHECK (revision > 0 AND secret_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE custom_dns_connections (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    name VARCHAR(128) NOT NULL,
    server VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    interval_seconds SMALLINT UNSIGNED NOT NULL,
    credential_mode VARCHAR(32) NOT NULL,
    profile_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    collector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    binding_epoch INT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    KEY custom_dns_connection_profile (profile_id),
    CONSTRAINT custom_dns_connection_profile_fk FOREIGN KEY (profile_id) REFERENCES custom_dns_credential_profiles (id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_connection_provider CHECK (provider = 'microsoft_dns'),
    CONSTRAINT custom_dns_connection_interval CHECK (interval_seconds BETWEEN 60 AND 3600),
    CONSTRAINT custom_dns_connection_mode CHECK ((credential_mode = 'profile' AND profile_id IS NOT NULL) OR (credential_mode = 'collector_identity' AND profile_id IS NULL)),
    CONSTRAINT custom_dns_connection_disabled CHECK (enabled = 0 AND collector_id IS NULL),
    CONSTRAINT custom_dns_connection_version CHECK (revision > 0 AND binding_epoch > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE custom_dns_config_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(32) NOT NULL,
    object_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
