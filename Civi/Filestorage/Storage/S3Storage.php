<?php

namespace Civi\Filestorage\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use CRM_Core_Exception;

/**
 * AWS S3 storage adapter.
 *
 * This adapter handles file operations with Amazon S3 (Simple Storage Service)
 * and S3-compatible services like DigitalOcean Spaces, MinIO, Wasabi, etc.
 *
 * Features:
 * - Upload/download files to/from S3 buckets
 * - Generate signed URLs for secure file access
 * - Support for public and private files
 * - Multipart uploads for large files (handled by AWS SDK)
 * - Custom endpoints for S3-compatible services
 *
 * @package Civi\Filestorage\Storage
 */
class S3Storage implements StorageInterface {

  /**
   * AWS S3 client instance.
   *
   * @var S3Client
   */
  private $client;

  /**
   * S3 bucket name.
   *
   * @var string
   */
  private $bucket;

  /**
   * Path prefix within the bucket (optional).
   *
   * Useful for organizing files in a specific "folder" structure.
   * Example: 'civicrm/files/' will prepend this to all paths.
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
   * CDN URL for public file access (optional).
   *
   * If configured, public file URLs will use this CDN instead of S3 URLs.
   *
   * @var string|null
   */
  private $cdnUrl;

  /**
   * Constructor.
   *
   * @param array $config Configuration array with:
   *   - 'key' => string - AWS access key ID (required)
   *   - 'secret' => string - AWS secret access key (required)
   *   - 'region' => string - AWS region (default: 'us-east-1')
   *   - 'bucket' => string - S3 bucket name (required)
   *   - 'prefix' => string - Path prefix (optional)
   *   - 'endpoint' => string - Custom endpoint for S3-compatible services (optional)
   *   - 'use_path_style' => bool - Use path-style URLs (required for some services)
   *   - 'cdn_url' => string - CDN URL for public files (optional)
   *   - 'version' => string - S3 API version (default: 'latest')
   *
   * @throws CRM_Core_Exception If required configuration is missing
   */
  public function __construct(array $config) {
    $this->config = $config;

    // Validate required configuration
    if (empty($config['key'])) {
      throw new CRM_Core_Exception("S3 storage requires 'key' configuration");
    }
    if (empty($config['secret'])) {
      throw new CRM_Core_Exception("S3 storage requires 'secret' configuration");
    }
    if (empty($config['bucket'])) {
      throw new CRM_Core_Exception("S3 storage requires 'bucket' configuration");
    }

    $this->bucket = $config['bucket'];
    $this->prefix = rtrim($config['prefix'] ?? '', '/');
    $this->cdnUrl = !empty($config['cdn_url']) ? rtrim($config['cdn_url'], '/') : NULL;

    // Initialize S3 client
    $clientConfig = [
      'version' => $config['version'] ?? 'latest',
      'region' => $config['region'] ?? 'us-east-1',
      'credentials' => [
        'key' => $config['key'],
        'secret' => $config['secret'],
      ],
    ];

    // Add custom endpoint if provided (for S3-compatible services)
    if (!empty($config['endpoint'])) {
      $clientConfig['endpoint'] = $config['endpoint'];
      $clientConfig['use_path_style_endpoint'] = $config['use_path_style'] ?? TRUE;
    }

    try {
      $this->client = new S3Client($clientConfig);
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to initialize S3 client: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, $contents, array $config = []): bool {
    $key = $this->getKey($path);

    try {
      $params = [
        'Bucket' => $this->bucket,
        'Key' => $key,
      ];

      // Handle content
      if (is_resource($contents)) {
        $params['Body'] = $contents;
      }
      else {
        $params['Body'] = $contents;
      }

      // Set ACL (Access Control List)
      $visibility = $config['visibility'] ?? 'private';
      $params['ACL'] = $visibility === 'public' ? 'public-read' : 'private';

      // Set Content-Type if provided
      if (!empty($config['mime_type'])) {
        $params['ContentType'] = $config['mime_type'];
      }

      // Set custom metadata
      if (!empty($config['metadata'])) {
        $params['Metadata'] = $config['metadata'];
      }

      // Upload to S3
      $result = $this->client->putObject($params);

      return isset($result['ObjectURL']) || isset($result['@metadata']['effectiveUri']);
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to upload file to S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    $key = $this->getKey($path);

    try {
      $result = $this->client->getObject([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      return (string)$result['Body'];
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to read file from S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    $key = $this->getKey($path);

    try {
      $result = $this->client->getObject([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      // The Body is already a stream resource
      return $result['Body']->detach();
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to read stream from S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): bool {
    $key = $this->getKey($path);

    try {
      $this->client->deleteObject([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      return TRUE;
    }
    catch (AwsException $e) {
      // If file doesn't exist, consider it deleted
      if ($e->getAwsErrorCode() === 'NoSuchKey') {
        return TRUE;
      }

      throw new CRM_Core_Exception("Failed to delete file from S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exists(string $path): bool {
    $key = $this->getKey($path);

    try {
      $this->client->headObject([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      return TRUE;
    }
    catch (AwsException $e) {
      // NoSuchKey error means file doesn't exist
      if ($e->getAwsErrorCode() === 'NotFound' || $e->getAwsErrorCode() === 'NoSuchKey') {
        return FALSE;
      }

      // Other errors should be thrown
      throw new CRM_Core_Exception("Failed to check if file exists in S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $from, string $to): bool {
    $fromKey = $this->getKey($from);
    $toKey = $this->getKey($to);

    try {
      $this->client->copyObject([
        'Bucket' => $this->bucket,
        'CopySource' => $this->bucket . '/' . $fromKey,
        'Key' => $toKey,
      ]);

      return TRUE;
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to copy file in S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $from, string $to): bool {
    // S3 doesn't have a native move operation, so copy then delete
    $this->copy($from, $to);
    $this->delete($from);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(string $path, int $ttl = 3600): string {
    $key = $this->getKey($path);

    // If CDN URL is configured and file should be public, use CDN
    if ($this->cdnUrl && $ttl === 0) {
      return $this->cdnUrl . '/' . $key;
    }

    try {
      // Check if object has public-read ACL
      $acl = $this->client->getObjectAcl([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      $isPublic = FALSE;
      foreach ($acl['Grants'] as $grant) {
        if (isset($grant['Grantee']['URI']) &&
          strpos($grant['Grantee']['URI'], 'AllUsers') !== FALSE &&
          $grant['Permission'] === 'READ') {
          $isPublic = TRUE;
          break;
        }
      }

      // For public files with no TTL, return direct URL
      if ($isPublic && $ttl === 0) {
        return $this->client->getObjectUrl($this->bucket, $key);
      }

      // Generate signed URL for private files or when TTL is specified
      $cmd = $this->client->getCommand('GetObject', [
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      $request = $this->client->createPresignedRequest($cmd, "+{$ttl} seconds");

      return (string)$request->getUri();
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to generate S3 URL: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(string $path): array {
    $key = $this->getKey($path);

    try {
      $result = $this->client->headObject([
        'Bucket' => $this->bucket,
        'Key' => $key,
      ]);

      return [
        'size' => $result['ContentLength'] ?? 0,
        'mime_type' => $result['ContentType'] ?? 'application/octet-stream',
        'last_modified' => strtotime($result['LastModified'] ?? 'now'),
        'etag' => trim($result['ETag'] ?? '', '"'),
        'visibility' => 'private', // Would need ACL check to determine actual visibility
        'metadata' => $result['Metadata'] ?? [],
      ];
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to get metadata from S3: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSize(string $path): int {
    $metadata = $this->getMetadata($path);
    return $metadata['size'];
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
      $params = [
        'Bucket' => $this->bucket,
        'Prefix' => $prefix,
      ];

      // For non-recursive listing, use delimiter
      if (!$recursive) {
        $params['Delimiter'] = '/';
      }

      $result = $this->client->listObjectsV2($params);

      if (isset($result['Contents'])) {
        foreach ($result['Contents'] as $object) {
          // Remove prefix to get relative path
          $relativePath = $this->prefix
            ? substr($object['Key'], strlen($this->prefix) + 1)
            : $object['Key'];

          $files[] = $relativePath;
        }
      }

      return $files;
    }
    catch (AwsException $e) {
      throw new CRM_Core_Exception("Failed to list S3 contents: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      // Try to list objects with limit 1 to test connection
      $this->client->listObjectsV2([
        'Bucket' => $this->bucket,
        'MaxKeys' => 1,
      ]);

      return TRUE;
    }
    catch (AwsException $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    // Return 'spaces' if this is DigitalOcean Spaces, otherwise 's3'
    if (!empty($this->config['endpoint']) &&
      strpos($this->config['endpoint'], 'digitaloceanspaces.com') !== FALSE) {
      return 'spaces';
    }

    return 's3';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): array {
    // Return sanitized config (remove sensitive credentials)
    return [
      'type' => $this->getType(),
      'bucket' => $this->bucket,
      'region' => $this->config['region'] ?? 'us-east-1',
      'prefix' => $this->prefix,
      'endpoint' => $this->config['endpoint'] ?? NULL,
      'cdn_url' => $this->cdnUrl,
      // Mask credentials
      'key' => substr($this->config['key'], 0, 4) . '***',
    ];
  }

  /**
   * Get the full S3 key including prefix.
   *
   * Combines the configured prefix with the relative path to create
   * the complete S3 object key.
   *
   * @param string $path Relative path
   *
   * @return string Full S3 key
   */
  private function getKey(string $path): string {
    $path = ltrim($path, '/');

    if ($this->prefix) {
      return $this->prefix . '/' . $path;
    }

    return $path;
  }
}