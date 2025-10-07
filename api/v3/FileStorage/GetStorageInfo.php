<?php

use Civi\Filestorage\Storage\StorageFactory;
use Civi\Filestorage\Service\FileService;

/**
 * FileStorage.GetStorageInfo API specification.
 *
 * @param array $spec API specification array
 */
function _civicrm_api3_file_storage_GetStorageInfo_spec(&$spec) {
  $spec['storage_type'] = [
    'title' => 'Storage Type',
    'description' => 'Storage type to get info for (optional - returns all if not specified)',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
  ];

  $spec['include_stats'] = [
    'title' => 'Include Statistics',
    'description' => 'Include usage statistics',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => TRUE,
  ];

  $spec['test_connection'] = [
    'title' => 'Test Connection',
    'description' => 'Test connectivity to storage backends',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => FALSE,
  ];
}

/**
 * FileStorage.GetStorageInfo API.
 *
 * Returns information about configured storage backends and usage statistics.
 *
 * Usage examples:
 *
 * Get all storage info:
 *   cv api FileStorage.getstorageinfo
 *
 * Get specific storage info:
 *   cv api FileStorage.getstorageinfo storage_type=s3
 *
 * Test connections:
 *   cv api FileStorage.getstorageinfo test_connection=1
 *
 * @param array $params API parameters
 *
 * @return array API result
 *
 * @throws API_Exception
 */
function civicrm_api3_file_storage_GetStorageInfo($params) {
  try {
    $info = [];

    // Get configured storage backends
    $configs = \Civi\Api4\FilestorageConfig::get(FALSE)
      ->addSelect('*')
      ->execute();

    foreach ($configs as $config) {
      $storageType = $config['storage_type'];

      // Filter by storage type if specified
      if (!empty($params['storage_type']) && $storageType !== $params['storage_type']) {
        continue;
      }

      $storageInfo = [
        'id' => $config['id'],
        'type' => $storageType,
        'name' => $config['config_name'],
        'is_active' => (bool)$config['is_active'],
        'is_default' => (bool)$config['is_default'],
      ];

      // Test connection if requested
      if (!empty($params['test_connection'])) {
        try {
          $storage = StorageFactory::getAdapter($storageType, $config['config_name']);
          $storageInfo['connection_status'] = $storage->testConnection() ? 'success' : 'failed';
          $storageInfo['config'] = $storage->getConfig();
        }
        catch (Exception $e) {
          $storageInfo['connection_status'] = 'error';
          $storageInfo['connection_error'] = $e->getMessage();
        }
      }

      // Add to results
      $info[$storageType] = $storageInfo;
    }

    // Add local storage info (always available)
    if (empty($params['storage_type']) || $params['storage_type'] === 'local') {
      $info['local'] = [
        'type' => 'local',
        'name' => 'Local Filesystem',
        'is_active' => TRUE,
        'is_default' => count($configs) === 0, // Default if no other storage configured
      ];

      if (!empty($params['test_connection'])) {
        try {
          $storage = StorageFactory::getAdapter('local');
          $info['local']['connection_status'] = $storage->testConnection() ? 'success' : 'failed';
          $info['local']['config'] = $storage->getConfig();
        }
        catch (Exception $e) {
          $info['local']['connection_status'] = 'error';
          $info['local']['connection_error'] = $e->getMessage();
        }
      }
    }

    // Add usage statistics if requested
    if (!empty($params['include_stats'])) {
      $stats = FileService::getStatistics();

      foreach ($info as $storageType => &$storageInfo) {
        $storageInfo['file_count'] = $stats['by_storage'][$storageType] ?? 0;
        $storageInfo['percentage'] = $stats['total_files'] > 0
          ? round(($storageInfo['file_count'] / $stats['total_files']) * 100, 1)
          : 0;
      }

      // Add overall statistics
      $info['_summary'] = [
        'total_files' => $stats['total_files'],
        'by_storage' => $stats['by_storage'],
        'by_entity' => $stats['by_entity'],
      ];
    }

    return civicrm_api3_create_success(
      $info,
      $params,
      'FileStorage',
      'GetStorageInfo'
    );

  }
  catch (Exception $e) {
    return civicrm_api3_create_error($e->getMessage());
  }
}