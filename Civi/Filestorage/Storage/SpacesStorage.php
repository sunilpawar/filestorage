<?php

namespace Civi\Filestorage\Storage;

/**
 * DigitalOcean Spaces storage adapter.
 *
 * DigitalOcean Spaces is S3-compatible, so this adapter extends S3Storage
 * with Spaces-specific configuration defaults and endpoint handling.
 *
 * Spaces provides:
 * - S3-compatible API
 * - Built-in CDN (SpacesCDN)
 * - Simple pricing ($5/month for 250GB + 1TB transfer)
 * - Multiple regions (NYC3, SFO3, SGP1, etc.)
 *
 * This is often the most cost-effective option for CiviCRM file storage,
 * especially for Upsun/Platform.sh deployments.
 *
 * @package Civi\Filestorage\Storage
 */
class SpacesStorage extends S3Storage {

  /**
   * Constructor.
   *
   * @param array $config Configuration array with:
   *   - 'key' => string - Spaces access key (required)
   *   - 'secret' => string - Spaces secret key (required)
   *   - 'region' => string - Spaces region (nyc3, sfo3, sgp1, etc.) (required)
   *   - 'bucket' => string - Space name (required)
   *   - 'prefix' => string - Path prefix (optional)
   *   - 'cdn_url' => string - SpacesCDN URL (optional but recommended)
   *
   * @throws \CRM_Core_Exception If required configuration is missing
   */
  public function __construct(array $config) {
    // Set Spaces-specific defaults
    $config['endpoint'] = $this->getSpacesEndpoint($config['region'] ?? 'nyc3');
    $config['use_path_style'] = TRUE; // Spaces requires path-style URLs
    $config['version'] = 'latest';

    // If CDN URL not provided, generate it
    if (empty($config['cdn_url']) && !empty($config['bucket']) && !empty($config['region'])) {
      $config['cdn_url'] = sprintf(
        'https://%s.%s.cdn.digitaloceanspaces.com',
        $config['bucket'],
        $config['region']
      );
    }

    // Call parent S3Storage constructor
    parent::__construct($config);
  }

  /**
   * Get the Spaces endpoint URL for a region.
   *
   * @param string $region Spaces region code
   *
   * @return string Endpoint URL
   */
  private function getSpacesEndpoint(string $region): string {
    // DigitalOcean Spaces endpoint format
    return sprintf('https://%s.digitaloceanspaces.com', $region);
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'spaces';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): array {
    $config = parent::getConfig();
    $config['type'] = 'spaces';

    // Add Spaces-specific info
    if (!empty($this->config['region'])) {
      $config['region'] = $this->config['region'];
    }

    return $config;
  }

  /**
   * {@inheritdoc}
   *
   * Override to use CDN URL for public files by default.
   */
  public function getUrl(string $path, int $ttl = 3600): string {
    // For public files with no TTL, prefer CDN URL if available
    if ($ttl === 0 && $this->cdnUrl) {
      $key = $this->getKey($path);
      return $this->cdnUrl . '/' . $key;
    }

    // Otherwise use parent S3 signed URL logic
    return parent::getUrl($path, $ttl);
  }

  /**
   * Get available Spaces regions.
   *
   * @return array Array of region codes and names
   */
  public static function getAvailableRegions(): array {
    return [
      'nyc3' => 'New York 3',
      'sfo3' => 'San Francisco 3',
      'sgp1' => 'Singapore 1',
      'fra1' => 'Frankfurt 1',
      'ams3' => 'Amsterdam 3',
      'blr1' => 'Bangalore 1',
      'syd1' => 'Sydney 1',
    ];
  }

  /**
   * Estimate monthly cost for Spaces storage.
   *
   * Spaces pricing (as of 2025):
   * - $5/month base (includes 250GB storage + 1TB outbound transfer)
   * - $0.02/GB for additional storage
   * - $0.01/GB for additional transfer
   *
   * @param int $storageGB Storage in GB
   * @param int $transferGB Monthly transfer in GB
   *
   * @return float Estimated monthly cost in USD
   */
  public static function estimateCost(int $storageGB, int $transferGB = 0): float {
    $baseCost = 5.00;
    $includedStorage = 250; // GB
    $includedTransfer = 1024; // GB (1TB)

    $cost = $baseCost;

    // Additional storage cost
    if ($storageGB > $includedStorage) {
      $additionalStorage = $storageGB - $includedStorage;
      $cost += $additionalStorage * 0.02;
    }

    // Additional transfer cost
    if ($transferGB > $includedTransfer) {
      $additionalTransfer = $transferGB - $includedTransfer;
      $cost += $additionalTransfer * 0.01;
    }

    return round($cost, 2);
  }

  /**
   * Create a Space (bucket) if it doesn't exist.
   *
   * @param string $spaceName Space name
   * @param string $region Region code
   * @param bool $publicRead Make space publicly readable
   *
   * @return bool TRUE on success
   *
   * @throws \CRM_Core_Exception
   */
  public function createSpace(string $spaceName, string $region, bool $publicRead = FALSE): bool {
    try {
      // Check if space exists
      if ($this->client->doesBucketExist($spaceName)) {
        return TRUE;
      }

      // Create space
      $this->client->createBucket([
        'Bucket' => $spaceName,
      ]);

      // Set ACL if public
      if ($publicRead) {
        $this->client->putBucketAcl([
          'Bucket' => $spaceName,
          'ACL' => 'public-read',
        ]);
      }

      return TRUE;
    }
    catch (\Exception $e) {
      throw new \CRM_Core_Exception("Failed to create Space: " . $e->getMessage());
    }
  }

  /**
   * Enable SpacesCDN for a Space.
   *
   * @param string $spaceName Space name
   * @param string $region Region code
   *
   * @return string CDN URL
   *
   * @throws \CRM_Core_Exception
   */
  public function enableCDN(string $spaceName, string $region): string {
    // Note: SpacesCDN is automatically enabled for all Spaces
    // This method returns the CDN URL

    $cdnUrl = sprintf(
      'https://%s.%s.cdn.digitaloceanspaces.com',
      $spaceName,
      $region
    );

    return $cdnUrl;
  }

  /**
   * Set CORS configuration for a Space.
   *
   * Useful for allowing browser uploads or cross-origin access.
   *
   * @param array $allowedOrigins Array of allowed origins (e.g., ['https://example.com'])
   * @param array $allowedMethods Array of allowed methods (default: ['GET', 'PUT', 'POST', 'DELETE'])
   *
   * @return bool TRUE on success
   *
   * @throws \CRM_Core_Exception
   */
  public function setCORS(array $allowedOrigins, array $allowedMethods = ['GET', 'PUT', 'POST', 'DELETE']): bool {
    try {
      $this->client->putBucketCors([
        'Bucket' => $this->bucket,
        'CORSConfiguration' => [
          'CORSRules' => [
            [
              'AllowedOrigins' => $allowedOrigins,
              'AllowedMethods' => $allowedMethods,
              'AllowedHeaders' => ['*'],
              'MaxAgeSeconds' => 3600,
            ],
          ],
        ],
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      throw new \CRM_Core_Exception("Failed to set CORS: " . $e->getMessage());
    }
  }

  /**
   * Get Space usage statistics.
   *
   * Note: DigitalOcean doesn't provide real-time usage stats via API.
   * This method calculates usage by listing all objects.
   *
   * @return array Usage statistics:
   *   - 'object_count' => int
   *   - 'total_size' => int (bytes)
   *   - 'total_size_formatted' => string
   *
   * @throws \CRM_Core_Exception
   */
  public function getUsageStats(): array {
    try {
      $objectCount = 0;
      $totalSize = 0;

      $paginator = $this->client->getPaginator('ListObjectsV2', [
        'Bucket' => $this->bucket,
      ]);

      foreach ($paginator as $result) {
        foreach ($result['Contents'] ?? [] as $object) {
          $objectCount++;
          $totalSize += $object['Size'] ?? 0;
        }
      }

      return [
        'object_count' => $objectCount,
        'total_size' => $totalSize,
        'total_size_formatted' => \Civi\Filestorage\Util\PathHelper::formatFileSize($totalSize),
      ];
    }
    catch (\Exception $e) {
      throw new \CRM_Core_Exception("Failed to get usage stats: " . $e->getMessage());
    }
  }
}