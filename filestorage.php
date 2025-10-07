<?php

require_once 'filestorage.civix.php';

use Civi\Filestorage\Storage\StorageFactory;
use CRM_Filestorage_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function filestorage_civicrm_config(&$config): void {
  _filestorage_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function filestorage_civicrm_install(): void {
  _filestorage_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function filestorage_civicrm_enable(): void {
  _filestorage_civix_civicrm_enable();
}


/**
 * Implements hook_civicrm_pre().
 *
 * This hook intercepts file operations BEFORE they are saved to the database.
 * We use this to:
 * 1. Determine which storage backend to use for new files
 * 2. Upload files to remote storage before database commit
 * 3. Update file record with storage information
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre
 */
function filestorage_civicrm_pre($op, $objectName, $id, &$params): void {
  // Only process File entity operations
  if ($objectName !== 'File') {
    return;
  }

  // Handle file creation
  if ($op === 'create') {
    try {
      // Check if file storage is enabled
      if (!\Civi::settings()->get('filestorage_enabled')) {
        return;
      }

      // Determine appropriate storage for this file
      $fileInfo = [
        'mime_type' => $params['mime_type'] ?? NULL,
        'size' => !empty($params['uri']) ? filesize($params['uri']) : 0,
        'file_type_id' => $params['file_type_id'] ?? NULL,
      ];

      $storage = StorageFactory::getAdapterForNewFile($fileInfo);

      // If not local storage, upload file now
      if ($storage->getType() !== 'local' && !empty($params['uri'])) {
        $localPath = $params['uri'];

        // Check if local file exists
        if (!file_exists($localPath)) {
          \Civi::log()->warning("File not found for upload: {$localPath}");
          return;
        }

        // Generate remote path
        $remotePath = _filestorage_generate_path($params);

        // Upload to remote storage
        $stream = fopen($localPath, 'rb');

        $writeConfig = [
          'mime_type' => $params['mime_type'] ?? 'application/octet-stream',
          'visibility' => 'private', // Default to private, can be changed later
        ];

        $storage->write($remotePath, $stream, $writeConfig);

        fclose($stream);

        // Update params with storage information
        $params['storage_type'] = $storage->getType();
        $params['storage_path'] = $remotePath;
        $params['storage_metadata'] = json_encode([
          'uploaded_at' => date('Y-m-d H:i:s'),
          'original_name' => basename($localPath),
        ]);
        $params['sync_status'] = 'synced';
        $params['last_sync_date'] = date('Y-m-d H:i:s');

        // Optionally delete local file after upload
        if (\Civi::settings()->get('filestorage_delete_local_after_upload')) {
          @unlink($localPath);
        }
      } else {
        // Local storage - mark as pending for scheduled sync if needed
        $params['storage_type'] = 'local';
        $params['sync_status'] = 'pending';
      }
    } catch (Exception $e) {
      // Log error but don't prevent file creation
      \Civi::log()->error('File storage hook error: ' . $e->getMessage());

      // Mark as failed so scheduled job can retry
      $params['sync_status'] = 'failed';
    }
  }

  // Handle file updates
  if ($op === 'edit' && $id) {
    try {
      // If file is being moved/updated, we might need to update storage
      // This is handled by the scheduled job for safety
      if (!empty($params['uri'])) {
        $params['sync_status'] = 'pending';
      }
    } catch (Exception $e) {
      \Civi::log()->error('File storage hook error on edit: ' . $e->getMessage());
    }
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * This hook runs AFTER database operations complete.
 * We use this for cleanup and logging operations that don't need to
 * block the main transaction.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post
 */
function filestorage_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  // Only process File entity operations
  if ($objectName !== 'File') {
    return;
  }

  // Handle file deletion
  if ($op === 'delete' && $objectId) {
    try {
      // Get file info before it's deleted from database
      $file = \Civi\Api4\File::get(FALSE)
        ->addSelect('storage_type', 'storage_path', 'uri')
        ->addWhere('id', '=', $objectId)
        ->execute()
        ->first();

      if ($file && !empty($file['storage_type']) && $file['storage_type'] !== 'local') {
        // Delete from remote storage
        $storage = StorageFactory::getAdapter($file['storage_type']);

        if (!empty($file['storage_path']) && $storage->exists($file['storage_path'])) {
          $storage->delete($file['storage_path']);
        }
      }
    } catch (Exception $e) {
      // Log but don't fail the deletion
      \Civi::log()->error('Failed to delete file from remote storage: ' . $e->getMessage());
    }
  }
}

/**
 * Implements hook_civicrm_download().
 *
 * This hook intercepts file download requests.
 * For files on remote storage, we redirect to signed URLs or proxy the download.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_download
 */
function filestorage_civicrm_download($path, &$mimeType, &$buffer, &$redirect): void {
  try {
    // Extract file ID from path if possible
    $fileId = _filestorage_extract_file_id($path);

    if (!$fileId) {
      return;
    }

    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('storage_type', 'storage_path', 'mime_type')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      return;
    }

    // If file is on remote storage, handle download
    if (!empty($file['storage_type']) && $file['storage_type'] !== 'local') {
      $storage = StorageFactory::getAdapter($file['storage_type']);

      // Get signed URL (valid for 1 hour)
      $url = $storage->getUrl($file['storage_path'], 3600);

      // Redirect to signed URL
      $redirect = $url;

      // Update mime type if known
      if (!empty($file['mime_type'])) {
        $mimeType = $file['mime_type'];
      }
    }
  } catch (Exception $e) {
    \Civi::log()->error('File download hook error: ' . $e->getMessage());
  }
}

/**
 * Implements hook_civicrm_postProcess().
 *
 * This hook runs after form submissions are processed.
 * We use it to catch file uploads that may have bypassed other hooks.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postProcess
 */
function filestorage_civicrm_postProcess($formName, &$form): void {
  try {
    // Check if form has file uploads
    if (!isset($form->_submitFiles) || empty($form->_submitFiles)) {
      return;
    }

    // Mark any newly created files as pending for sync
    // The scheduled job will handle them if the hooks missed them
    foreach ($form->_submitFiles as $fieldName => $fileInfo) {
      if (!empty($fileInfo['name']) && !empty($fileInfo['tmp_name'])) {
        // File was uploaded - the pre hook should have handled it
        // But if it didn't, the scheduled job will catch it
        \Civi::log()->debug("File uploaded via form: {$fileInfo['name']}");
      }
    }
  } catch (Exception $e) {
    \Civi::log()->error('File storage postProcess hook error: ' . $e->getMessage());
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds menu items for file storage administration.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function filestorage_civicrm_navigationMenu(&$menu): void {
  _filestorage_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('File Storage Settings'),
    'name' => 'filestorage_settings',
    'url' => 'civicrm/admin/filestorage/settings',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);

  _filestorage_civix_navigationMenu($menu);
}

/**
 * Generate a storage path for a file.
 *
 * Helper function to create organized file paths in remote storage.
 *
 * @param array $params File parameters
 *
 * @return string Generated path
 */
function _filestorage_generate_path(array $params): string {
  // Use current date for organization
  $year = date('Y');
  $month = date('m');
  $day = date('d');

  // Get original filename
  $filename = basename($params['uri'] ?? 'unknown');

  // Generate unique hash to prevent collisions
  $hash = substr(md5(uniqid() . $filename), 0, 8);

  // Preserve extension
  $extension = pathinfo($filename, PATHINFO_EXTENSION);
  $basename = pathinfo($filename, PATHINFO_FILENAME);

  // Clean filename (remove special characters)
  $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);

  // Build path: uploads/{year}/{month}/{day}/{basename}_{hash}.{ext}
  $path = sprintf(
    'uploads/%s/%s/%s/%s_%s',
    $year,
    $month,
    $day,
    $basename,
    $hash
  );

  if ($extension) {
    $path .= '.' . $extension;
  }

  return $path;
}

/**
 * Extract file ID from a file path or URL.
 *
 * Helper function to determine which file record a download request is for.
 *
 * @param string $path File path or URL
 *
 * @return int|null File ID or NULL if not found
 */
function _filestorage_extract_file_id(string $path): ?int {
  // Try to extract file ID from various path formats

  // Format 1: Direct file ID in path (e.g., "civicrm/file?id=123")
  if (preg_match('/[?&]id=(\d+)/', $path, $matches)) {
    return (int) $matches[1];
  }

  // Format 2: File ID in path segment (e.g., "files/123/document.pdf")
  if (preg_match('/files\/(\d+)\//', $path, $matches)) {
    return (int) $matches[1];
  }

  // Format 3: Look up by URI in database
  try {
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('id')
      ->addWhere('uri', 'LIKE', '%' . basename($path))
      ->setLimit(1)
      ->execute()
      ->first();

    if ($file) {
      return (int) $file['id'];
    }
  } catch (Exception $e) {
    // Ignore lookup errors
  }

  return NULL;
}

/**
 * Implements hook_civicrm_check().
 *
 * Performs system checks for file storage configuration.
 * Warns about potential issues.
 *
 * @param array $messages
 * @param string $statusNames
 * @param bool $includeDisabled
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_check
 */
function filestorage_civicrm_check(&$messages, $statusNames, $includeDisabled): void {
  // Check if any storage backends are configured
  try {
    $configs = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->execute();

    if ($configs->count() === 0) {
      $messages[] = new CRM_Utils_Check_Message(
        'filestorage_no_config',
        E::ts('No active file storage backends configured. Files will use local storage only.'),
        E::ts('File Storage Configuration'),
        \Psr\Log\LogLevel::WARNING,
        'fa-cloud'
      );
    }

    // Check for files with failed sync status
    $failedCount = \Civi\Api4\File::get(FALSE)
      ->addWhere('sync_status', '=', 'failed')
      ->selectRowCount()
      ->execute()
      ->count();

    if ($failedCount > 0) {
      $messages[] = new CRM_Utils_Check_Message(
        'filestorage_failed_syncs',
        E::ts('There are %1 files with failed sync status. Check the sync log for details.', [
          1 => $failedCount,
        ]),
        E::ts('File Storage Sync Issues'),
        \Psr\Log\LogLevel::WARNING,
        'fa-exclamation-triangle'
      );
    }

    // Check if scheduled job is enabled
    $job = \Civi\Api4\Job::get(FALSE)
      ->addWhere('api_entity', '=', 'Job')
      ->addWhere('api_action', '=', 'filestorage_sync')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->first();

    if (!$job) {
      $messages[] = new CRM_Utils_Check_Message(
        'filestorage_job_disabled',
        E::ts('The File Storage Sync scheduled job is not enabled. Files may not sync automatically.'),
        E::ts('File Storage Scheduled Job'),
        \Psr\Log\LogLevel::WARNING,
        'fa-clock-o'
      );
    }

    // Test connection to each configured storage
    foreach ($configs as $config) {
      try {
        $storage = StorageFactory::getAdapter($config['storage_type'], $config['config_name']);

        if (!$storage->testConnection()) {
          $messages[] = new CRM_Utils_Check_Message(
            'filestorage_connection_' . $config['id'],
            E::ts('Cannot connect to %1 storage "%2". Check credentials and configuration.', [
              1 => strtoupper($config['storage_type']),
              2 => $config['config_name'],
            ]),
            E::ts('File Storage Connection'),
            \Psr\Log\LogLevel::ERROR,
            'fa-unlink'
          );
        }
      } catch (Exception $e) {
        $messages[] = new CRM_Utils_Check_Message(
          'filestorage_error_' . $config['id'],
          E::ts('Error testing %1 storage "%2": %3', [
            1 => strtoupper($config['storage_type']),
            2 => $config['config_name'],
            3 => $e->getMessage(),
          ]),
          E::ts('File Storage Error'),
          \Psr\Log\LogLevel::ERROR,
          'fa-times-circle'
        );
      }
    }
  } catch (Exception $e) {
    // Ignore check errors
  }
}

/**
 * Implements hook_civicrm_apiWrappers().
 *
 * Wraps File API calls to handle storage operations.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_apiWrappers
 */
function filestorage_civicrm_apiWrappers(&$wrappers, $apiRequest): void {
  // Add API wrapper for File entity operations
  if ($apiRequest['entity'] === 'File') {
    $wrappers[] = new CRM_Filestorage_APIWrapper_FileStorage();
  }
}

/**
 * Implements hook_civicrm_managed().
 *
 * Declares managed entities (scheduled jobs, option groups, etc.).
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function filestorage_civicrm_managed(&$entities): void {
  // Add scheduled job for file sync
  $entities[] = [
    'module' => 'com.skvare.filestorage',
    'name' => 'filestorage_sync_job',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'File Storage Sync',
      'description' => 'Syncs files to configured remote storage that were missed by hooks or created outside normal workflows',
      'run_frequency' => 'Hourly',
      'api_entity' => 'Job',
      'api_action' => 'filestorage_sync',
      'parameters' => 'mode=pending',
      'is_active' => 1,
    ],
  ];

  _filestorage_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function filestorage_civicrm_caseTypes(&$caseTypes): void {
  _filestorage_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function filestorage_civicrm_angularModules(&$angularModules): void {
  _filestorage_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_themes().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_themes
 */
function filestorage_civicrm_themes(&$themes): void {
  _filestorage_civix_civicrm_themes($themes);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function filestorage_civicrm_entityTypes(&$entityTypes): void {
  _filestorage_civix_civicrm_entityTypes($entityTypes);
}