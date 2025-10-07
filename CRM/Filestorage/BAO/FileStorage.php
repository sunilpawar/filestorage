<?php

/**
 * Business Access Object (BAO) for File Storage.
 *
 * This BAO provides business logic layer for file storage operations.
 * It acts as a bridge between the database layer and the application logic.
 *
 * @package CRM_Filestorage_BAO
 */
class CRM_Filestorage_BAO_FileStorage extends CRM_Core_DAO {

  /**
   * Create or update a file storage configuration.
   *
   * @param array $params Configuration parameters
   *
   * @return object Configuration object
   *
   * @throws CRM_Core_Exception
   */
  public static function create($params) {
    $config = new CRM_Filestorage_DAO_FilestorageConfig();

    // Validate required fields
    if (empty($params['storage_type'])) {
      throw new CRM_Core_Exception('Storage type is required');
    }

    if (empty($params['config_name'])) {
      throw new CRM_Core_Exception('Configuration name is required');
    }

    // Set values
    $config->copyValues($params);

    // If this is set as default, unset other defaults
    if (!empty($params['is_default'])) {
      self::clearDefaultFlags($params['storage_type'], $params['id'] ?? NULL);
    }

    // Save
    $config->save();

    return $config;
  }

  /**
   * Delete a file storage configuration.
   *
   * @param int $id Configuration ID
   *
   * @return bool TRUE on success
   *
   * @throws CRM_Core_Exception
   */
  public static function del($id) {
    $config = new CRM_Filestorage_DAO_FilestorageConfig();
    $config->id = $id;

    if (!$config->find(TRUE)) {
      throw new CRM_Core_Exception('Configuration not found');
    }

    // Check if any files are using this configuration
    $fileCount = self::getFileCount($config->storage_type, $config->config_name);

    if ($fileCount > 0) {
      throw new CRM_Core_Exception(
        "Cannot delete configuration: {$fileCount} files are using this storage"
      );
    }

    return $config->delete();
  }

  /**
   * Get file count for a storage configuration.
   *
   * @param string $storageType Storage type
   * @param string $configName Configuration name
   *
   * @return int File count
   */
  public static function getFileCount($storageType, $configName = NULL) {
    $query = \Civi\Api4\File::get(FALSE)
      ->addWhere('storage_type', '=', $storageType)
      ->selectRowCount()
      ->execute();

    return $query->count();
  }

  /**
   * Clear default flags for other configurations of the same storage type.
   *
   * @param string $storageType Storage type
   * @param int|null $exceptId Configuration ID to exclude
   */
  private static function clearDefaultFlags($storageType, $exceptId = NULL) {
    $query = "
      UPDATE civicrm_filestorage_config
      SET is_default = 0
      WHERE storage_type = %1
    ";

    $params = [
      1 => [$storageType, 'String'],
    ];

    if ($exceptId) {
      $query .= " AND id != %2";
      $params[2] = [$exceptId, 'Integer'];
    }

    CRM_Core_DAO::executeQuery($query, $params);
  }

  /**
   * Get default configuration for a storage type.
   *
   * @param string $storageType Storage type
   *
   * @return array|null Configuration or NULL
   */
  public static function getDefaultConfig($storageType) {
    $config = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addWhere('storage_type', '=', $storageType)
      ->addWhere('is_default', '=', TRUE)
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->first();

    return $config;
  }

  /**
   * Get all active configurations.
   *
   * @return array Array of configurations
   */
  public static function getAllConfigs() {
    return \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Test connection to a storage backend.
   *
   * @param int $configId Configuration ID
   *
   * @return array Test result with 'success' and optional 'error'
   */
  public static function testConnection($configId) {
    try {
      $config = \Civi\Api4\FilestorageConfig::get(FALSE)
        ->addWhere('id', '=', $configId)
        ->execute()
        ->first();

      if (!$config) {
        return [
          'success' => FALSE,
          'error' => 'Configuration not found',
        ];
      }

      $storage = \Civi\Filestorage\Storage\StorageFactory::getAdapter(
        $config['storage_type'],
        $config['config_name']
      );

      $success = $storage->testConnection();

      return [
        'success' => $success,
        'error' => $success ? NULL : 'Connection test failed',
      ];

    }
    catch (Exception $e) {
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Get storage usage statistics.
   *
   * @return array Statistics array
   */
  public static function getUsageStats() {
    return \Civi\Filestorage\Service\FileService::getStatistics();
  }

  /**
   * Validate configuration data.
   *
   * @param array $params Configuration parameters
   *
   * @return array Validation result with 'valid' and 'errors'
   */
  public static function validateConfig($params) {
    $errors = [];

    // Validate storage type
    $validTypes = ['local', 's3', 'gcs', 'azure', 'spaces'];
    if (empty($params['storage_type']) || !in_array($params['storage_type'], $validTypes)) {
      $errors[] = 'Invalid storage type';
    }

    // Validate config name
    if (empty($params['config_name'])) {
      $errors[] = 'Configuration name is required';
    }

    // Validate config data
    if (empty($params['config_data'])) {
      $errors[] = 'Configuration data is required';
    }
    else {
      // Validate JSON
      $configData = is_string($params['config_data'])
        ? json_decode($params['config_data'], TRUE)
        : $params['config_data'];

      if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = 'Invalid JSON in configuration data';
      }
      else {
        // Validate storage-specific requirements
        $storageErrors = self::validateStorageConfig(
          $params['storage_type'],
          $configData
        );
        $errors = array_merge($errors, $storageErrors);
      }
    }

    // Check for duplicate config name
    if (!empty($params['config_name'])) {
      $query = \Civi\Api4\FilestorageConfig::get(FALSE)
        ->addWhere('config_name', '=', $params['config_name'])
        ->selectRowCount();

      if (!empty($params['id'])) {
        $query->addWhere('id', '!=', $params['id']);
      }

      if ($query->execute()->count() > 0) {
        $errors[] = 'Configuration name already exists';
      }
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * Validate storage-specific configuration.
   *
   * @param string $storageType Storage type
   * @param array $configData Configuration data
   *
   * @return array Array of error messages
   */
  private static function validateStorageConfig($storageType, $configData) {
    $errors = [];

    switch ($storageType) {
      case 's3':
      case 'spaces':
        if (empty($configData['key'])) {
          $errors[] = 'Access key is required';
        }
        if (empty($configData['secret'])) {
          $errors[] = 'Secret key is required';
        }
        if (empty($configData['bucket'])) {
          $errors[] = 'Bucket name is required';
        }
        if (empty($configData['region'])) {
          $errors[] = 'Region is required';
        }
        break;

      case 'gcs':
        if (empty($configData['project_id'])) {
          $errors[] = 'Project ID is required';
        }
        if (empty($configData['bucket'])) {
          $errors[] = 'Bucket name is required';
        }
        if (empty($configData['key_file']) && empty($configData['credentials'])) {
          $errors[] = 'Key file or credentials are required';
        }
        break;

      case 'azure':
        if (empty($configData['account_name'])) {
          $errors[] = 'Account name is required';
        }
        if (empty($configData['account_key']) && empty($configData['connection_string'])) {
          $errors[] = 'Account key or connection string is required';
        }
        if (empty($configData['container'])) {
          $errors[] = 'Container name is required';
        }
        break;

      case 'local':
        // Local storage doesn't need additional validation
        break;
    }

    return $errors;
  }

  /**
   * Encrypt sensitive configuration data.
   *
   * @param array $configData Configuration data
   *
   * @return array Encrypted configuration data
   */
  public static function encryptConfig($configData) {
    $sensitiveKeys = ['secret', 'account_key', 'key_file', 'credentials'];

    foreach ($sensitiveKeys as $key) {
      if (isset($configData[$key])) {
        // In production, use proper encryption
        // For now, this is a placeholder
        $configData[$key] = base64_encode($configData[$key]);
      }
    }

    return $configData;
  }

  /**
   * Decrypt sensitive configuration data.
   *
   * @param array $configData Configuration data
   *
   * @return array Decrypted configuration data
   */
  public static function decryptConfig($configData) {
    $sensitiveKeys = ['secret', 'account_key', 'key_file', 'credentials'];

    foreach ($sensitiveKeys as $key) {
      if (isset($configData[$key])) {
        // In production, use proper decryption
        // For now, this is a placeholder
        $configData[$key] = base64_decode($configData[$key]);
      }
    }

    return $configData;
  }

  /**
   * Clone a configuration.
   *
   * @param int $configId Configuration ID to clone
   * @param string $newName New configuration name
   *
   * @return object New configuration object
   *
   * @throws CRM_Core_Exception
   */
  public static function cloneConfig($configId, $newName) {
    $original = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addWhere('id', '=', $configId)
      ->execute()
      ->first();

    if (!$original) {
      throw new CRM_Core_Exception('Configuration not found');
    }

    // Create new config
    $newConfig = $original;
    unset($newConfig['id']);
    $newConfig['config_name'] = $newName;
    $newConfig['is_default'] = FALSE; // Clones are never default

    return self::create($newConfig);
  }

  /**
   * Export configuration (sanitized, without credentials).
   *
   * @param int $configId Configuration ID
   *
   * @return array Sanitized configuration
   *
   * @throws CRM_Core_Exception
   */
  public static function exportConfig($configId) {
    $config = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addWhere('id', '=', $configId)
      ->execute()
      ->first();

    if (!$config) {
      throw new CRM_Core_Exception('Configuration not found');
    }

    // Parse config data
    $configData = json_decode($config['config_data'], TRUE);

    // Remove sensitive data
    $sensitiveKeys = ['secret', 'key', 'account_key', 'key_file', 'credentials'];
    foreach ($sensitiveKeys as $key) {
      if (isset($configData[$key])) {
        $configData[$key] = '***REDACTED***';
      }
    }

    $config['config_data'] = $configData;

    return $config;
  }

  /**
   * Get available storage types
   *
   * @return array
   *   Array of storage types with key => label pairs
   */
  public static function getStorageTypes() {
    return [
      'local' => E::ts('Local Filesystem'),
      's3' => E::ts('Amazon S3'),
      'gcs' => E::ts('Google Cloud Storage'),
      'azure' => E::ts('Azure Blob Storage'),
      'digitalocean' => E::ts('DigitalOcean Spaces'),
    ];
  }

  /**
   * Get available operation types
   *
   * @return array
   *   Array of operation types with key => label pairs
   */
  public static function getOperationTypes() {
    return [
      'upload' => E::ts('Upload'),
      'download' => E::ts('Download'),
      'delete' => E::ts('Delete'),
      'sync' => E::ts('Sync'),
      'move' => E::ts('Move'),
      'copy' => E::ts('Copy'),
    ];
  }

}