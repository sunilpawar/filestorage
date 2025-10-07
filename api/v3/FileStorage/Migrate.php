<?php

use Civi\Filestorage\Service\MigrationService;

/**
 * FileStorage.Migrate API specification.
 *
 * This API endpoint handles bulk migration of files between storage backends.
 *
 * @param array $spec API specification array
 */
function _civicrm_api3_file_storage_Migrate_spec(&$spec) {
  // Source storage (optional - if not specified, migrates from all)
  $spec['source_storage'] = [
    'title' => 'Source Storage',
    'description' => 'Source storage type (local, s3, gcs, azure, spaces)',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
  ];

  // Target storage (required)
  $spec['target_storage'] = [
    'title' => 'Target Storage',
    'description' => 'Target storage type (local, s3, gcs, azure, spaces)',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  // Batch size
  $spec['batch_size'] = [
    'title' => 'Batch Size',
    'description' => 'Number of files to process in this batch',
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 50,
  ];

  // Delete source after migration
  $spec['delete_source'] = [
    'title' => 'Delete Source',
    'description' => 'Delete files from source storage after successful migration',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => FALSE,
  ];

  // Verify after migration
  $spec['verify'] = [
    'title' => 'Verify',
    'description' => 'Verify files exist in target storage after migration',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => TRUE,
  ];

  // Dry run (plan only, don't execute)
  $spec['dry_run'] = [
    'title' => 'Dry Run',
    'description' => 'Plan migration without actually migrating files',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => FALSE,
  ];

  // File type filter
  $spec['file_types'] = [
    'title' => 'File Types',
    'description' => 'Array of file type IDs to migrate',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
  ];

  // Entity type filter
  $spec['entity_types'] = [
    'title' => 'Entity Types',
    'description' => 'Array of entity types to migrate (e.g., activity, contact)',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
  ];

  // Age filter
  $spec['days_old'] = [
    'title' => 'Days Old',
    'description' => 'Only migrate files older than this many days',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
  ];

  // Size filters
  $spec['min_size'] = [
    'title' => 'Minimum Size',
    'description' => 'Minimum file size in bytes',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
  ];

  $spec['max_size'] = [
    'title' => 'Maximum Size',
    'description' => 'Maximum file size in bytes',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
  ];
}

/**
 * FileStorage.Migrate API.
 *
 * Migrates files from one storage backend to another.
 *
 * Usage examples:
 *
 * Dry run (plan only):
 *   cv api FileStorage.migrate target_storage=s3 dry_run=1
 *
 * Migrate all local files to S3:
 *   cv api FileStorage.migrate source_storage=local target_storage=s3
 *
 * Migrate old files only:
 *   cv api FileStorage.migrate target_storage=s3 days_old=90
 *
 * Migrate large files with deletion:
 *   cv api FileStorage.migrate target_storage=s3 min_size=10485760 delete_source=1
 *
 * @param array $params API parameters
 *
 * @return array API result
 *
 * @throws API_Exception
 */
function civicrm_api3_file_storage_Migrate($params) {
  try {
    // Build criteria
    $criteria = [
      'target_storage' => $params['target_storage'],
    ];

    if (!empty($params['source_storage'])) {
      $criteria['source_storage'] = $params['source_storage'];
    }

    if (!empty($params['file_types'])) {
      // Handle comma-separated string or array
      $criteria['file_types'] = is_array($params['file_types'])
        ? $params['file_types']
        : explode(',', $params['file_types']);
    }

    if (!empty($params['entity_types'])) {
      $criteria['entity_types'] = is_array($params['entity_types'])
        ? $params['entity_types']
        : explode(',', $params['entity_types']);
    }

    if (!empty($params['days_old'])) {
      $criteria['days_old'] = (int)$params['days_old'];
    }

    if (!empty($params['min_size'])) {
      $criteria['min_size'] = (int)$params['min_size'];
    }

    if (!empty($params['max_size'])) {
      $criteria['max_size'] = (int)$params['max_size'];
    }

    // Build options
    $options = [
      'batch_size' => (int)$params['batch_size'],
      'delete_source' => (bool)$params['delete_source'],
      'verify' => (bool)$params['verify'],
      'dry_run' => (bool)$params['dry_run'],
    ];

    // If dry run, return migration plan
    if ($options['dry_run']) {
      $plan = MigrationService::planMigration($criteria);

      return civicrm_api3_create_success([
        'mode' => 'plan',
        'plan' => $plan,
        'message' => sprintf(
          'Migration plan: %d files, %s total, estimated %d seconds',
          $plan['file_count'],
          $plan['total_size_formatted'],
          $plan['estimated_time']
        ),
      ], $params, 'FileStorage', 'Migrate');
    }

    // Execute migration
    $results = MigrationService::executeMigration($criteria, $options);

    $message = sprintf(
      'Migrated %d files (%d success, %d failed, %d skipped) in %d seconds',
      $results['processed'],
      $results['success'],
      $results['failed'],
      $results['skipped'],
      $results['duration']
    );

    if ($results['failed'] > 0) {
      // Return warning if some failed
      return civicrm_api3_create_error(
        $message,
        [
          'results' => $results,
          'is_error' => 1,
        ]
      );
    }

    return civicrm_api3_create_success([
      'results' => $results,
      'message' => $message,
    ], $params, 'FileStorage', 'Migrate');

  }
  catch (Exception $e) {
    return civicrm_api3_create_error($e->getMessage());
  }
}