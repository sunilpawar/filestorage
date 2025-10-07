<?php

namespace Civi\Api4;

/**
 * FilestorageSyncLog entity.
 *
 * Tracks file storage sync operations for auditing and troubleshooting.
 * Records every sync attempt including success, failure, timing, and errors.
 *
 * Provided by the File Storage Manager extension.
 *
 * @searchable secondary
 * @since 1.0
 * @package Civi\Api4
 */
class FilestorageSyncLog extends Generic\DAOEntity {

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetAction
   */
  public static function get($checkPermissions = TRUE) {
    return (new Generic\BasicGetAction(__CLASS__, __FUNCTION__, function ($get) {
      return $get;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicCreateAction
   */
  public static function create($checkPermissions = TRUE) {
    return (new Generic\BasicCreateAction(__CLASS__, __FUNCTION__, function ($create) {
      return $create;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicDeleteAction
   */
  public static function delete($checkPermissions = TRUE) {
    return (new Generic\BasicDeleteAction(__CLASS__, __FUNCTION__, function ($delete) {
      return $delete;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [
        [
          'name' => 'id',
          'title' => 'ID',
          'data_type' => 'Integer',
          'input_type' => 'Number',
          'required' => FALSE,
          'description' => 'Unique sync log ID',
          'primary_key' => TRUE,
          'auto_increment' => TRUE,
        ],
        [
          'name' => 'file_id',
          'title' => 'File ID',
          'data_type' => 'Integer',
          'input_type' => 'EntityRef',
          'required' => TRUE,
          'description' => 'FK to civicrm_file',
          'fk_entity' => 'File',
        ],
        [
          'name' => 'operation',
          'title' => 'Operation',
          'data_type' => 'String',
          'input_type' => 'Select',
          'required' => TRUE,
          'description' => 'Operation type: upload, download, delete, verify, migrate',
          'maxlength' => 32,
          'options' => [
            'upload' => 'Upload',
            'download' => 'Download',
            'delete' => 'Delete',
            'verify' => 'Verify',
            'migrate' => 'Migrate',
            'sync' => 'Sync',
          ],
        ],
        [
          'name' => 'source_storage',
          'title' => 'Source Storage',
          'data_type' => 'String',
          'input_type' => 'Text',
          'required' => FALSE,
          'description' => 'Source storage type',
          'maxlength' => 32,
        ],
        [
          'name' => 'target_storage',
          'title' => 'Target Storage',
          'data_type' => 'String',
          'input_type' => 'Text',
          'required' => FALSE,
          'description' => 'Target storage type',
          'maxlength' => 32,
        ],
        [
          'name' => 'status',
          'title' => 'Status',
          'data_type' => 'String',
          'input_type' => 'Select',
          'required' => TRUE,
          'description' => 'Operation status: success, failed, skipped',
          'maxlength' => 32,
          'options' => [
            'success' => 'Success',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
          ],
        ],
        [
          'name' => 'error_message',
          'title' => 'Error Message',
          'data_type' => 'Text',
          'input_type' => 'TextArea',
          'required' => FALSE,
          'description' => 'Error details if status is failed',
        ],
        [
          'name' => 'file_size',
          'title' => 'File Size',
          'data_type' => 'Integer',
          'input_type' => 'Number',
          'required' => FALSE,
          'description' => 'File size in bytes',
        ],
        [
          'name' => 'duration_ms',
          'title' => 'Duration (ms)',
          'data_type' => 'Integer',
          'input_type' => 'Number',
          'required' => FALSE,
          'description' => 'Operation duration in milliseconds',
        ],
        [
          'name' => 'sync_date',
          'title' => 'Sync Date',
          'data_type' => 'Timestamp',
          'input_type' => 'Date',
          'required' => TRUE,
          'description' => 'When sync was attempted',
          'default' => 'CURRENT_TIMESTAMP',
        ],
        [
          'name' => 'created_by',
          'title' => 'Created By',
          'data_type' => 'Integer',
          'input_type' => 'EntityRef',
          'required' => FALSE,
          'description' => 'Contact ID who triggered sync',
          'fk_entity' => 'Contact',
        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @return string
   */
  public static function permissions() {
    return [
      'meta' => ['access CiviCRM'],
      'default' => [
        'administer CiviCRM',
      ],
    ];
  }

}