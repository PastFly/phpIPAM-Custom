-- Source candidate only. Production DDL requires separate approval, live-schema review and backup.
-- DDL is not assumed transactional; do not execute this file from application requests.
-- Requires the DNS1 connection and DNS3 collector tables. No IF NOT EXISTS: drift must stop rollout.

CREATE TABLE custom_dns_zone_datasets (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    zone_name VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    virtualization_instance VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    zone_scope VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    selected TINYINT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    UNIQUE KEY custom_dns_zone_dataset_identity (connection_id,zone_name,virtualization_instance,zone_scope),
    CONSTRAINT custom_dns_zone_dataset_source_fk FOREIGN KEY (connection_id) REFERENCES custom_dns_connections(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_dataset_zone CHECK (zone_name <> ''),
    CONSTRAINT custom_dns_zone_dataset_scope CHECK (virtualization_instance='default' AND zone_scope='default'),
    CONSTRAINT custom_dns_zone_dataset_selected CHECK (selected IN (0,1)),
    CONSTRAINT custom_dns_zone_dataset_revision CHECK (revision > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE custom_dns_zone_generations (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    collector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    dataset_revision INT UNSIGNED NOT NULL,
    configuration_revision INT UNSIGNED NOT NULL,
    binding_epoch INT UNSIGNED NOT NULL,
    assignment_revision INT UNSIGNED NOT NULL,
    credential_version INT UNSIGNED NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_bytes INT UNSIGNED NOT NULL,
    record_count INT UNSIGNED NOT NULL,
    started_at BIGINT UNSIGNED NOT NULL,
    finished_at BIGINT UNSIGNED NOT NULL,
    received_at BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY custom_dns_zone_generation_dataset_id (dataset_id,id),
    UNIQUE KEY custom_dns_zone_generation_sequence (dataset_id,sequence_no),
    UNIQUE KEY custom_dns_zone_generation_run (dataset_id,run_id),
    KEY custom_dns_zone_generation_collector (collector_id),
    CONSTRAINT custom_dns_zone_generation_dataset_fk FOREIGN KEY (dataset_id) REFERENCES custom_dns_zone_datasets(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_generation_collector_fk FOREIGN KEY (collector_id) REFERENCES custom_dns_collectors(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_generation_sequence_ck CHECK (sequence_no BETWEEN 1 AND 9007199254740991),
    CONSTRAINT custom_dns_zone_generation_revision_ck CHECK (dataset_revision>0 AND configuration_revision>0 AND binding_epoch>0 AND assignment_revision>0 AND (credential_version IS NULL OR credential_version>0)),
    CONSTRAINT custom_dns_zone_generation_size_ck CHECK (payload_bytes BETWEEN 1 AND 4194304 AND record_count<=10000),
    CONSTRAINT custom_dns_zone_generation_time_ck CHECK (started_at>0 AND finished_at>=started_at AND received_at>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE custom_dns_zone_heads (
    dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    current_generation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_generation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    KEY custom_dns_zone_head_current (dataset_id,current_generation_id),
    KEY custom_dns_zone_head_previous (dataset_id,previous_generation_id),
    CONSTRAINT custom_dns_zone_head_dataset_fk FOREIGN KEY (dataset_id) REFERENCES custom_dns_zone_datasets(id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_head_current_fk FOREIGN KEY (dataset_id,current_generation_id) REFERENCES custom_dns_zone_generations(dataset_id,id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_head_previous_fk FOREIGN KEY (dataset_id,previous_generation_id) REFERENCES custom_dns_zone_generations(dataset_id,id) ON DELETE RESTRICT,
    CONSTRAINT custom_dns_zone_head_order_ck CHECK (previous_generation_id IS NULL OR (current_generation_id IS NOT NULL AND previous_generation_id<>current_generation_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE custom_dns_zone_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    generation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_owner VARCHAR(1024) NOT NULL,
    record_type VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_class VARCHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rdata VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_rdata VARCHAR(1024) NOT NULL,
    ttl_seconds INT UNSIGNED NOT NULL,
    source_aging_timestamp BIGINT UNSIGNED NULL,
    UNIQUE KEY custom_dns_zone_record_identity (generation_id,owner,record_type,rdata),
    KEY custom_dns_zone_record_browse (generation_id,owner,record_type),
    CONSTRAINT custom_dns_zone_record_generation_fk FOREIGN KEY (generation_id) REFERENCES custom_dns_zone_generations(id) ON DELETE CASCADE,
    CONSTRAINT custom_dns_zone_record_type_ck CHECK (record_type IN ('A','AAAA','CNAME','PTR') AND record_class='IN')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
