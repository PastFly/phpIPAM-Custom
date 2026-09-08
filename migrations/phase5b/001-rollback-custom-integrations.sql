-- phpIPAM Custom Phase 5B.1
-- Fail-closed rollback for 001-create-custom-integrations.sql.
--
-- The table is dropped ONLY when it exists and contains zero rows. Once real
-- connector data exists, export/backup review is mandatory and this rollback
-- deliberately raises SQLSTATE 45000 instead of destroying data.
-- Marker: PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_ROLLBACK_V1

-- A previous guarded rollback can leave the helper procedure behind because
-- SIGNAL aborts the batch before the trailing DROP PROCEDURE statement. Clean
-- that helper first so an operator can safely retry after exporting/deleting
-- test data.
DROP PROCEDURE IF EXISTS `phpipam_custom_phase5b_rollback_v1`;

DELIMITER $$
CREATE PROCEDURE `phpipam_custom_phase5b_rollback_v1`()
BEGIN
  DECLARE table_exists INT DEFAULT 0;
  DECLARE row_count BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO table_exists
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'custom_integrations';

  IF table_exists = 1 THEN
    SELECT COUNT(*) INTO row_count FROM `custom_integrations`;

    IF row_count > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Refusing Phase 5B rollback: custom_integrations contains data; export/backup decision required';
    ELSE
      DROP TABLE `custom_integrations`;
    END IF;
  END IF;
END$$
DELIMITER ;

CALL `phpipam_custom_phase5b_rollback_v1`();
DROP PROCEDURE `phpipam_custom_phase5b_rollback_v1`;
