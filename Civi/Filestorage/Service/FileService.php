<?php

namespace Civi\Filestorage\Service;

use Civi\Filestorage\Storage\StorageFactory;
use Civi\Filestorage\Util\PathHelper;
use Civi\Filestorage\Util\UrlGenerator;
use CRM_Core_Exception;

/**
 * File Service Class.
 *
 * High-level service for file operations. Provides a simplified API
 * for common file tasks that abstracts away storage backend details.
 *
 * This service handles:
 * - File uploads with automatic storage selection
 * - File downloads with URL generation
 * - File metadata management
 * - Batch file operations
 * - File validation and security
 *
 * @package Civi\Filestorage\Service
 */
class FileService {

  /**
   * Upload a file to appropriate storage.
   *
   * This is the main method for uploading files. It determines the best
   * storage backend based on rules, uploads the file, and creates the
   * database record.
   *
   * @param array $params Upload parameters:
   *   - 'file_path' => string - Path to local file (required)
   *   - 'filename' => string - Original filename (optional, derived from file_path)
   *   - 'mime_type' => string - MIME type (optional, auto-detected)
   *   - 'entity_type' => string - Entity type (e.g., 'activity', 'contact')
   *   - 'entity_id' => int - Entity ID
   *   - 'file_type_id' => int - File type option value
   *   - 'description' => string - File description
   *   - 'storage_type' => string - Force specific storage (optional)
   *
   * @return array File record with:
   *   - 'id' => int - CiviCRM file ID
   *   - 'uri' => string - Original URI
   *   - 'storage_type' => string - Storage type used
   *   - 'storage_path' => string - Path in storage
   *   - 'url' => string - Download URL
   *
   * @throws CRM_Core_Exception If upload fails
   */
  public static function upload(array $params): array {
    // Validate required parameters
    if (empty($params['file_path'])) {
      throw new CRM_Core_Exception("Parameter 'file_path' is required");
    }

    $filePath = $params['file_path'];

    // Check if file exists
    if (!file_exists($filePath)) {
      throw new CRM_Core_Exception("File not found: {$filePath}");
    }

    // Get file info
    $fileSize = filesize($filePath);
    $filename = $params['filename'] ?? basename($filePath);
    $mimeType = $params['mime_type'] ?? mime_content_type($filePath);

    // Determine storage backend
    if (!empty($params['storage_type'])) {
      // Use specified storage
      $storage = StorageFactory::getAdapter($params['storage_type']);
    }
    else {
      // Use rules to determine storage
      $fileInfo = [
        'mime_type' => $mimeType,
        'size' => $fileSize,
        'entity_type' => $params['entity_type'] ?? NULL,
        'file_type_id' => $params['file_type_id'] ?? NULL,
      ];
      $storage = StorageFactory::getAdapterForNewFile($fileInfo);
    }

    // Generate storage path
    $storagePath = PathHelper::generatePath([
      'filename' => $filename,
      'entity_type' => $params['entity_type'] ?? NULL,
      'entity_id' => $params['entity_id'] ?? NULL,
      'mime_type' => $mimeType,
      'upload_date' => date('Y-m-d H:i:s'),
    ]);

    // Upload to storage
    $stream = fopen($filePath, 'rb');

    $uploadConfig = [
      'mime_type' => $mimeType,
      'visibility' => $params['visibility'] ?? 'private',
      'metadata' => [
        'original_filename' => $filename,
        'uploaded_by' => \CRM_Core_Session::getLoggedInContactID(),
      ],
    ];

    try {
      $storage->write($storagePath, $stream, $uploadConfig);
    }
    finally {
      if (is_resource($stream)) {
        fclose($stream);
      }
    }

    // Create file record in database
    $fileParams = [
      'uri' => $filePath,
      'mime_type' => $mimeType,
      'storage_type' => $storage->getType(),
      'storage_path' => $storagePath,
      'storage_metadata' => json_encode([
        'original_filename' => $filename,
        'uploaded_at' => date('Y-m-d H:i:s'),
      ]),
      'upload_date' => date('Y-m-d H:i:s'),
      'sync_status' => 'synced',
      'last_sync_date' => date('Y-m-d H:i:s'),
    ];

    if (!empty($params['file_type_id'])) {
      $fileParams['file_type_id'] = $params['file_type_id'];
    }
    if (!empty($params['description'])) {
      $fileParams['description'] = $params['description'];
    }

    $file = \Civi\Api4\File::create(FALSE)
      ->setValues($fileParams)
      ->execute()
      ->first();

    // Generate download URL
    $file['url'] = UrlGenerator::getDownloadUrl($file['id']);

    return $file;
  }

  /**
   * Download a file from storage.
   *
   * Retrieves file content from its storage location.
   *
   * @param int $fileId CiviCRM file ID
   * @param string|null $destination Local path to save file (optional)
   *
   * @return string File content (if no destination specified)
   *
   * @throws CRM_Core_Exception If file not found or download fails
   */
  public static function download(int $fileId, ?string $destination = NULL): string {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    // Get storage adapter
    $storage = StorageFactory::getAdapterForFile($fileId);

    // Read file content
    if ($destination) {
      // Save to destination
      $stream = $storage->readStream($file['storage_path']);
      $destStream = fopen($destination, 'wb');

      stream_copy_to_stream($stream, $destStream);

      fclose($stream);
      fclose($destStream);

      return $destination;
    }
    else {
      // Return content
      return $storage->read($file['storage_path']);
    }
  }

  /**
   * Delete a file from storage and database.
   *
   * @param int $fileId CiviCRM file ID
   * @param bool $deleteFromStorage Whether to delete from storage (default: true)
   *
   * @return bool TRUE on success
   *
   * @throws CRM_Core_Exception If deletion fails
   */
  public static function delete(int $fileId, bool $deleteFromStorage = TRUE): bool {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    // Delete from storage if requested
    if ($deleteFromStorage && !empty($file['storage_path'])) {
      try {
        $storage = StorageFactory::getAdapterForFile($fileId);
        $storage->delete($file['storage_path']);
      }
      catch (\Exception $e) {
        \Civi::log()->warning("Failed to delete file from storage: " . $e->getMessage());
        // Continue to delete database record
      }
    }

    // Delete from database
    \Civi\Api4\File::delete(FALSE)
      ->addWhere('id', '=', $fileId)
      ->execute();

    return TRUE;
  }

  /**
   * Get file metadata.
   *
   * @param int $fileId CiviCRM file ID
   * @param bool $includeStorageMetadata Include storage provider metadata
   *
   * @return array File metadata
   *
   * @throws CRM_Core_Exception If file not found
   */
  public static function getMetadata(int $fileId, bool $includeStorageMetadata = FALSE): array {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    $metadata = [
      'id' => $file['id'],
      'filename' => PathHelper::getFilename($file['storage_path'] ?? $file['uri']),
      'mime_type' => $file['mime_type'],
      'storage_type' => $file['storage_type'] ?? 'local',
      'upload_date' => $file['upload_date'],
      'description' => $file['description'] ?? NULL,
    ];

    // Get storage metadata if requested
    if ($includeStorageMetadata && !empty($file['storage_path'])) {
      try {
        $storage = StorageFactory::getAdapterForFile($fileId);
        $storageMetadata = $storage->getMetadata($file['storage_path']);

        $metadata['size'] = $storageMetadata['size'];
        $metadata['last_modified'] = $storageMetadata['last_modified'];
        $metadata['size_formatted'] = PathHelper::formatFileSize($storageMetadata['size']);
      }
      catch (\Exception $e) {
        \Civi::log()->warning("Failed to get storage metadata: " . $e->getMessage());
      }
    }

    return $metadata;
  }

  /**
   * Copy a file to a different storage backend.
   *
   * @param int $fileId CiviCRM file ID
   * @param string $targetStorageType Target storage type
   * @param bool $deleteSource Delete from source storage after copy
   *
   * @return array Updated file record
   *
   * @throws CRM_Core_Exception If copy fails
   */
  public static function copyToStorage(
    int    $fileId,
    string $targetStorageType,
    bool   $deleteSource = FALSE
  ): array {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    $sourceStorageType = $file['storage_type'] ?? 'local';

    // Check if already on target storage
    if ($sourceStorageType === $targetStorageType) {
      return $file;
    }

    // Get storage adapters
    $sourceStorage = StorageFactory::getAdapter($sourceStorageType);
    $targetStorage = StorageFactory::getAdapter($targetStorageType);

    // Read from source
    $sourcePath = $file['storage_path'] ?? $file['uri'];
    $stream = $sourceStorage->readStream($sourcePath);

    // Generate new path for target
    $targetPath = PathHelper::generatePath([
      'filename' => PathHelper::getFilename($sourcePath),
      'file_id' => $fileId,
      'mime_type' => $file['mime_type'],
    ]);

    // Write to target
    $targetStorage->write($targetPath, $stream, [
      'mime_type' => $file['mime_type'],
    ]);

    if (is_resource($stream)) {
      fclose($stream);
    }

    // Update file record
    $updateParams = [
      'storage_type' => $targetStorageType,
      'storage_path' => $targetPath,
      'sync_status' => 'synced',
      'last_sync_date' => date('Y-m-d H:i:s'),
    ];

    $updatedFile = \Civi\Api4\File::update(FALSE)
      ->addWhere('id', '=', $fileId)
      ->setValues($updateParams)
      ->execute()
      ->first();

    // Delete from source if requested
    if ($deleteSource) {
      try {
        $sourceStorage->delete($sourcePath);
      }
      catch (\Exception $e) {
        \Civi::log()->warning("Failed to delete source file after copy: " . $e->getMessage());
      }
    }

    return $updatedFile;
  }

  /**
   * Validate file upload.
   *
   * Checks file against security and business rules.
   *
   * @param array $params Upload parameters
   *
   * @return array Validation result:
   *   - 'valid' => bool
   *   - 'errors' => array
   *
   * @throws CRM_Core_Exception
   */
  public static function validateUpload(array $params): array {
    $errors = [];

    // Check file exists
    if (empty($params['file_path']) || !file_exists($params['file_path'])) {
      $errors[] = 'File does not exist';
      return ['valid' => FALSE, 'errors' => $errors];
    }

    $filePath = $params['file_path'];
    $fileSize = filesize($filePath);
    $mimeType = mime_content_type($filePath);

    // Check file size limits
    $maxSize = \Civi::settings()->get('filestorage_max_file_size') ?? (50 * 1024 * 1024); // 50MB default
    if ($fileSize > $maxSize) {
      $errors[] = sprintf(
        'File size (%s) exceeds maximum allowed (%s)',
        PathHelper::formatFileSize($fileSize),
        PathHelper::formatFileSize($maxSize)
      );
    }

    // Check allowed MIME types
    $allowedTypes = \Civi::settings()->get('filestorage_allowed_mime_types') ?? [];
    if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
      $errors[] = "File type '{$mimeType}' is not allowed";
    }

    // Check blocked MIME types
    $blockedTypes = \Civi::settings()->get('filestorage_blocked_mime_types') ?? [
      'application/x-executable',
      'application/x-sh',
      'text/x-php',
    ];
    if (in_array($mimeType, $blockedTypes)) {
      $errors[] = "File type '{$mimeType}' is blocked for security reasons";
    }

    // Validate filename
    $filename = $params['filename'] ?? basename($filePath);
    $cleanFilename = PathHelper::cleanFilename($filename);
    if ($filename !== $cleanFilename) {
      $errors[] = "Filename contains invalid characters";
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * Get statistics about file storage usage.
   *
   * @return array Storage statistics:
   *   - 'total_files' => int
   *   - 'by_storage' => array
   *   - 'total_size' => int
   *   - 'by_entity' => array
   *
   * @throws CRM_Core_Exception
   */
  public static function getStatistics(): array {
    $stats = [
      'total_files' => 0,
      'by_storage' => [],
      'total_size' => 0,
      'by_entity' => [],
    ];

    // Get file counts by storage type
    $sql = "
      SELECT 
        COALESCE(storage_type, 'local') as storage_type,
        COUNT(*) as count
      FROM civicrm_file
      GROUP BY storage_type
    ";

    $dao = \CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $stats['by_storage'][$dao->storage_type] = (int)$dao->count;
      $stats['total_files'] += (int)$dao->count;
    }

    // Get file counts by entity
    $sql = "
      SELECT 
        entity_table,
        COUNT(DISTINCT ef.file_id) as count
      FROM civicrm_entity_file ef
      INNER JOIN civicrm_file f ON ef.file_id = f.id
      GROUP BY entity_table
    ";

    $dao = \CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $entityType = str_replace('civicrm_', '', $dao->entity_table);
      $stats['by_entity'][$entityType] = (int)$dao->count;
    }

    return $stats;
  }

  /**
   * Batch update file storage for multiple files.
   *
   * @param array $fileIds Array of file IDs
   * @param string $targetStorage Target storage type
   *
   * @return array Results:
   *   - 'success' => int
   *   - 'failed' => int
   *   - 'errors' => array
   *
   * @throws CRM_Core_Exception
   */
  public static function batchUpdateStorage(array $fileIds, string $targetStorage): array {
    $results = [
      'success' => 0,
      'failed' => 0,
      'errors' => [],
    ];

    foreach ($fileIds as $fileId) {
      try {
        self::copyToStorage($fileId, $targetStorage, FALSE);
        $results['success']++;
      }
      catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][$fileId] = $e->getMessage();
      }
    }

    return $results;
  }
}