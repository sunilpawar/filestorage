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
