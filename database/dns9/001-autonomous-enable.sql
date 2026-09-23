-- DNS9 autonomous collection enablement candidate.
-- Requires DNS1 through DNS6 schema.
-- Source only: production execution requires separate explicit approval and exact live-schema review.
-- The legacy DNS1 check forced every connection to stay disabled while later layers were not yet present.

ALTER TABLE custom_dns_connections
    DROP CONSTRAINT custom_dns_connection_disabled,
    ADD CONSTRAINT custom_dns_connection_enabled CHECK (enabled IN (0,1)),
    ADD CONSTRAINT custom_dns_connection_legacy_collector_null CHECK (collector_id IS NULL);
