<?php

use Civi\Filestorage\Storage\StorageFactory;
use CRM_Filestorage_ExtensionUtil as E;

/**
 * Scheduled job for syncing files to remote storage.
 *
 * This job handles syncing files that may have been missed by the hooks,
 * such as:
 * - Files created through direct database operations
 * - Files uploaded via batch imports
 * - Legacy files not yet migrated
 * - Files where the hook failed but the operation completed
 * - Files created by other extensions
 *
 * The job can run in different modes:
 * - 'pending': Sync only files with sync_status = 'pending'
 * - 'failed': Retry files with sync_status = 'failed'
 * - 'verify': Verify all files exist in their configured storage
 * - 'all': Process all files regardless of status
 *
 * @package CRM_Filestorage_Job
 */
class CRM_Filestorage_Job_SyncFiles {

  /**
   * Maximum number of files to process in a single job run.
   * Prevents timeout on large installations.
   *
   * @var int
   */
  const BATCH_SIZE = 100;

  /**
   * Maximum file size for synchronous processing (in bytes).
   * Files larger than this will be queued for background processing.
   * Default: 50MB
   *
   * @var int
   */
  const MAX_SYNC_FILE_SIZE = 52428800;

  /**
   * Main job execution method called by CiviCRM scheduler.
   *
   * This method is the entry point for the scheduled job. It processes
   * files in batches to avoid timeouts and memory issues.
   *
   * @param array $params Job parameters:
   *   - 'mode' => string - Sync mode: 'pending', 'failed', 'verify', 'all'
   *   - 'batch_size' => int - Number of files to process (default: 100)
   *   - 'target_storage' => string - Target storage type (default: from settings)
   *   - 'file_types' => array - Limit to specific file type IDs
   *   - 'entity_types' => array - Limit to specific entity types
   *   - 'days_old' => int - Only sync files older than X days
   *
   * @return array Result array with:
   *   - 'is_error' => int (0 or 1)
   *   - 'values' => array with statistics
   *   - 'error_message' => string (if error occurred)
   */
  public static function run($params) {
    $mode = $params['mode'] ?? 'pending';
    $batchSize = $params['batch_size'] ?? self::BATCH_SIZE;
    $targetStorage = $params['target_storage'] ?? NULL;

    $stats = [
      'processed' => 0,
      'success' => 0,
      'failed' => 0,
      'skipped' => 0,
      'total_size' => 0,
      'duration_ms' => 0,
    ];

    $startTime = microtime(TRUE);

    try {
      // Get files to sync based on mode
      $files = self::getFilesToSync($mode, $batchSize, $params);

      if (empty($files)) {
        return civicrm_api3_create_success([
          'message' => 'No files to sync',
          'stats' => $stats,
        ]);
      }

      // Determine target storage
      if (!$targetStorage) {
        $targetStorage = StorageFactory::getDefaultAdapter()->getType();
      }

      // Process each file
      foreach ($files as $file) {
        $stats['processed']++;

        try {
          $result = self::syncFile($file, $targetStorage, $mode);

          if ($result['status'] === 'success') {
            $stats['success']++;
            $stats['total_size'] += $result['size'] ?? 0;
          }
          elseif ($result['status'] === 'skipped') {
            $stats['skipped']++;
          }
          else {
            $stats['failed']++;
          }
        }
        catch (Exception $e) {
          $stats['failed']++;

          // Log the error
          self::logSyncOperation($file['id'], [
            'operation' => 'sync',
            'source_storage' => $file['storage_type'] ?? 'local',
            'target_storage' => $targetStorage,
            'status' => 'failed',
            'error_message' => $e->getMessage(),
          ]);

          // Update file sync status
          self::updateFileSyncStatus($file['id'], 'failed', $e->getMessage());
        }
      }

      $stats['duration_ms'] = round((microtime(TRUE) - $startTime) * 1000);

      return civicrm_api3_create_success([
        'message' => sprintf(
          'Synced %d files (%d success, %d failed, %d skipped) in %d ms',
          $stats['processed'],
          $stats['success'],
          $stats['failed'],
          $stats['skipped'],
          $stats['duration_ms']
        ),
        'stats' => $stats,
      ]);
    }
    catch (Exception $e) {
      return civicrm_api3_create_error($e->getMessage());
    }
  }

  /**
   * Get files that need to be synced based on the mode.
   *
   * Queries the database for files matching the sync criteria.
   * Uses efficient queries with proper indexing.
   *
   * @param string $mode Sync mode
   * @param int $limit Maximum number of files to return
   * @param array $filters Additional filters
   *
   * @return array Array of file records
   */
  private static function getFilesToSync(string $mode, int $limit, array $filters): array {
    $query = \Civi\Api4\File::get(FALSE)
      ->addSelect('id', 'uri', 'mime_type', 'storage_type', 'storage_path',
        'storage_metadata', 'sync_status', 'last_sync_date')
      ->setLimit($limit);

    // Apply mode-specific filters
    switch ($mode) {
      case 'pending':
        // Files never synced or marked as pending
        $query->addClause('OR',
          ['sync_status', '=', 'pending'],
          ['sync_status', 'IS NULL']
        );
        break;

      case 'failed':
        // Retry failed syncs
        $query->addWhere('sync_status', '=', 'failed');
        break;

      case 'verify':
        // Check files that should be synced
        $query->addWhere('sync_status', '=', 'synced');
        break;

      case 'all':
        // Process everything except excluded files
        $query->addWhere('sync_status', '!=', 'excluded');
        break;

      default:
        throw new CRM_Core_Exception("Invalid sync mode: {$mode}");
    }

    // Apply additional filters
    if (!empty($filters['file_types'])) {
      $query->addWhere('file_type_id', 'IN', $filters['file_types']);
    }

    if (!empty($filters['days_old'])) {
      $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$filters['days_old']} days"));
      $query->addWhere('upload_date', '<', $dateThreshold);
    }

    // Filter by entity type if specified
    if (!empty($filters['entity_types'])) {
      // Need to join with civicrm_entity_file to filter by entity type
      $query->addJoin('EntityFile AS entity_file', 'LEFT')
        ->addWhere('entity_file.entity_table', 'IN', $filters['entity_types']);
    }

    // Order by upload date (oldest first) to ensure FIFO processing
    $query->addOrderBy('upload_date', 'ASC');

    return $query->execute()->getArrayCopy();
  }

  /**
   * Sync a single file to the target storage.
   *
   * This is the core sync logic that:
   * 1. Determines source and target storage
   * 2. Reads file from source
   * 3. Writes file to target
   * 4. Updates database record
   * 5. Logs the operation
   *
   * @param array $file File record from database
   * @param string $targetStorageType Target storage type
   * @param string $mode Sync mode (affects behavior)
   *
   * @return array Result with 'status' and optional 'size', 'error'
   */
  private static function syncFile(array $file, string $targetStorageType, string $mode): array {
    $fileId = $file['id'];
    $startTime = microtime(TRUE);

    // Determine source storage
    $sourceStorageType = $file['storage_type'] ?? 'local';

    // Skip if already on target storage
    if ($sourceStorageType === $targetStorageType && $mode !== 'verify') {
      self::updateFileSyncStatus($fileId, 'skipped', 'Already on target storage');
      return ['status' => 'skipped'];
    }

    // Get storage adapters
    $sourceStorage = StorageFactory::getAdapter($sourceStorageType);
    $targetStorage = StorageFactory::getAdapter($targetStorageType);

    // Determine source path
    $sourcePath = self::getSourcePath($file, $sourceStorage);

    // Check if source file exists
    if (!$sourceStorage->exists($sourcePath)) {
      $error = "Source file does not exist: {$sourcePath}";
      self::updateFileSyncStatus($fileId, 'failed', $error);

      self::logSyncOperation($fileId, [
        'operation' => 'sync',
        'source_storage' => $sourceStorageType,
        'target_storage' => $targetStorageType,
        'status' => 'failed',
        'error_message' => $error,
      ]);

      return ['status' => 'failed', 'error' => $error];
    }

    // In verify mode, just check existence and return
    if ($mode === 'verify') {
      if ($targetStorage->exists($file['storage_path'])) {
        self::updateFileSyncStatus($fileId, 'synced', NULL);
        return ['status' => 'success'];
      }
      else {
        self::updateFileSyncStatus($fileId, 'failed', 'File missing from target storage');
        return ['status' => 'failed', 'error' => 'File missing'];
      }
    }

    try {
      // Get file size
      $fileSize = $sourceStorage->getSize($sourcePath);

      // Check if file is too large for synchronous processing
      if ($fileSize > self::MAX_SYNC_FILE_SIZE) {
        // Queue for background processing
        self::queueLargeFile($fileId, $sourceStorageType, $targetStorageType);
        return ['status' => 'skipped', 'reason' => 'queued_for_background'];
      }

      // Generate target path
      $targetPath = self::generateTargetPath($file);

      // Read from source (use stream for memory efficiency)
      $stream = $sourceStorage->readStream($sourcePath);

      // Write to target
      $writeConfig = [
        'mime_type' => $file['mime_type'] ?? 'application/octet-stream',
        'visibility' => self::determineVisibility($file),
      ];

      $targetStorage->write($targetPath, $stream, $writeConfig);

      // Close stream
      if (is_resource($stream)) {
        fclose($stream);
      }

      // Update file record in database
      $metadata = [
        'config_name' => $targetStorage->getConfig()['config_name'] ?? NULL,
        'synced_at' => date('Y-m-d H:i:s'),
        'original_storage' => $sourceStorageType,
      ];

      \Civi\Api4\File::update(FALSE)
        ->addWhere('id', '=', $fileId)
        ->addValue('storage_type', $targetStorageType)
        ->addValue('storage_path', $targetPath)
        ->addValue('storage_metadata', json_encode($metadata))
        ->addValue('sync_status', 'synced')
        ->addValue('last_sync_date', date('Y-m-d H:i:s'))
        ->execute();

      // Log successful sync
      $duration = round((microtime(TRUE) - $startTime) * 1000);

      self::logSyncOperation($fileId, [
        'operation' => 'sync',
        'source_storage' => $sourceStorageType,
        'target_storage' => $targetStorageType,
        'status' => 'success',
        'file_size' => $fileSize,
        'duration_ms' => $duration,
      ]);

      // Optionally delete from source if configured
      if (self::shouldDeleteFromSource($sourceStorageType, $targetStorageType)) {
        try {
          $sourceStorage->delete($sourcePath);
        }
        catch (Exception $e) {
          // Log but don't fail the sync if deletion fails
          \Civi::log()->warning("Failed to delete source file after sync: " . $e->getMessage());
        }
      }

      return [
        'status' => 'success',
        'size' => $fileSize,
        'duration_ms' => $duration,
      ];
    }
    catch (Exception $e) {
      // Log failed sync
      self::logSyncOperation($fileId, [
        'operation' => 'sync',
        'source_storage' => $sourceStorageType,
        'target_storage' => $targetStorageType,
        'status' => 'failed',
        'error_message' => $e->getMessage(),
      ]);

      // Update file status
      self::updateFileSyncStatus($fileId, 'failed', $e->getMessage());

      throw $e;
    }
  }

  /**
   * Get the source path for a file from its record.
   *
   * Handles both legacy files (uri field) and new files (storage_path field).
   *
   * @param array $file File record
   * @param StorageInterface $storage Storage adapter
   *
   * @return string Source path
   */
  private static function getSourcePath(array $file, $storage): string {
    // If storage_path is set, use it
    if (!empty($file['storage_path'])) {
      return $file['storage_path'];
    }

    // Otherwise use uri field (legacy files)
    if (!empty($file['uri'])) {
      return $file['uri'];
    }

    throw new CRM_Core_Exception("File {$file['id']} has no path information");
  }

  /**
   * Generate target path for a file in the destination storage.
   *
   * Creates an organized path structure:
   * - By entity type
   * - By year/month of upload
   * - With unique filename
   *
   * @param array $file File record
   *
   * @return string Target path
   */
  private static function generateTargetPath(array $file): string {
    // Get upload date
    $uploadDate = strtotime($file['upload_date'] ?? 'now');
    $year = date('Y', $uploadDate);
    $month = date('m', $uploadDate);

    // Get entity type from entity_file join (if available)
    $entityType = self::getEntityTypeForFile($file['id']);

    // Build path: {entity_type}/{year}/{month}/{filename}
    $pathParts = [
      $entityType ?? 'files',
      $year,
      $month,
    ];

    // Generate unique filename (preserve original if possible)
    $filename = basename($file['uri'] ?? "file_{$file['id']}");

    // Add hash to prevent collisions
    $hash = substr(md5($file['id'] . $filename), 0, 8);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = pathinfo($filename, PATHINFO_FILENAME);

    $uniqueFilename = "{$basename}_{$hash}";
    if ($extension) {
      $uniqueFilename .= ".{$extension}";
    }

    $pathParts[] = $uniqueFilename;

    return implode('/', $pathParts);
  }

  /**
   * Get the entity type for a file.
   *
   * Queries civicrm_entity_file to determine what type of entity
   * this file is attached to (Activity, Contact, etc.).
   *
   * @param int $fileId File ID
   *
   * @return string|null Entity type or NULL
   */
  private static function getEntityTypeForFile(int $fileId): ?string {
    try {
      $entityFile = \Civi\Api4\EntityFile::get(FALSE)
        ->addSelect('entity_table')
        ->addWhere('file_id', '=', $fileId)
        ->setLimit(1)
        ->execute()
        ->first();

      if ($entityFile) {
        // Convert table name to friendly name: civicrm_activity -> activity
        return str_replace('civicrm_', '', $entityFile['entity_table']);
      }
    }
    catch (Exception $e) {
      // Ignore errors, just return NULL
    }

    return NULL;
  }

  /**
   * Determine file visibility (public or private).
   *
   * Decides whether a file should be publicly accessible or private
   * based on entity type and file type.
   *
   * @param array $file File record
   *
   * @return string 'public' or 'private'
   */
  private static function determineVisibility(array $file): string {
    // Check settings for default visibility
    $defaultVisibility = \Civi::settings()->get('filestorage_default_visibility') ?? 'private';

    // Contact images are typically public
    $entityType = self::getEntityTypeForFile($file['id']);
    if ($entityType === 'contact') {
      return 'public';
    }

    // Check file type rules
    $visibilityRules = \Civi::settings()->get('filestorage_visibility_rules') ?? [];

    foreach ($visibilityRules as $rule) {
      if (!empty($rule['entity_types']) && in_array($entityType, $rule['entity_types'])) {
        return $rule['visibility'] ?? $defaultVisibility;
      }
    }

    return $defaultVisibility;
  }

  /**
   * Queue a large file for background processing.
   *
   * Creates a queue task for processing files that are too large
   * to handle in the scheduled job.
   *
   * @param int $fileId File ID
   * @param string $sourceStorage Source storage type
   * @param string $targetStorage Target storage type
   */
  private static function queueLargeFile(int $fileId, string $sourceStorage, string $targetStorage): void {
    // Mark as pending but note it's queued
    self::updateFileSyncStatus($fileId, 'pending', 'Queued for background processing');

    // Create queue item (if CiviCRM queue API available)
    try {
      $queue = \Civi::queue('filestorage_large_files', [
        'type' => 'Sql',
        'runner' => 'task',
      ]);

      $queue->createItem(new \CRM_Queue_Task(
        [__CLASS__, 'processLargeFileTask'],
        [$fileId, $sourceStorage, $targetStorage],
        "Sync large file {$fileId}"
      ));
    }
    catch (Exception $e) {
      \Civi::log()->error("Failed to queue large file {$fileId}: " . $e->getMessage());
    }
  }

  /**
   * Queue task callback for processing large files.
   *
   * This method is called by the queue runner to process large files
   * in the background.
   *
   * @param \CRM_Queue_TaskContext $ctx Queue context
   * @param int $fileId File ID
   * @param string $sourceStorage Source storage type
   * @param string $targetStorage Target storage type
   *
   * @return bool TRUE on success
   */
  public static function processLargeFileTask($ctx, int $fileId, string $sourceStorage, string $targetStorage): bool {
    try {
      // Reload file record
      $file = \Civi\Api4\File::get(FALSE)
        ->addWhere('id', '=', $fileId)
        ->execute()
        ->first();

      if (!$file) {
        throw new CRM_Core_Exception("File {$fileId} not found");
      }

      // Process the file
      self::syncFile($file, $targetStorage, 'all');

      return TRUE;
    }
    catch (Exception $e) {
      \Civi::log()->error("Failed to process large file {$fileId}: " . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Update file sync status in database.
   *
   * @param int $fileId File ID
   * @param string $status Sync status
   * @param string|null $error Error message if failed
   */
  private static function updateFileSyncStatus(int $fileId, string $status, ?string $error = NULL): void {
    try {
      $update = \Civi\Api4\File::update(FALSE)
        ->addWhere('id', '=', $fileId)
        ->addValue('sync_status', $status);

      if ($status === 'synced') {
        $update->addValue('last_sync_date', date('Y-m-d H:i:s'));
      }

      $update->execute();
    }
    catch (Exception $e) {
      // Log but don't throw - we don't want status update failures to break the job
      \Civi::log()->warning("Failed to update sync status for file {$fileId}: " . $e->getMessage());
    }
  }

  /**
   * Log a sync operation to the audit table.
   *
   * Records detailed information about each sync operation for
   * troubleshooting and auditing purposes.
   *
   * @param int $fileId File ID
   * @param array $data Log data
   */
  private static function logSyncOperation(int $fileId, array $data): void {
    try {
      \Civi\Api4\FilestorageSyncLog::create(FALSE)
        ->addValue('file_id', $fileId)
        ->addValue('operation', $data['operation'] ?? 'sync')
        ->addValue('source_storage', $data['source_storage'] ?? NULL)
        ->addValue('target_storage', $data['target_storage'] ?? NULL)
        ->addValue('status', $data['status'])
        ->addValue('error_message', $data['error_message'] ?? NULL)
        ->addValue('file_size', $data['file_size'] ?? NULL)
        ->addValue('duration_ms', $data['duration_ms'] ?? NULL)
        ->addValue('sync_date', date('Y-m-d H:i:s'))
        ->execute();
    }
    catch (Exception $e) {
      // Log to CiviCRM log if database logging fails
      \Civi::log()->warning("Failed to log sync operation for file {$fileId}: " . $e->getMessage());
    }
  }

  /**
   * Determine if source file should be deleted after successful sync.
   *
   * @param string $sourceStorage Source storage type
   * @param string $targetStorage Target storage type
   *
   * @return bool TRUE if should delete
   */
  private static function shouldDeleteFromSource(string $sourceStorage, string $targetStorage): bool {
    // Never delete if source and target are the same
    if ($sourceStorage === $targetStorage) {
      return FALSE;
    }

    // Check global setting
    $deleteAfterSync = \Civi::settings()->get('filestorage_delete_after_sync') ?? FALSE;

    // Check storage-specific setting
    $deleteRules = \Civi::settings()->get('filestorage_delete_rules') ?? [];

    foreach ($deleteRules as $rule) {
      if ($rule['from'] === $sourceStorage && $rule['to'] === $targetStorage) {
        return $rule['delete'] ?? $deleteAfterSync;
      }
    }

    return $deleteAfterSync;
  }
}