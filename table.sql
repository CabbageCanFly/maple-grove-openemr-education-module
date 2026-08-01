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
