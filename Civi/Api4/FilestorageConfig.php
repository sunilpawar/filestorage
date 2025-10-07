<?php

namespace Civi\Api4;

/**
 * FilestorageConfig entity.
 *
 * Represents storage backend configurations for the File Storage extension.
 * Each configuration defines how to connect to a specific storage provider
 * (S3, GCS, Azure, Spaces, etc.).
 *
 * Provided by the File Storage Manager extension.
 *
 * @searchable secondary
 * @since 1.0
 * @package Civi\Api4
 */
class FilestorageConfig extends Generic\DAOEntity {

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetAction
   */
  public static function get($checkPermissions = TRUE) {
    return (new Generic\BasicGetAction(__CLASS__, __FUNCTION__, function ($get) {
      // Add custom logic here if needed
      return $get;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicCreateAction
   */
  public static function create($checkPermissions = TRUE) {
    return (new Generic\BasicCreateAction(__CLASS__, __FUNCTION__, function ($create) {
      // Add custom logic here if needed
      return $create;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicUpdateAction
   */
  public static function update($checkPermissions = TRUE) {
    return (new Generic\BasicUpdateAction(__CLASS__, __FUNCTION__, function ($update) {
      // Add custom logic here if needed
      return $update;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicSaveAction
   */
  public static function save($checkPermissions = TRUE) {
    return (new Generic\BasicSaveAction(__CLASS__, __FUNCTION__, function ($save) {
      // Add custom logic here if needed
      return $save;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicDeleteAction
   */
  public static function delete($checkPermissions = TRUE) {
    return (new Generic\BasicDeleteAction(__CLASS__, __FUNCTION__, function ($delete) {
      // Add custom logic here if needed
      return $delete;
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicReplaceAction
   */
  public static function replace($checkPermissions = TRUE) {
    return (new Generic\BasicReplaceAction(__CLASS__, __FUNCTION__, function ($replace) {
      // Add custom logic here if needed
      return $replace;
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
          'description' => 'Unique configuration ID',
          'primary_key' => TRUE,
          'auto_increment' => TRUE,
        ],
        [
          'name' => 'storage_type',
          'title' => 'Storage Type',
          'data_type' => 'String',
          'input_type' => 'Select',
          'required' => TRUE,
          'description' => 'Type of storage backend',
          'options' => [
            'local' => 'Local Filesystem',
            's3' => 'AWS S3',
            'gcs' => 'Google Cloud Storage',
            'azure' => 'Azure Blob Storage',
            'spaces' => 'DigitalOcean Spaces',
          ],
        ],
        [
          'name' => 'config_name',
          'title' => 'Configuration Name',
          'data_type' => 'String',
          'input_type' => 'Text',
          'required' => TRUE,
          'description' => 'Friendly name for this configuration',
          'maxlength' => 64,
        ],
        [
          'name' => 'config_data',
          'title' => 'Configuration Data',
          'data_type' => 'Text',
          'input_type' => 'TextArea',
          'required' => TRUE,
          'description' => 'JSON configuration: credentials, bucket, region, etc.',
          'serialize' => \CRM_Core_DAO::SERIALIZE_JSON,
        ],
        [
          'name' => 'is_active',
          'title' => 'Is Active',
          'data_type' => 'Boolean',
          'input_type' => 'CheckBox',
          'required' => TRUE,
          'description' => 'Is this configuration active?',
          'default' => TRUE,
        ],
        [
          'name' => 'is_default',
          'title' => 'Is Default',
          'data_type' => 'Boolean',
          'input_type' => 'CheckBox',
          'required' => TRUE,
          'description' => 'Is this the default storage for new files?',
          'default' => FALSE,
        ],
        [
          'name' => 'file_type_rules',
          'title' => 'File Type Rules',
          'data_type' => 'Text',
          'input_type' => 'TextArea',
          'required' => FALSE,
          'description' => 'JSON rules for which file types use this storage',
          'serialize' => \CRM_Core_DAO::SERIALIZE_JSON,
        ],
        [
          'name' => 'created_date',
          'title' => 'Created Date',
          'data_type' => 'Timestamp',
          'input_type' => NULL,
          'required' => TRUE,
          'description' => 'When this configuration was created',
          'default' => 'CURRENT_TIMESTAMP',
        ],
        [
          'name' => 'modified_date',
          'title' => 'Modified Date',
          'data_type' => 'Timestamp',
          'input_type' => NULL,
          'required' => FALSE,
          'description' => 'When this configuration was last modified',
          'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
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