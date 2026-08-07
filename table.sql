CREATE TABLE IF NOT EXISTS `mod_maple_grove_education_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `openemr_user_id` BIGINT NOT NULL,
    `username` VARCHAR(255) NOT NULL,
    `education_role` VARCHAR(32) NOT NULL DEFAULT 'student',
    `track_activity` TINYINT(1) NOT NULL DEFAULT 1,
    `can_view_analytics` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_openemr_user` (`openemr_user_id`)
);

CREATE TABLE IF NOT EXISTS `mod_maple_grove_education_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `openemr_user_id` BIGINT NOT NULL,
    `username` VARCHAR(255) NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `task_id` BIGINT NULL,
    `patient_id` BIGINT NULL,
    `encounter_id` BIGINT NULL,
    `metadata` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `education_events_user` (`openemr_user_id`),
    KEY `education_events_type` (`event_type`),
    KEY `education_events_created` (`created_at`)
);

-- ---------------------------------------------------------------------------
-- Maple Grove audit analytics performance indexes
--
-- These indexes speed up queries against OpenEMR's existing audit log table.
-- Each index is created only when an index with our name does not already exist.
-- Compatible with older MySQL/MariaDB versions used by OpenEMR deployments.
-- ---------------------------------------------------------------------------

SET @maple_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'log'
      AND index_name = 'idx_maple_grove_log_user_date'
);

SET @maple_index_sql = IF(
    @maple_index_exists = 0,
    'CREATE INDEX `idx_maple_grove_log_user_date` ON `log` (`user`, `date`)',
    'SELECT 1'
);

PREPARE maple_index_statement FROM @maple_index_sql;
EXECUTE maple_index_statement;
DEALLOCATE PREPARE maple_index_statement;


SET @maple_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'log'
      AND index_name = 'idx_maple_grove_log_user_event_date'
);

SET @maple_index_sql = IF(
    @maple_index_exists = 0,
    'CREATE INDEX `idx_maple_grove_log_user_event_date` ON `log` (`user`, `event`, `date`)',
    'SELECT 1'
);

PREPARE maple_index_statement FROM @maple_index_sql;
EXECUTE maple_index_statement;
DEALLOCATE PREPARE maple_index_statement;


SET @maple_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'log'
      AND index_name = 'idx_maple_grove_log_date_user'
);

SET @maple_index_sql = IF(
    @maple_index_exists = 0,
    'CREATE INDEX `idx_maple_grove_log_date_user` ON `log` (`date`, `user`)',
    'SELECT 1'
);

PREPARE maple_index_statement FROM @maple_index_sql;
EXECUTE maple_index_statement;
DEALLOCATE PREPARE maple_index_statement;


SET @maple_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'log'
      AND index_name = 'idx_maple_grove_log_user_patient_date'
);

SET @maple_index_sql = IF(
    @maple_index_exists = 0,
    'CREATE INDEX `idx_maple_grove_log_user_patient_date` ON `log` (`user`, `patient_id`, `date`)',
    'SELECT 1'
);

PREPARE maple_index_statement FROM @maple_index_sql;
EXECUTE maple_index_statement;
DEALLOCATE PREPARE maple_index_statement;
