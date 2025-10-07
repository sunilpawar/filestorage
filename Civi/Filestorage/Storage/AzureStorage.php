<?php

namespace Civi\Filestorage\Storage;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use CRM_Core_Exception;

/**
 * Azure Blob Storage adapter.
 *
 * This adapter handles file operations with Microsoft Azure Blob Storage.
 *
 * Features:
 * - Upload/download files to/from Azure containers
 * - Generate SAS (Shared Access Signature) URLs
 * - Support for public and private blobs
 * - Block blob management
 * - Streaming support for large files
 *
 * @package Civi\Filestorage\Storage
 */
class AzureStorage implements StorageInterface {

  /**
   * Azure Blob service client.
   *
   * @var BlobRestProxy
   */
  private $client;

  /**
   * Container name.
   *
   * @var string
   */
  private $container;

  /**
   * Path prefix within the container (optional).
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
   *   - 'account_name' => string - Azure storage account name (required)
   *   - 'account_key' => string - Azure storage account key (required)
   *   - 'container' => string - Container name (required)
   *   - 'prefix' => string - Path prefix (optional)
   *   - 'connection_string' => string - Alternative to account_name/key
   *
   * @throws CRM_Core_Exception If required configuration is missing
   */
  public function __construct(array $config) {
    $this->config = $config;

    // Validate required configuration
    if (empty($config['container'])) {
      throw new CRM_Core_Exception("Azure storage requires 'container' configuration");
    }

    $this->container = $config['container'];
    $this->prefix = rtrim($config['prefix'] ?? '', '/');

    // Build connection string
    if (!empty($config['connection_string'])) {
      $connectionString = $config['connection_string'];
    }
    elseif (!empty($config['account_name']) && !empty($config['account_key'])) {
      $connectionString = sprintf(
        'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
        $config['account_name'],
        $config['account_key']
      );
    }
    else {
      throw new CRM_Core_Exception(
        "Azure storage requires 'connection_string' or 'account_name' and 'account_key'"
      );
    }

    // Initialize Azure client
    try {
      $this->client = BlobRestProxy::createBlobService($connectionString);
    }
    catch (\Exception $e) {
      throw new CRM_Core_Exception("Failed to initialize Azure client: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, $contents, array $config = []): bool {
    $blobName = $this->getBlobName($path);

    try {
      $options = new CreateBlockBlobOptions();

      // Set Content-Type
      if (!empty($config['mime_type'])) {
        $options->setContentType($config['mime_type']);
      }

      // Set metadata
      if (!empty($config['metadata'])) {
        foreach ($config['metadata'] as $key => $value) {
          $options->setMetadata($key, $value);
        }
      }

      // Set visibility (Azure uses public access level)
      $visibility = $config['visibility'] ?? 'private';
      // Note: Container-level access control is set separately in Azure

      // Upload blob
      if (is_resource($contents)) {
        $this->client->createBlockBlob($this->container, $blobName, $contents, $options);
      }
      else {
        $this->client->createBlockBlob($this->container, $blobName, $contents, $options);
      }

      return TRUE;
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to upload file to Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    $blobName = $this->getBlobName($path);

    try {
      $blob = $this->client->getBlob($this->container, $blobName);
      return stream_get_contents($blob->getContentStream());
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to read file from Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    $blobName = $this->getBlobName($path);

    try {
      $blob = $this->client->getBlob($this->container, $blobName);
      return $blob->getContentStream();
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to read stream from Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): bool {
    $blobName = $this->getBlobName($path);

    try {
      $this->client->deleteBlob($this->container, $blobName);
      return TRUE;
    }
    catch (ServiceException $e) {
      // If blob doesn't exist, consider it deleted
      if ($e->getCode() === 404) {
        return TRUE;
      }

      throw new CRM_Core_Exception("Failed to delete file from Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exists(string $path): bool {
    $blobName = $this->getBlobName($path);

    try {
      $this->client->getBlobMetadata($this->container, $blobName);
      return TRUE;
    }
    catch (ServiceException $e) {
      if ($e->getCode() === 404) {
        return FALSE;
      }

      throw new CRM_Core_Exception("Failed to check if file exists in Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $from, string $to): bool {
    $fromBlob = $this->getBlobName($from);
    $toBlob = $this->getBlobName($to);

    try {
      // Build source URL
      $sourceUrl = $this->client->getBlobUrl($this->container, $fromBlob);

      // Copy blob
      $this->client->copyBlob($this->container, $toBlob, $sourceUrl);

      return TRUE;
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to copy file in Azure: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $from, string $to): bool {
    // Azure doesn't have native move, so copy then delete
    $this->copy($from, $to);
    $this->delete($from);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(string $path, int $ttl = 3600): string {
    $blobName = $this->getBlobName($path);

    try {
      // For permanent URLs (ttl = 0), return public URL if blob is public
      if ($ttl === 0) {
        // Check container public access level
        $properties = $this->client->getContainerProperties($this->container);
        $publicAccess = $properties->getPublicAccess();

        if ($publicAccess !== NULL) {
          return $this->client->getBlobUrl($this->container, $blobName);
        }
      }

      // Generate SAS (Shared Access Signature) URL
      $sas = $this->generateSasToken($blobName, $ttl);
      $url = $this->client->getBlobUrl($this->container, $blobName);

      return $url . '?' . $sas;
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to generate Azure URL: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(string $path): array {
    $blobName = $this->getBlobName($path);

    try {
      $properties = $this->client->getBlobProperties($this->container, $blobName);
      $blobProperties = $properties->getProperties();
      $metadata = $properties->getMetadata();

      return [
        'size' => $blobProperties->getContentLength(),
        'mime_type' => $blobProperties->getContentType() ?? 'application/octet-stream',
        'last_modified' => $blobProperties->getLastModified()->getTimestamp(),
        'etag' => $blobProperties->getETag(),
        'visibility' => 'private', // Would need container access check for accurate value
        'metadata' => $metadata,
      ];
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to get metadata from Azure: " . $e->getMessage());
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
    $prefix = $directory ? $this->getBlobName($directory) . '/' : $this->prefix;
    if ($prefix && !str_ends_with($prefix, '/')) {
      $prefix .= '/';
    }

    $files = [];

    try {
      $options = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
      $options->setPrefix($prefix);

      if (!$recursive) {
        $options->setDelimiter('/');
      }

      $result = $this->client->listBlobs($this->container, $options);

      foreach ($result->getBlobs() as $blob) {
        $name = $blob->getName();

        // Remove prefix to get relative path
        $relativePath = $this->prefix
          ? substr($name, strlen($this->prefix) + 1)
          : $name;

        $files[] = $relativePath;
      }

      return $files;
    }
    catch (ServiceException $e) {
      throw new CRM_Core_Exception("Failed to list Azure contents: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    try {
      // Try to get container properties
      $this->client->getContainerProperties($this->container);
      return TRUE;
    }
    catch (ServiceException $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'azure';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): array {
    // Return sanitized config (remove sensitive credentials)
    return [
      'type' => 'azure',
      'account_name' => $this->config['account_name'] ?? NULL,
      'container' => $this->container,
      'prefix' => $this->prefix,
      // Mask credentials
      'account_key' => !empty($this->config['account_key']) ? '***' : NULL,
    ];
  }

  /**
   * Get the full blob name including prefix.
   *
   * @param string $path Relative path
   *
   * @return string Full blob name
   */
  private function getBlobName(string $path): string {
    $path = ltrim($path, '/');

    if ($this->prefix) {
      return $this->prefix . '/' . $path;
    }

    return $path;
  }

  /**
   * Generate a SAS (Shared Access Signature) token.
   *
   * @param string $blobName Blob name
   * @param int $ttl Time-to-live in seconds
   *
   * @return string SAS token query string
   */
  private function generateSasToken(string $blobName, int $ttl): string {
    $signedExpiry = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl);
    $signedStart = gmdate('Y-m-d\TH:i:s\Z', time() - 300); // 5 minutes ago

    $canonicalizedResource = sprintf(
      '/blob/%s/%s/%s',
      $this->config['account_name'],
      $this->container,
      $blobName
    );

    $signedPermissions = 'r'; // Read permission
    $signedProtocol = 'https';
    $signedVersion = '2020-08-04';

    // Build string to sign
    $stringToSign = implode("\n", [
      $signedPermissions,
      $signedStart,
      $signedExpiry,
      $canonicalizedResource,
      '', // signedIdentifier
      '', // signedIP
      $signedProtocol,
      $signedVersion,
      '', // signedResource
      '', // signedSnapshotTime
      '', // signedEncryptionScope
      '', // rscc (Cache-Control)
      '', // rscd (Content-Disposition)
      '', // rsce (Content-Encoding)
      '', // rscl (Content-Language)
      '', // rsct (Content-Type)
    ]);

    // Sign the string
    $signature = base64_encode(
      hash_hmac('sha256', $stringToSign, base64_decode($this->config['account_key']), TRUE)
    );

    // Build SAS query parameters
    $sasParams = [
      'sp' => $signedPermissions,
      'st' => $signedStart,
      'se' => $signedExpiry,
      'spr' => $signedProtocol,
      'sv' => $signedVersion,
      'sr' => 'b', // blob
      'sig' => $signature,
    ];

    return http_build_query($sasParams);
  }
}