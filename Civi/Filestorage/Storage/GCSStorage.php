<?php

namespace Civi\Filestorage\Storage;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;
use CRM_Core_Exception;

/**
 * Google Cloud Storage adapter.
 *
 * This adapter handles file operations with Google Cloud Storage (GCS).
 * It provides integration with Google's object storage service.
 *
 * Features:
 * - Upload/download files to/from GCS buckets
 * - Generate signed URLs for secure file access
 * - Support for public and private files
 * - Bucket and object management
 * - Streaming support for large files
 *
 * @package Civi\Filestorage\Storage
 */
class GCSStorage implements StorageInterface {

  /**
   * Google Cloud Storage client instance.
   *
   * @var StorageClient
   */
  private $client;

  /**
   * GCS bucket instance.
   *
   * @var Bucket
   */
  private $bucket;

  /**
   * Bucket name.
   *
   * @var string
   */
  private $bucketName;

  /**
   * Path prefix within the bucket (optional).
   *
   * @var string
   */
  private $prefix;

  /**
   * Storage configuration array.
   *
   * @var array
   */
  private $config;

  /**
   * Constructor.
   *
   * @param array $config Configuration array with:
   *   - 'project_id' => string - GCP project ID (required)
   *   - 'bucket' => string - GCS bucket name (required)
   *   - 'key_file' => string - Path to service account JSON file (required)
   *   - 'prefix' => string - Path prefix (optional)
   *   - 'credentials' => array - Alternative to key_file, JSON credentials array
   *
   * @throws CRM_Core_Exception If required configuration is missing
   */
  public function __construct(array $config) {
    $this->config = $config;

    // Validate required configuration
    if (empty($config['project_id'])) {
      throw new CRM_Core_Exception("GCS storage requires 'project_id' configuration");
    }
    if (empty($config['bucket'])) {
      throw new CRM_Core_Exception("GCS storage requires 'bucket' configuration");
    }
    if (empty($config['key_file']) && empty($config['credentials'])) {
      throw new CRM_Core_Exception("GCS storage requires 'key_file' or 'credentials' configuration");
    }

    $this->bucketName = $config['bucket'];
    $this->prefix = rtrim($config['prefix'] ?? '', '/');

    // Initialize GCS client
    $clientConfig = [
      'projectId' => $config['project_id'],
    ];

    // Use key file or credentials array
    if (!empty($config['key_file'])) {
      $clientConfig['keyFilePath'] = $config['key_file'];
    }
    elseif (!empty($config['credentials'])) {
      $clientConfig['keyFile'] = $config['credentials'];
    }

    try {
      $this->client = new StorageClient($clientConfig);
      $this->bucket = $this->client->bucket($this->bucketName);
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to initialize GCS client: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, $contents, array $config = []): bool {
    $key = $this->getKey($path);

    try {
      $options = [];

      // Set metadata
      if (!empty($config['metadata'])) {
        $options['metadata'] = $config['metadata'];
      }

      // Set Content-Type
      if (!empty($config['mime_type'])) {
        $options['metadata']['contentType'] = $config['mime_type'];
      }

      // Set visibility/ACL
      $visibility = $config['visibility'] ?? 'private';
      if ($visibility === 'public') {
        $options['predefinedAcl'] = 'publicRead';
      }

      // Upload object
      if (is_resource($contents)) {
        // Stream upload
        $this->bucket->upload($contents, [
            'name' => $key,
          ] + $options);
      }
      else {
        // String upload
        $this->bucket->upload($contents, [
            'name' => $key,
          ] + $options);
      }

      return TRUE;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to upload file to GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);

      if (!$object->exists()) {
        throw new CRM_Core_Exception("File does not exist: {$path}");
      }

      return $object->downloadAsString();
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to read file from GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);

      if (!$object->exists()) {
        throw new CRM_Core_Exception("File does not exist: {$path}");
      }

      $stream = $object->downloadAsStream();

      // Convert to PHP stream resource
      $tempStream = fopen('php://temp', 'r+');
      stream_copy_to_stream($stream->detach(), $tempStream);
      rewind($tempStream);

      return $tempStream;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to read stream from GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): bool {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);

      if ($object->exists()) {
        $object->delete();
      }

      return TRUE;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to delete file from GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exists(string $path): bool {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);
      return $object->exists();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $from, string $to): bool {
    $fromKey = $this->getKey($from);
    $toKey = $this->getKey($to);

    try {
      $sourceObject = $this->bucket->object($fromKey);

      if (!$sourceObject->exists()) {
        throw new CRM_Core_Exception("Source file does not exist: {$from}");
      }

      $sourceObject->copy($this->bucket, ['name' => $toKey]);

      return TRUE;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to copy file in GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $from, string $to): bool {
    // GCS doesn't have native move, so copy then delete
    $this->copy($from, $to);
    $this->delete($from);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(string $path, int $ttl = 3600): string {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);

      if (!$object->exists()) {
        throw new CRM_Core_Exception("File does not exist: {$path}");
      }

      // For permanent URLs (ttl = 0), check if object is public
      if ($ttl === 0) {
        $info = $object->info();
        $acl = $info['acl'] ?? [];

        $isPublic = FALSE;
        foreach ($acl as $entry) {
          if (($entry['entity'] ?? '') === 'allUsers' && ($entry['role'] ?? '') === 'READER') {
            $isPublic = TRUE;
            break;
          }
        }

        if ($isPublic) {
          // Return public URL
          return sprintf(
            'https://storage.googleapis.com/%s/%s',
            $this->bucketName,
            $key
          );
        }
      }

      // Generate signed URL
      $signedUrl = $object->signedUrl(
        new \DateTime("+{$ttl} seconds"),
        [
          'version' => 'v4',
        ]
      );

      return $signedUrl;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to generate GCS URL: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(string $path): array {
    $key = $this->getKey($path);

    try {
      $object = $this->bucket->object($key);

      if (!$object->exists()) {
        throw new CRM_Core_Exception("File does not exist: {$path}");
      }

      $info = $object->info();

      return [
        'size' => $info['size'] ?? 0,
        'mime_type' => $info['contentType'] ?? 'application/octet-stream',
        'last_modified' => isset($info['updated']) ? strtotime($info['updated']) : time(),
        'etag' => $info['etag'] ?? NULL,
        'visibility' => 'private', // Would need ACL check for accurate value
        'metadata' => $info['metadata'] ?? [],
      ];
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to get metadata from GCS: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSize(string $path): int {
    $metadata = $this->getMetadata($path);
    return (int)$metadata['size'];
  }

  /**
   * {@inheritdoc}
   */
  public function getMimeType(string $path): string {
    $metadata = $this->getMetadata($path);
    return $metadata['mime_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function listContents(string $directory = '', bool $recursive = FALSE): array {
    $prefix = $directory ? $this->getKey($directory) . '/' : $this->prefix;
    if ($prefix && !str_ends_with($prefix, '/')) {
      $prefix .= '/';
    }

    $files = [];

    try {
      $options = [
        'prefix' => $prefix,
      ];

      // For non-recursive listing, use delimiter
      if (!$recursive) {
        $options['delimiter'] = '/';
      }

      $objects = $this->bucket->objects($options);

      foreach ($objects as $object) {
        // Remove prefix to get relative path
        $name = $object->name();
        $relativePath = $this->prefix
          ? substr($name, strlen($this->prefix) + 1)
          : $name;

        $files[] = $relativePath;
      }

      return $files;
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to list GCS contents: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      // Try to check if bucket exists
      return $this->bucket->exists();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'gcs';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): array {
    // Return sanitized config (remove sensitive credentials)
    return [
      'type' => 'gcs',
      'project_id' => $this->config['project_id'],
      'bucket' => $this->bucketName,
      'prefix' => $this->prefix,
      // Mask credentials
      'key_file' => !empty($this->config['key_file']) ? '***' : NULL,
    ];
  }

  /**
   * Get the full GCS object key including prefix.
   *
   * @param string $path Relative path
   *
   * @return string Full object key
   */
  private function getKey(string $path): string {
    $path = ltrim($path, '/');

    if ($this->prefix) {
      return $this->prefix . '/' . $path;
    }

    return $path;
  }
}