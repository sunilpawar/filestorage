<?php

namespace Civi\Filestorage\Storage;

use CRM_Core_Exception;

/**
 * Factory class for creating storage adapter instances.
 *
 * This factory centralizes the creation of storage adapters based on configuration.
 * It handles loading configurations from the database and instantiating the
 * appropriate adapter class with the correct settings.
 *
 * Usage:
 *   $storage = StorageFactory::getAdapter('s3');
 *   $storage = StorageFactory::getDefaultAdapter();
 *   $storage = StorageFactory::getAdapterForFile($fileId);
 *
 * @package Civi\Filestorage\Storage
 */
class StorageFactory {

  /**
   * Cache of instantiated storage adapters.
   * Prevents creating multiple instances of the same adapter.
   *
   * @var array<string, StorageInterface>
   */
  private static $adapters = [];

  /**
   * Get a storage adapter by type.
   *
   * This is the primary method for obtaining storage adapters. It loads the
   * configuration for the specified storage type and returns an initialized adapter.
   * Adapters are cached, so subsequent calls with the same type return the same instance.
   *
   * @param string $type Storage type: 'local', 's3', 'gcs', 'azure', 'spaces'
   * @param string|null $configName Optional specific configuration name to use
   *
   * @return StorageInterface The storage adapter instance
   *
   * @throws CRM_Core_Exception If storage type is invalid or configuration not found
   */
  public static function getAdapter(string $type, ?string $configName = NULL): StorageInterface {
    // Create cache key
    $cacheKey = $configName ?? $type;

    // Return cached adapter if available
    if (isset(self::$adapters[$cacheKey])) {
      return self::$adapters[$cacheKey];
    }

    // Load configuration for this storage type
    $config = self::loadConfig($type, $configName);

    // Create the appropriate adapter based on type
    $adapter = self::createAdapter($type, $config);

    // Cache the adapter for reuse
    self::$adapters[$cacheKey] = $adapter;

    return $adapter;
  }

  /**
   * Get the default storage adapter for new files.
   *
   * Retrieves the storage adapter marked as default in the configuration.
   * If no default is configured, falls back to local storage.
   *
   * @return StorageInterface The default storage adapter
   *
   * @throws CRM_Core_Exception If default storage cannot be determined
   */
  public static function getDefaultAdapter(): StorageInterface {
    // Check for default storage in settings
    $defaultType = \Civi::settings()->get('filestorage_default_type') ?? 'local';
    $defaultConfigName = \Civi::settings()->get('filestorage_default_config');

    return self::getAdapter($defaultType, $defaultConfigName);
  }

  /**
   * Get the storage adapter for a specific file.
   *
   * Determines which storage adapter to use based on the file's current
   * storage_type field in the database. This is used when retrieving or
   * manipulating existing files.
   *
   * @param int $fileId CiviCRM file ID
   *
   * @return StorageInterface The storage adapter for this file
   *
   * @throws CRM_Core_Exception If file not found or storage type invalid
   */
  public static function getAdapterForFile(int $fileId): StorageInterface {
    // Query the file record to get its storage type
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('storage_type', 'storage_metadata')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File ID {$fileId} not found");
    }

    // Use local storage if storage_type is not set (legacy files)
    $storageType = $file['storage_type'] ?? 'local';

    // Get storage metadata if available (may contain config name)
    $metadata = !empty($file['storage_metadata'])
      ? json_decode($file['storage_metadata'], TRUE)
      : [];

    $configName = $metadata['config_name'] ?? NULL;

    return self::getAdapter($storageType, $configName);
  }

  /**
   * Determine the appropriate storage adapter for a new file.
   *
   * This method implements business logic to decide which storage backend
   * should be used for a new file based on:
   * - File type (e.g., image, PDF, attachment)
   * - File size
   * - Entity type (e.g., contact, activity, case)
   * - Storage rules configured in the system
   *
   * @param array $fileInfo File information:
   *   - 'mime_type' => string
   *   - 'size' => int (bytes)
   *   - 'entity_type' => string (optional)
   *   - 'file_type_id' => int (optional)
   *
   * @return StorageInterface The appropriate storage adapter
   */
  public static function getAdapterForNewFile(array $fileInfo): StorageInterface {
    // Load storage rules from settings
    $rules = \Civi::settings()->get('filestorage_rules') ?? [];

    // Apply rules in order of priority
    foreach ($rules as $rule) {
      if (self::matchesRule($fileInfo, $rule)) {
        return self::getAdapter($rule['storage_type'], $rule['config_name'] ?? NULL);
      }
    }

    // No matching rule, use default storage
    return self::getDefaultAdapter();
  }

  /**
   * Create a storage adapter instance of the specified type.
   *
   * Factory method that instantiates the concrete adapter class based on
   * the storage type identifier.
   *
   * @param string $type Storage type identifier
   * @param array $config Configuration array for the adapter
   *
   * @return StorageInterface Instance of the storage adapter
   *
   * @throws CRM_Core_Exception If storage type is not supported
   */
  private static function createAdapter(string $type, array $config): StorageInterface {
    switch ($type) {
      case 'local':
        return new LocalStorage($config);

      case 's3':
        return new S3Storage($config);

      case 'gcs':
        return new GCSStorage($config);

      case 'azure':
        return new AzureStorage($config);

      case 'spaces':
        // DigitalOcean Spaces is S3-compatible, use S3 adapter with custom endpoint
        return new S3Storage($config);

      default:
        throw new CRM_Core_Exception("Unsupported storage type: {$type}");
    }
  }

  /**
   * Load storage configuration from database or settings.
   *
   * Retrieves the configuration for a specific storage type, either by
   * configuration name or by loading the active configuration for that type.
   *
   * @param string $type Storage type
   * @param string|null $configName Optional specific configuration name
   *
   * @return array Configuration array
   *
   * @throws CRM_Core_Exception If configuration not found
   */
  private static function loadConfig(string $type, ?string $configName = NULL): array {
    // For local storage, use default paths from CiviCRM configuration
    if ($type === 'local') {
      return self::getLocalConfig();
    }

    // Load from database configuration table
    $query = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addSelect('config_data', 'config_name')
      ->addWhere('storage_type', '=', $type)
      ->addWhere('is_active', '=', TRUE);

    // Filter by config name if provided
    if ($configName) {
      $query->addWhere('config_name', '=', $configName);
    }

    $result = $query->execute()->first();

    if (!$result) {
      throw new CRM_Core_Exception(
        "No active configuration found for storage type: {$type}" .
        ($configName ? " (config: {$configName})" : '')
      );
    }

    // Decode JSON configuration
    $config = json_decode($result['config_data'], TRUE);
    $config['config_name'] = $result['config_name'];

    return $config;
  }

  /**
   * Get local storage configuration from CiviCRM settings.
   *
   * Retrieves the standard CiviCRM file paths for local storage.
   *
   * @return array Local storage configuration
   */
  private static function getLocalConfig(): array {
    $config = \CRM_Core_Config::singleton();

    return [
      'base_path' => \Civi::paths()->getPath('[civicrm.files]/'),
      'upload_dir' => \Civi::paths()->getPath('[civicrm.files]/upload/'),
      'image_dir' => \Civi::paths()->getPath('[civicrm.files]/persist/contribute/'),
      'custom_dir' => \Civi::paths()->getPath('[civicrm.files]/custom/'),
      'base_url' => \Civi::paths()->getUrl('[civicrm.files]/'),
    ];
  }

  /**
   * Check if file info matches a storage rule.
   *
   * Evaluates whether the file information matches the criteria defined
   * in a storage rule.
   *
   * @param array $fileInfo File information
   * @param array $rule Storage rule with conditions
   *
   * @return bool TRUE if file matches rule
   */
  private static function matchesRule(array $fileInfo, array $rule): bool {
    // Check MIME type pattern (e.g., "image/*", "application/pdf")
    if (!empty($rule['mime_pattern'])) {
      if (!self::matchesPattern($fileInfo['mime_type'] ?? '', $rule['mime_pattern'])) {
        return FALSE;
      }
    }

    // Check file size limits
    if (!empty($rule['min_size']) && ($fileInfo['size'] ?? 0) < $rule['min_size']) {
      return FALSE;
    }
    if (!empty($rule['max_size']) && ($fileInfo['size'] ?? PHP_INT_MAX) > $rule['max_size']) {
      return FALSE;
    }

    // Check entity type
    if (!empty($rule['entity_types']) && !empty($fileInfo['entity_type'])) {
      if (!in_array($fileInfo['entity_type'], $rule['entity_types'])) {
        return FALSE;
      }
    }

    // Check file type ID
    if (!empty($rule['file_type_ids']) && !empty($fileInfo['file_type_id'])) {
      if (!in_array($fileInfo['file_type_id'], $rule['file_type_ids'])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Check if a string matches a pattern with wildcards.
   *
   * @param string $string String to test
   * @param string $pattern Pattern with * wildcards
   *
   * @return bool TRUE if matches
   */
  private static function matchesPattern(string $string, string $pattern): bool {
    $regex = '/^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$/';
    return (bool)preg_match($regex, $string);
  }

  /**
   * Clear the adapter cache.
   *
   * Forces recreation of adapters on next request. Useful after
   * configuration changes.
   */
  public static function clearCache(): void {
    self::$adapters = [];
  }

  /**
   * Get all available storage types.
   *
   * @return array List of supported storage type identifiers
   */
  public static function getAvailableTypes(): array {
    return ['local', 's3', 'gcs', 'azure', 'spaces'];
  }
}