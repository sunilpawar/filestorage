<?php

namespace Civi\Filestorage\Util;

use Civi\Filestorage\Storage\StorageFactory;
use CRM_Core_Exception;

/**
 * URL Generator Utility Class.
 *
 * Generates URLs for accessing files stored in various storage backends.
 * Handles signed URLs, CDN URLs, proxy URLs, and download URLs.
 *
 * @package Civi\Filestorage\Util
 */
class UrlGenerator {

  /**
   * Default TTL for signed URLs (in seconds).
   *
   * @var int
   */
  const DEFAULT_TTL = 3600; // 1 hour

  /**
   * Maximum TTL for signed URLs (in seconds).
   *
   * @var int
   */
  const MAX_TTL = 86400; // 24 hours

  /**
   * Generate a download URL for a file.
   *
   * This is the main method for getting a URL to download a file.
   * It handles different storage types and returns the appropriate URL.
   *
   * @param int $fileId CiviCRM file ID
   * @param array $options URL generation options:
   *   - 'ttl' => int - Time-to-live for signed URLs (default: 3600)
   *   - 'disposition' => string - 'inline' or 'attachment' (default: 'attachment')
   *   - 'filename' => string - Override download filename
   *   - 'force_download' => bool - Force browser to download vs display
   *
   * @return string Download URL
   *
   * @throws CRM_Core_Exception If file not found
   */
  public static function getDownloadUrl(int $fileId, array $options = []): string {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    // Determine storage type
    $storageType = $file['storage_type'] ?? 'local';

    // Generate URL based on storage type
    if ($storageType === 'local') {
      return self::getLocalFileUrl($file, $options);
    }
    else {
      return self::getRemoteFileUrl($file, $options);
    }
  }

  /**
   * Generate URL for a local file.
   *
   * Creates a URL that goes through CiviCRM's download handler.
   * This ensures permission checks are enforced.
   *
   * @param array $file File record
   * @param array $options URL options
   *
   * @return string Local file URL
   */
  private static function getLocalFileUrl(array $file, array $options = []): string {
    $baseUrl = \CRM_Utils_System::baseURL();

    // Build query parameters
    $params = [
      'id' => $file['id'],
    ];

    // Add disposition if specified
    if (!empty($options['disposition'])) {
      $params['disposition'] = $options['disposition'];
    }

    // Add custom filename if specified
    if (!empty($options['filename'])) {
      $params['filename'] = $options['filename'];
    }

    // Build URL
    $url = $baseUrl . 'civicrm/file?' . http_build_query($params);

    return $url;
  }

  /**
   * Generate URL for a remote file.
   *
   * Creates a signed URL directly to the storage provider, or a proxy URL
   * through CiviCRM depending on configuration.
   *
   * @param array $file File record
   * @param array $options URL options
   *
   * @return string Remote file URL
   */
  private static function getRemoteFileUrl(array $file, array $options = []): string {
    // Check if we should proxy through CiviCRM or use direct URLs
    $useProxy = \Civi::settings()->get('filestorage_use_proxy') ?? FALSE;

    if ($useProxy) {
      // Generate proxy URL through CiviCRM
      return self::getProxyUrl($file, $options);
    }
    else {
      // Generate direct signed URL from storage provider
      return self::getSignedUrl($file, $options);
    }
  }

  /**
   * Generate a signed URL directly from the storage provider.
   *
   * Creates a time-limited URL with authentication for accessing the file.
   *
   * @param array $file File record
   * @param array $options URL options
   *
   * @return string Signed URL
   */
  private static function getSignedUrl(array $file, array $options = []): string {
    // Get TTL (time-to-live)
    $ttl = $options['ttl'] ?? self::DEFAULT_TTL;
    $ttl = min($ttl, self::MAX_TTL); // Cap at maximum

    // Get storage adapter
    $storage = StorageFactory::getAdapterForFile($file['id']);

    // Generate signed URL
    $url = $storage->getUrl($file['storage_path'], $ttl);

    return $url;
  }

  /**
   * Generate a proxy URL through CiviCRM.
   *
   * Creates a URL that proxies the file download through CiviCRM.
   * Useful for:
   * - Enforcing CiviCRM permissions
   * - Hiding actual storage location
   * - Logging downloads
   * - Adding custom headers
   *
   * @param array $file File record
   * @param array $options URL options
   *
   * @return string Proxy URL
   */
  private static function getProxyUrl(array $file, array $options = []): string {
    $baseUrl = \CRM_Utils_System::baseURL();

    // Generate token for security
    $token = self::generateToken($file['id']);

    // Build query parameters
    $params = [
      'id' => $file['id'],
      'token' => $token,
    ];

    // Add options
    if (!empty($options['disposition'])) {
      $params['disposition'] = $options['disposition'];
    }
    if (!empty($options['filename'])) {
      $params['filename'] = $options['filename'];
    }

    // Build proxy URL
    $url = $baseUrl . 'civicrm/filestorage/download?' . http_build_query($params);

    return $url;
  }

  /**
   * Generate a public URL for a file (if applicable).
   *
   * For public files on remote storage, generates a permanent URL.
   * For private files, falls back to signed URL.
   *
   * @param int $fileId File ID
   *
   * @return string|null Public URL or NULL if file is private
   */
  public static function getPublicUrl(int $fileId): ?string {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      return NULL;
    }

    // Check if file is public
    $metadata = !empty($file['storage_metadata'])
      ? json_decode($file['storage_metadata'], TRUE)
      : [];

    $isPublic = $metadata['visibility'] ?? 'private';

    if ($isPublic !== 'public') {
      return NULL;
    }

    // For local files
    if (($file['storage_type'] ?? 'local') === 'local') {
      return self::getLocalFileUrl($file);
    }

    // For remote files, get URL with zero TTL (permanent)
    try {
      $storage = StorageFactory::getAdapterForFile($fileId);
      return $storage->getUrl($file['storage_path'], 0);
    }
    catch (\Exception $e) {
      \Civi::log()->warning("Failed to generate public URL for file {$fileId}: " . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Generate a CDN URL for a file.
   *
   * If CDN is configured for the storage backend, returns CDN URL.
   * Otherwise falls back to regular download URL.
   *
   * @param int $fileId File ID
   * @param array $options URL options
   *
   * @return string CDN URL or download URL
   */
  public static function getCdnUrl(int $fileId, array $options = []): string {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    // Check if CDN is configured
    $metadata = !empty($file['storage_metadata'])
      ? json_decode($file['storage_metadata'], TRUE)
      : [];

    $cdnUrl = $metadata['cdn_url'] ?? NULL;

    if ($cdnUrl) {
      // Build CDN URL
      return rtrim($cdnUrl, '/') . '/' . ltrim($file['storage_path'], '/');
    }

    // Fallback to regular download URL
    return self::getDownloadUrl($fileId, $options);
  }

  /**
   * Generate an inline URL (display in browser, not download).
   *
   * Creates a URL with Content-Disposition: inline header.
   * Useful for images, PDFs that should display in browser.
   *
   * @param int $fileId File ID
   * @param array $options URL options
   *
   * @return string Inline URL
   */
  public static function getInlineUrl(int $fileId, array $options = []): string {
    $options['disposition'] = 'inline';
    return self::getDownloadUrl($fileId, $options);
  }

  /**
   * Generate a thumbnail URL for an image.
   *
   * If thumbnails are supported/configured, returns thumbnail URL.
   * Otherwise returns original image URL.
   *
   * @param int $fileId File ID
   * @param array $options Thumbnail options:
   *   - 'width' => int - Thumbnail width
   *   - 'height' => int - Thumbnail height
   *   - 'crop' => bool - Crop to fit dimensions
   *
   * @return string Thumbnail URL
   */
  public static function getThumbnailUrl(int $fileId, array $options = []): string {
    // Get file record
    $file = \Civi\Api4\File::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $fileId)
      ->execute()
      ->first();

    if (!$file) {
      throw new CRM_Core_Exception("File not found: {$fileId}");
    }

    // Check if file is an image
    $mimeType = $file['mime_type'] ?? '';
    if (strpos($mimeType, 'image/') !== 0) {
      // Not an image, return regular URL
      return self::getDownloadUrl($fileId);
    }

    // Check if thumbnails are enabled
    $useThumbnails = \Civi::settings()->get('filestorage_use_thumbnails') ?? FALSE;

    if (!$useThumbnails) {
      return self::getDownloadUrl($fileId);
    }

    // Build thumbnail URL with parameters
    $baseUrl = \CRM_Utils_System::baseURL();

    $params = [
      'id' => $fileId,
      'thumbnail' => 1,
    ];

    if (!empty($options['width'])) {
      $params['width'] = $options['width'];
    }
    if (!empty($options['height'])) {
      $params['height'] = $options['height'];
    }
    if (!empty($options['crop'])) {
      $params['crop'] = 1;
    }

    return $baseUrl . 'civicrm/filestorage/thumbnail?' . http_build_query($params);
  }

  /**
   * Generate a token for secure file access.
   *
   * Creates a hash token that can be validated later to ensure
   * URL wasn't tampered with.
   *
   * @param int $fileId File ID
   * @param int|null $ttl Token expiration time (NULL for no expiration)
   *
   * @return string Security token
   */
  public static function generateToken(int $fileId, ?int $ttl = NULL): string {
    $secret = \Civi::settings()->get('filestorage_secret') ?? CIVICRM_SITE_KEY;

    $expires = $ttl ? time() + $ttl : 0;

    $data = sprintf('%d|%d|%s', $fileId, $expires, $secret);

    return substr(md5($data), 0, 16);
  }

  /**
   * Validate a file access token.
   *
   * Checks if a token is valid for accessing a file.
   *
   * @param int $fileId File ID
   * @param string $token Token to validate
   * @param int|null $expires Expiration timestamp (NULL if no expiration)
   *
   * @return bool TRUE if valid, FALSE otherwise
   */
  public static function validateToken(int $fileId, string $token, ?int $expires = NULL): bool {
    // Check expiration
    if ($expires && $expires < time()) {
      return FALSE;
    }

    // Generate expected token
    $secret = \Civi::settings()->get('filestorage_secret') ?? CIVICRM_SITE_KEY;
    $data = sprintf('%d|%d|%s', $fileId, $expires ?? 0, $secret);
    $expectedToken = substr(md5($data), 0, 16);

    // Compare tokens (timing-safe)
    return hash_equals($expectedToken, $token);
  }

  /**
   * Generate bulk download URL for multiple files.
   *
   * Creates a URL that will generate a ZIP archive with multiple files.
   *
   * @param array $fileIds Array of file IDs
   * @param string|null $archiveName Optional name for the ZIP file
   *
   * @return string Bulk download URL
   */
  public static function getBulkDownloadUrl(array $fileIds, ?string $archiveName = NULL): string {
    $baseUrl = \CRM_Utils_System::baseURL();

    // Generate token for security
    $token = md5(implode(',', $fileIds) . CIVICRM_SITE_KEY);

    $params = [
      'files' => implode(',', $fileIds),
      'token' => $token,
    ];

    if ($archiveName) {
      $params['name'] = PathHelper::cleanFilename($archiveName);
    }

    return $baseUrl . 'civicrm/filestorage/bulk-download?' . http_build_query($params);
  }

  /**
   * Get the base URL for file storage.
   *
   * Returns the configured base URL for file access.
   *
   * @return string Base URL
   */
  public static function getBaseUrl(): string {
    return \CRM_Utils_System::baseURL();
  }

  /**
   * Build a query string from parameters.
   *
   * @param array $params Query parameters
   *
   * @return string Query string
   */
  private static function buildQuery(array $params): string {
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  }
}