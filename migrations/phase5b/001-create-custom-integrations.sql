-- phpIPAM Custom Phase 5B.1
-- Forward migration: persistent integration connector configuration.
--
-- This is a custom migration and MUST NOT change phpIPAM settings.dbversion.
-- Production execution requires a separate Owner approval and pre-migration backup.
-- Marker: PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1

CREATE TABLE `custom_integrations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL,
  `name` varchar(128) NOT NULL,
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `endpoint` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `verify_tls` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `secret_envelope` text DEFAULT NULL,
  `secret_key_id` varchar(64) DEFAULT NULL,
  `created_by` int(11) unsigned DEFAULT NULL,
  `updated_by` int(11) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_test_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_error_at` datetime DEFAULT NULL,
  `last_error_code` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_integrations_provider_endpoint` (`provider`,`endpoint`),
  KEY `idx_custom_integrations_provider_enabled` (`provider`,`enabled`),
  KEY `idx_custom_integrations_last_success` (`last_success_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1';
