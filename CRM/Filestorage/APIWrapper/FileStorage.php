<?php

/**
 * API wrapper for File entity operations.
 *
 * This wrapper intercepts File API calls to ensure proper storage handling
 * for operations that bypass standard hooks.
 *
 * @package CRM_Filestorage_APIWrapper
 */
class CRM_Filestorage_APIWrapper_FileStorage implements API_Wrapper {

  /**
   * Modify API parameters before the request is executed.
   *
   * This allows us to intercept and modify file operations before
   * they hit the database.
   *
   * @param array $apiRequest The API request array
   *
   * @return array Modified API request
   */
  public function fromApiInput($apiRequest) {
    // Only process File entity
    if ($apiRequest['entity'] !== 'File') {
      return $apiRequest;
    }

    // Handle file creation through API
    if ($apiRequest['action'] === 'create' || $apiRequest['action'] === 'replace') {
      try {
        // Check if file storage is enabled
        if (!\Civi::settings()->get('filestorage_enabled')) {
          return $apiRequest;
        }

        // If params contain file data, mark for sync
        if (!empty($apiRequest['params']['uri'])) {
          // Set default sync status
          if (!isset($apiRequest['params']['sync_status'])) {
            $apiRequest['params']['sync_status'] = 'pending';
          }
        }
      }
      catch (Exception $e) {
        \Civi::log()->error('File storage API wrapper error: ' . $e->getMessage());
      }
    }

    return $apiRequest;
  }

  /**
   * Modify the API result before it's returned.
   *
   * This allows us to modify the response or perform cleanup operations
   * after the API request completes.
   *
   * @param array $apiRequest The API request array
   * @param array $result The API result array
   *
   * @return array Modified API result
   */
  public function toApiOutput($apiRequest, $result) {
    // Add storage information to file results
    if ($apiRequest['entity'] === 'File' &&
      ($apiRequest['action'] === 'get' || $apiRequest['action'] === 'getsingle')) {

      try {
        if (!empty($result['values'])) {
          foreach ($result['values'] as $id => &$file) {
            // Add computed field for download URL if file is on remote storage
            if (!empty($file['storage_type']) && $file['storage_type'] !== 'local') {
              $file['download_url'] = $this->getDownloadUrl($file);
            }
          }
        }
      }
      catch (Exception $e) {
        \Civi::log()->error('File storage API output wrapper error: ' . $e->getMessage());
      }
    }

    return $result;
  }

  /**
   * Get download URL for a file.
   *
   * Generates appropriate download URL based on storage type.
   *
   * @param array $file File record
   *
   * @return string|null Download URL
   */
  private function getDownloadUrl(array $file): ?string {
    try {
      $storage = \Civi\Filestorage\Storage\StorageFactory::getAdapterForFile($file['id']);

      // Generate signed URL valid for 1 hour
      return $storage->getUrl($file['storage_path'], 3600);
    }
    catch (Exception $e) {
      \Civi::log()->warning("Failed to generate download URL for file {$file['id']}: " . $e->getMessage());
      return NULL;
    }
  }
}