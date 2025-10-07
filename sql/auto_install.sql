-- /*******************************************************
-- *
-- * com.skvare.filestorage
-- *
-- * Database schema changes for File Storage Manager
-- * Adds columns to civicrm_file table to track storage backend
-- *
-- *******************************************************/

-- Add storage-related columns to existing civicrm_file table
ALTER TABLE `civicrm_file`
    ADD COLUMN IF NOT EXISTS `storage_type` VARCHAR(32) DEFAULT 'local'
    COMMENT 'Storage backend type: local, s3, gcs, azure, spaces' AFTER `created_id`,
    ADD COLUMN IF NOT EXISTS `storage_path` VARCHAR(512)
    COMMENT 'Full storage path/key for remote storage (e.g., s3://bucket/path/file.pdf)' AFTER `storage_type`,
    ADD COLUMN IF NOT EXISTS `storage_metadata` TEXT
    COMMENT 'JSON metadata for storage: bucket, region, cdn_url, etc.' AFTER `storage_path`,
    ADD COLUMN IF NOT EXISTS `last_sync_date` DATETIME
    COMMENT 'Timestamp of last successful sync to remote storage' AFTER `storage_metadata`,
    ADD COLUMN IF NOT EXISTS `sync_status` VARCHAR(32) DEFAULT 'pending'
    COMMENT 'Sync status: pending, synced, failed, excluded' AFTER `last_sync_date`,
    ADD INDEX IF NOT EXISTS `idx_storage_type` (`storage_type`),
    ADD INDEX IF NOT EXISTS `idx_sync_status` (`sync_status`),
    ADD INDEX IF NOT EXISTS `idx_last_sync_date` (`last_sync_date`);

-- Create sync log table to track sync operations
CREATE TABLE IF NOT EXISTS `civicrm_filestorage_sync_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique sync log ID',
    `file_id` INT UNSIGNED NOT NULL COMMENT 'FK to civicrm_file',
    `operation` VARCHAR(32) NOT NULL COMMENT 'Operation type: upload, download, delete, verify',
    `source_storage` VARCHAR(32) COMMENT 'Source storage type',
    `target_storage` VARCHAR(32) COMMENT 'Target storage type',
    `status` VARCHAR(32) NOT NULL COMMENT 'Operation status: success, failed, skipped',
    `error_message` TEXT COMMENT 'Error details if status is failed',
    `file_size` BIGINT COMMENT 'File size in bytes',
    `duration_ms` INT COMMENT 'Operation duration in milliseconds',
    `sync_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When sync was attempted',
    `created_by` INT UNSIGNED COMMENT 'Contact ID who triggered sync',
    PRIMARY KEY (`id`),
    INDEX `idx_file_id` (`file_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sync_date` (`sync_date`),
    CONSTRAINT `FK_civicrm_filestorage_sync_log_file_id`
    FOREIGN KEY (`file_id`) REFERENCES `civicrm_file` (`id`)
    ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Tracks file storage sync operations for auditing and troubleshooting';

-- Create settings table for storage configurations
CREATE TABLE IF NOT EXISTS `civicrm_filestorage_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique config ID',
    `storage_type` VARCHAR(32) NOT NULL COMMENT 'Storage type: s3, gcs, azure, spaces',
    `config_name` VARCHAR(64) NOT NULL COMMENT 'Friendly name for this configuration',
    `config_data` TEXT NOT NULL COMMENT 'JSON configuration: credentials, bucket, region, etc.',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Is this configuration active?',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Is this the default storage for new files?',
    `file_type_rules` TEXT COMMENT 'JSON rules for which file types use this storage',
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `modified_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_config_name` (`config_name`),
    INDEX `idx_storage_type` (`storage_type`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_is_default` (`is_default`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Storage backend configurations';