<?php

namespace Civi\Filestorage\Service;

use Civi\Filestorage\Storage\StorageFactory;
use Civi\Filestorage\Util\PathHelper;
use CRM_Core_Exception;

/**
 * Migration Service Class.
 *
 * Handles bulk migration of files between storage backends.
 * Provides tools for:
 * - Planning migrations
 * - Executing migrations in batches
 * - Rollback capabilities
 * - Progress tracking
 * - Validation and verification
 *
 * @package Civi\Filestorage\Service
 */
class MigrationService {

  /**
   * Plan a migration operation.
   *
   * Analyzes files that would be migrated and provides estimates.
   * Does not perform actual migration.
   *
   * @param array $criteria Migration criteria:
   *   - 'source_storage' => string - Source storage type (optional, defaults to all)
   *   - 'target_storage' => string - Target storage type (required)
   *   - 'file_types' => array - File type IDs to migrate
   *   - 'entity_types' => array - Entity types to migrate
   *   - 'days_old' => int - Only migrate files older than X days
   *   - 'min_size' => int - Minimum file size in bytes
   *   - 'max_size' => int - Maximum file size in bytes
   *
   * @return array Migration plan:
   *   - 'file_count' => int - Number of files to migrate
   *   - 'total_size' => int - Total size in bytes
   *   - 'estimated_time' => int - Estimated time in seconds
   *   - 'estimated_cost' => float - Estimated storage cost
   *   - 'files' => array - Sample of files to migrate
   *
   * @throws CRM_Core_Exception If criteria invalid
   */
  public static function planMigration(array $criteria): array {
    // Validate criteria
    if (empty($criteria['target_storage'])) {
      throw new CRM_Core_Exception("Target storage is required");
    }

    // Build query to find files
    $query = \Civi\Api4\File::get(FALSE)
      ->addSelect('id', 'mime_type', 'storage_type', 'storage_path', 'uri', 'upload_date');

    // Apply filters
    if (!empty($criteria['source_storage'])) {
      $query->addWhere('storage_type', '=', $criteria['source_storage']);
    }

    // Don't migrate files already on target storage
    $query->addWhere('storage_type', '!=', $criteria['target_storage']);

    if (!empty($criteria['file_types'])) {
      $query->addWhere('file_type_id', 'IN', $criteria['file_types']);
    }

    if (!empty($criteria['days_old'])) {
      $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$criteria['days_old']} days"));
      $query->addWhere('upload_date', '<', $dateThreshold);
    }

    // Get count and sample
    $totalCount = $query->execute()->count();

    // Get sample of files for size estimation
    $sampleSize = min($totalCount, 100);
    $sampleFiles = $query->setLimit($sampleSize)->execute();

    // Calculate statistics
    $totalSize = 0;
    $filesWithSize = 0;

    foreach ($sampleFiles as $file) {
      try {
        $storage = StorageFactory::getAdapterForFile($file['id']);
        $size = $storage->getSize($file['storage_path'] ?? $file['uri']);
        $totalSize += $size;
        $filesWithSize++;
      }
      catch (\Exception $e) {
        // Skip files where we can't get size
      }
    }

    // Estimate total size based on sample
    $avgFileSize = $filesWithSize > 0 ? ($totalSize / $filesWithSize) : 0;
    $estimatedTotalSize = (int)($avgFileSize * $totalCount);

    // Estimate time (assuming 1MB/second transfer rate)
    $estimatedTime = $estimatedTotalSize / (1024 * 1024);

    // Estimate cost based on target storage
    $estimatedCost = self::estimateStorageCost(
      $criteria['target_storage'],
      $estimatedTotalSize
    );

    return [
      'file_count' => $totalCount,
      'total_size' => $estimatedTotalSize,
      'total_size_formatted' => PathHelper::formatFileSize($estimatedTotalSize),
      'estimated_time' => (int)$estimatedTime,
      'estimated_cost' => $estimatedCost,
      'avg_file_size' => (int)$avgFileSize,
      'sample_size' => $filesWithSize,
      'criteria' => $criteria,
    ];
  }

  /**
   * Execute a migration operation.
   *
   * Performs the actual migration of files between storage backends.
   * Processes files in batches to avoid timeouts.
   *
   * @param array $criteria Migration criteria (same as planMigration)
   * @param array $options Migration options:
   *   - 'batch_size' => int - Files per batch (default: 50)
   *   - 'delete_source' => bool - Delete from source after migration
   *   - 'verify' => bool - Verify files after migration
   *   - 'dry_run' => bool - Simulate without actually migrating
   *
   * @return array Migration results:
   *   - 'processed' => int
   *   - 'success' => int
   *   - 'failed' => int
   *   - 'skipped' => int
   *   - 'errors' => array
   *   - 'duration' => int - Seconds
   *
   * @throws CRM_Core_Exception
   */
  public static function executeMigration(array $criteria, array $options = []): array {
    $batchSize = $options['batch_size'] ?? 50;
    $deleteSource = $options['delete_source'] ?? FALSE;
    $verify = $options['verify'] ?? FALSE;
    $dryRun = $options['dry_run'] ?? FALSE;

    $results = [
      'processed' => 0,
      'success' => 0,
      'failed' => 0,
      'skipped' => 0,
      'errors' => [],
      'duration' => 0,
    ];

    $startTime = microtime(TRUE);

    // Build query
    $query = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->setLimit($batchSize);

    // Apply same filters as planMigration
    if (!empty($criteria['source_storage'])) {
      $query->addWhere('storage_type', '=', $criteria['source_storage']);
    }

    $query->addWhere('storage_type', '!=', $criteria['target_storage']);

    if (!empty($criteria['file_types'])) {
      $query->addWhere('file_type_id', 'IN', $criteria['file_types']);
    }

    if (!empty($criteria['days_old'])) {
      $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$criteria['days_old']} days"));
      $query->addWhere('upload_date', '<', $dateThreshold);
    }

    $files = $query->execute();

    // Process each file
    foreach ($files as $file) {
      $results['processed']++;

      if ($dryRun) {
        $results['skipped']++;
        continue;
      }

      try {
        // Migrate file
        self::migrateFile(
          $file,
          $criteria['target_storage'],
          $deleteSource,
          $verify
        );

        $results['success']++;
      }
      catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][$file['id']] = $e->getMessage();

        \Civi::log()->error(
          "Failed to migrate file {$file['id']}: " . $e->getMessage()
        );
      }
    }

    $results['duration'] = (int)(microtime(TRUE) - $startTime);

    return $results;
  }

  /**
   * Migrate a single file.
   *
   * @param array $file File record
   * @param string $targetStorage Target storage type
   * @param bool $deleteSource Delete from source after migration
   * @param bool $verify Verify file after migration
   *
   * @return bool TRUE on success
   *
   * @throws CRM_Core_Exception If migration fails
   */
  private static function migrateFile(
    array  $file,
    string $targetStorage,
    bool   $deleteSource,
    bool   $verify
  ): bool {
    $sourceStorage = StorageFactory::getAdapterForFile($file['id']);
    $targetStorageAdapter = StorageFactory::getAdapter($targetStorage);

    $sourcePath = $file['storage_path'] ?? $file['uri'];

    // Check source exists
    if (!$sourceStorage->exists($sourcePath)) {
      throw new CRM_Core_Exception("Source file does not exist: {$sourcePath}");
    }

    // Generate target path
    $targetPath = PathHelper::generatePath([
      'filename' => PathHelper::getFilename($sourcePath),
      'file_id' => $file['id'],
      'mime_type' => $file['mime_type'],
    ]);

    // Read from source
    $stream = $sourceStorage->readStream($sourcePath);

    try {
      // Write to target
      $targetStorageAdapter->write($targetPath, $stream, [
        'mime_type' => $file['mime_type'],
      ]);
    }
    finally {
      if (is_resource($stream)) {
        fclose($stream);
      }
    }

    // Verify if requested
    if ($verify) {
      if (!$targetStorageAdapter->exists($targetPath)) {
        throw new CRM_Core_Exception("File verification failed: target file does not exist");
      }

      $sourceSize = $sourceStorage->getSize($sourcePath);
      $targetSize = $targetStorageAdapter->getSize($targetPath);

      if ($sourceSize !== $targetSize) {
        throw new CRM_Core_Exception(
          "File verification failed: size mismatch (source: {$sourceSize}, target: {$targetSize})"
        );
      }
    }

    // Update database record
    \Civi\Api4\File::update(FALSE)
      ->addWhere('id', '=', $file['id'])
      ->addValue('storage_type', $targetStorage)
      ->addValue('storage_path', $targetPath)
      ->addValue('sync_status', 'synced')
      ->addValue('last_sync_date', date('Y-m-d H:i:s'))
      ->execute();

    // Delete from source if requested
    if ($deleteSource) {
      try {
        $sourceStorage->delete($sourcePath);
      }
      catch (\Exception $e) {
        \Civi::log()->warning(
          "Failed to delete source file after migration: " . $e->getMessage()
        );
        // Don't fail the migration if cleanup fails
      }
    }

    return TRUE;
  }

  /**
   * Verify migrated files.
   *
   * Checks that files exist in target storage and match source.
   *
   * @param array $criteria Verification criteria
   *
   * @return array Verification results:
   *   - 'checked' => int
   *   - 'valid' => int
   *   - 'invalid' => int
   *   - 'errors' => array
   *
   * @throws CRM_Core_Exception
   */
  public static function verifyMigration(array $criteria): array {
    $results = [
      'checked' => 0,
      'valid' => 0,
      'invalid' => 0,
      'errors' => [],
    ];

    // Build query for files on target storage
    $query = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('storage_type', '=', $criteria['target_storage'])
      ->setLimit($criteria['batch_size'] ?? 100);

    $files = $query->execute();

    foreach ($files as $file) {
      $results['checked']++;

      try {
        $storage = StorageFactory::getAdapterForFile($file['id']);

        // Check file exists
        if (!$storage->exists($file['storage_path'])) {
          $results['invalid']++;
          $results['errors'][$file['id']] = "File does not exist in storage";
          continue;
        }

        // Optionally check file size matches
        if (!empty($file['size'])) {
          $actualSize = $storage->getSize($file['storage_path']);
          if ($actualSize != $file['size']) {
            $results['invalid']++;
            $results['errors'][$file['id']] = "Size mismatch";
            continue;
          }
        }

        $results['valid']++;
      }
      catch (\Exception $e) {
        $results['invalid']++;
        $results['errors'][$file['id']] = $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * Rollback a migration.
   *
   * Attempts to move files back to their original storage.
   * Only works if source files still exist.
   *
   * @param array $criteria Rollback criteria
   * @param array $options Rollback options
   *
   * @return array Rollback results
   *
   * @throws CRM_Core_Exception
   */
  public static function rollbackMigration(array $criteria, array $options = []): array {
    $results = [
      'processed' => 0,
      'success' => 0,
      'failed' => 0,
      'errors' => [],
    ];

    // Query files with migration metadata
    $query = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('storage_type', '=', $criteria['current_storage'])
      ->setLimit($options['batch_size'] ?? 50);

    $files = $query->execute();

    foreach ($files as $file) {
      $results['processed']++;

      try {
        // Get original storage from metadata
        $metadata = !empty($file['storage_metadata'])
          ? json_decode($file['storage_metadata'], TRUE)
          : [];

        $originalStorage = $metadata['original_storage'] ?? NULL;

        if (!$originalStorage) {
          throw new CRM_Core_Exception("Original storage not found in metadata");
        }

        // Migrate back
        FileService::copyToStorage($file['id'], $originalStorage, FALSE);

        $results['success']++;
      }
      catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][$file['id']] = $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * Estimate storage cost for a given size.
   *
   * @param string $storageType Storage type
   * @param int $sizeBytes Size in bytes
   *
   * @return float Estimated monthly cost in USD
   */
  private static function estimateStorageCost(string $storageType, int $sizeBytes): float {
    $sizeGB = $sizeBytes / (1024 * 1024 * 1024);

    // Rough cost estimates per GB/month
    $costPerGB = [
      's3' => 0.023,        // AWS S3 Standard
      'spaces' => 0.02,     // DigitalOcean Spaces
      'gcs' => 0.020,       // Google Cloud Storage Standard
      'azure' => 0.018,     // Azure Blob Storage Hot
      'local' => 0.10,      // Assuming server storage costs more
    ];

    $rate = $costPerGB[$storageType] ?? 0.02;

    return round($sizeGB * $rate, 2);
  }

  /**
   * Get migration history.
   *
   * Returns log of past migrations.
   *
   * @param int $limit Number of records to return
   *
   * @return array Migration history
   *
   * @throws CRM_Core_Exception
   */
  public static function getMigrationHistory(int $limit = 50): array {
    $logs = \Civi\Api4\FilestorageSyncLog::get(FALSE)
      ->addSelect('*')
      ->addWhere('operation', '=', 'migrate')
      ->addOrderBy('sync_date', 'DESC')
      ->setLimit($limit)
      ->execute();

    return $logs->getArrayCopy();
  }

  /**
   * Generate migration report.
   *
   * Creates a detailed report of current file storage distribution
   * and recommendations.
   *
   * @return array Migration report
   *
   * @throws CRM_Core_Exception
   */
  public static function generateReport(): array {
    $report = [
      'summary' => [],
      'by_storage' => [],
      'by_entity' => [],
      'recommendations' => [],
    ];

    // Get overall statistics
    $stats = FileService::getStatistics();
    $report['summary'] = $stats;

    // Analyze by storage type
    foreach ($stats['by_storage'] as $storageType => $count) {
      $report['by_storage'][$storageType] = [
        'count' => $count,
        'percentage' => round(($count / $stats['total_files']) * 100, 1),
      ];
    }

    // Generate recommendations
    $report['recommendations'] = self::generateRecommendations($stats);

    return $report;
  }

  /**
   * Generate migration recommendations.
   *
   * @param array $stats Current statistics
   *
   * @return array Recommendations
   */
  private static function generateRecommendations(array $stats): array {
    $recommendations = [];

    // Check if too many files on local storage
    $localCount = $stats['by_storage']['local'] ?? 0;
    $totalCount = $stats['total_files'];

    if ($totalCount > 0 && ($localCount / $totalCount) > 0.5) {
      $recommendations[] = [
        'type' => 'cost_savings',
        'priority' => 'high',
        'title' => 'High local storage usage',
        'description' => sprintf(
          '%d files (%d%%) are on local storage. Consider migrating to cloud storage for cost savings.',
          $localCount,
          round(($localCount / $totalCount) * 100)
        ),
        'action' => 'Migrate large files to S3/Spaces',
      ];
    }

    // Check for failed syncs
    $failedCount = \Civi\Api4\File::get(FALSE)
      ->addWhere('sync_status', '=', 'failed')
      ->execute()
      ->count();

    if ($failedCount > 0) {
      $recommendations[] = [
        'type' => 'maintenance',
        'priority' => 'medium',
        'title' => 'Failed sync operations',
        'description' => sprintf(
          '%d files have failed sync status. These should be reviewed and retried.',
          $failedCount
        ),
        'action' => 'Run sync job with mode=failed',
      ];
    }

    return $recommendations;
  }

  /**
   * Create migration snapshot.
   *
   * Saves current state before migration for rollback purposes.
   *
   * @param string $name Snapshot name
   *
   * @return array Snapshot data
   *
   * @throws CRM_Core_Exception
   */
  public static function createSnapshot(string $name): array {
    $snapshot = [
      'name' => $name,
      'created_at' => date('Y-m-d H:i:s'),
      'files' => [],
    ];

    // Get all files with current storage info
    $files = \Civi\Api4\File::get(FALSE)
      ->addSelect('id', 'storage_type', 'storage_path', 'uri')
      ->execute();

    foreach ($files as $file) {
      $snapshot['files'][$file['id']] = [
        'storage_type' => $file['storage_type'],
        'storage_path' => $file['storage_path'],
        'uri' => $file['uri'],
      ];
    }

    // Save snapshot to settings
    $snapshots = \Civi::settings()->get('filestorage_snapshots') ?? [];
    $snapshots[$name] = $snapshot;
    \Civi::settings()->set('filestorage_snapshots', $snapshots);

    return $snapshot;
  }

  /**
   * Restore from migration snapshot.
   *
   * @param string $name Snapshot name
   *
   * @return array Restore results
   *
   * @throws CRM_Core_Exception
   */
  public static function restoreSnapshot(string $name): array {
    $snapshots = \Civi::settings()->get('filestorage_snapshots') ?? [];

    if (!isset($snapshots[$name])) {
      throw new CRM_Core_Exception("Snapshot not found: {$name}");
    }

    $snapshot = $snapshots[$name];
    $results = [
      'restored' => 0,
      'failed' => 0,
      'errors' => [],
    ];

    foreach ($snapshot['files'] as $fileId => $fileData) {
      try {
        \Civi\Api4\File::update(FALSE)
          ->addWhere('id', '=', $fileId)
          ->addValue('storage_type', $fileData['storage_type'])
          ->addValue('storage_path', $fileData['storage_path'])
          ->addValue('uri', $fileData['uri'])
          ->execute();

        $results['restored']++;
      }
      catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][$fileId] = $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * List available snapshots.
   *
   * @return array Array of snapshots
   */
  public static function listSnapshots(): array {
    $snapshots = \Civi::settings()->get('filestorage_snapshots') ?? [];

    $list = [];
    foreach ($snapshots as $name => $snapshot) {
      $list[] = [
        'name' => $name,
        'created_at' => $snapshot['created_at'],
        'file_count' => count($snapshot['files']),
      ];
    }

    return $list;
  }

  /**
   * Delete a snapshot.
   *
   * @param string $name Snapshot name
   *
   * @return bool TRUE on success
   *
   * @throws CRM_Core_Exception
   */
  public static function deleteSnapshot(string $name): bool {
    $snapshots = \Civi::settings()->get('filestorage_snapshots') ?? [];

    if (!isset($snapshots[$name])) {
      throw new CRM_Core_Exception("Snapshot not found: {$name}");
    }

    unset($snapshots[$name]);
    \Civi::settings()->set('filestorage_snapshots', $snapshots);

    return TRUE;
  }

  /**
   * Calculate migration progress.
   *
   * @param string $targetStorage Target storage type
   *
   * @return array Progress information
   *
   * @throws CRM_Core_Exception
   */
  public static function getProgress(string $targetStorage): array {
    $total = \Civi\Api4\File::get(FALSE)
      ->addWhere('storage_type', '!=', $targetStorage)
      ->selectRowCount()
      ->execute()
      ->count();

    $completed = \Civi\Api4\File::get(FALSE)
      ->addWhere('storage_type', '=', $targetStorage)
      ->selectRowCount()
      ->execute()
      ->count();

    $remaining = $total;
    $totalAll = $total + $completed;
    $percentage = $totalAll > 0 ? round(($completed / $totalAll) * 100, 1) : 0;

    return [
      'total' => $totalAll,
      'completed' => $completed,
      'remaining' => $remaining,
      'percentage' => $percentage,
      'target_storage' => $targetStorage,
    ];
  }

  /**
   * Estimate time remaining for migration.
   *
   * @param string $targetStorage Target storage type
   *
   * @return array Time estimate
   *
   * @throws CRM_Core_Exception
   */
  public static function estimateTimeRemaining(string $targetStorage): array {
    $progress = self::getProgress($targetStorage);

    $recentLogs = \Civi\Api4\FilestorageSyncLog::get(FALSE)
      ->addSelect('duration_ms')
      ->addWhere('target_storage', '=', $targetStorage)
      ->addWhere('status', '=', 'success')
      ->addWhere('duration_ms', 'IS NOT NULL')
      ->addOrderBy('sync_date', 'DESC')
      ->setLimit(100)
      ->execute();

    $totalDuration = 0;
    $count = 0;

    foreach ($recentLogs as $log) {
      $totalDuration += $log['duration_ms'];
      $count++;
    }

    $avgTimePerFile = $count > 0 ? ($totalDuration / $count) / 1000 : 2;
    $estimatedSeconds = (int)($progress['remaining'] * $avgTimePerFile);
    $estimatedHours = round($estimatedSeconds / 3600, 2);

    return [
      'remaining_files' => $progress['remaining'],
      'avg_time_per_file' => round($avgTimePerFile, 2),
      'estimated_seconds' => $estimatedSeconds,
      'estimated_hours' => $estimatedHours,
      'estimated_completion' => date('Y-m-d H:i:s', time() + $estimatedSeconds),
    ];
  }

  /**
   * Get failed files with error details.
   *
   * @param int $limit Number of records to return
   *
   * @return array Array of failed files with errors
   *
   * @throws CRM_Core_Exception
   */
  public static function getFailedFiles(int $limit = 50): array {
    $files = \Civi\Api4\File::get(FALSE)
      ->addSelect('id', 'uri', 'mime_type', 'storage_type', 'upload_date')
      ->addWhere('sync_status', '=', 'failed')
      ->setLimit($limit)
      ->execute();

    $result = [];

    foreach ($files as $file) {
      $log = \Civi\Api4\FilestorageSyncLog::get(FALSE)
        ->addSelect('error_message', 'sync_date')
        ->addWhere('file_id', '=', $file['id'])
        ->addWhere('status', '=', 'failed')
        ->addOrderBy('sync_date', 'DESC')
        ->setLimit(1)
        ->execute()
        ->first();

      $result[] = [
        'file_id' => $file['id'],
        'uri' => $file['uri'],
        'mime_type' => $file['mime_type'],
        'storage_type' => $file['storage_type'],
        'upload_date' => $file['upload_date'],
        'error' => $log['error_message'] ?? 'Unknown error',
        'last_attempt' => $log['sync_date'] ?? NULL,
      ];
    }

    return $result;
  }

  /**
   * Retry failed files.
   *
   * @param string $targetStorage Target storage type
   * @param int $limit Number of files to retry
   *
   * @return array Retry results
   *
   * @throws CRM_Core_Exception
   */
  public static function retryFailed(string $targetStorage, int $limit = 50): array {
    $files = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('sync_status', '=', 'failed')
      ->setLimit($limit)
      ->execute();

    $results = [
      'processed' => 0,
      'success' => 0,
      'failed' => 0,
      'errors' => [],
    ];

    foreach ($files as $file) {
      $results['processed']++;

      try {
        self::migrateFile($file, $targetStorage, FALSE, TRUE);
        $results['success']++;
      }
      catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][$file['id']] = $e->getMessage();
      }
    }

    return $results;
  }
}