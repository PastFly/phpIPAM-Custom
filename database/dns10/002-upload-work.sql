-- Source candidate only; requires DNS1, DNS3, DNS4, DNS6, DNS9 and DNS10/001.
-- Production DDL requires separate approval, live-schema/version review, backup and recovery planning.
-- DDL is not assumed transactional. Never apply from an HTTP request or source test against production.

ALTER TABLE custom_dns_control_jobs
    DROP CONSTRAINT custom_dns_job_type,
    ADD CONSTRAINT custom_dns_job_type CHECK (job_type IN ('test_connection','list_zones','collect_selected_zones')),
    ADD UNIQUE KEY custom_dns_zone_job_source_id (connection_id,id);

ALTER TABLE custom_dns_zone_datasets
    ADD UNIQUE KEY custom_dns_zone_dataset_source_id (connection_id,id);

CREATE TABLE custom_dns_zone_upload_tokens (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    collector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assignment_revision INT UNSIGNED NOT NULL,
    token_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    KEY custom_dns_zone_token_source (connection_id,collector_id,revoked),
    CONSTRAINT custom_dns_zone_token_source_fk FOREIGN KEY (connection_id) REFERENCES custom_dns_connections(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_token_collector_fk FOREIGN KEY (collector_id) REFERENCES custom_dns_collectors(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_token_revision_ck CHECK (assignment_revision>0),
    CONSTRAINT custom_dns_zone_token_revoked_ck CHECK (revoked IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE custom_dns_zone_job_datasets (
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dataset_revision INT UNSIGNED NOT NULL,
    KEY custom_dns_zone_job_source (connection_id,job_id),
    KEY custom_dns_zone_job_dataset (connection_id,dataset_id),
    CONSTRAINT custom_dns_zone_job_source_fk FOREIGN KEY (connection_id,job_id) REFERENCES custom_dns_control_jobs(connection_id,id) ON DELETE CASCADE,
    CONSTRAINT custom_dns_zone_job_dataset_fk FOREIGN KEY (connection_id,dataset_id) REFERENCES custom_dns_zone_datasets(connection_id,id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_job_revision_ck CHECK (dataset_revision>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

-- Synchronization events only, not the separate security audit. The application must keep at most 1000 rows.
CREATE TABLE custom_dns_zone_sync_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    KEY custom_dns_zone_sync_event_time (created_at),
    CONSTRAINT custom_dns_zone_sync_event_kind CHECK (action='snapshot_published' AND actor_id=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
