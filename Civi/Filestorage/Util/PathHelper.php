<?php

namespace Civi\Filestorage\Util;

use CRM_Core_Exception;

/**
 * Path Helper Utility Class.
 *
 * Provides utility functions for generating, cleaning, and validating file paths
 * across different storage backends. Ensures consistent path structure and
 * prevents security issues like directory traversal.
 *
 * @package Civi\Filestorage\Util
 */
class PathHelper {

  /**
   * Characters that are unsafe in filenames.
   * These will be replaced with underscores.
   *
   * @var array
   */
  private static $unsafeChars = [
    '/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0",
    '{', '}', '[', ']', '`', '&', ';', '#', '%', '$',
  ];

  /**
   * Maximum filename length (excluding path).
   *
   * @var int
   */
  const MAX_FILENAME_LENGTH = 255;

  /**
   * Generate a standardized storage path for a file.
   *
   * Creates an organized path structure: {entity}/{year}/{month}/{day}/{filename}
   * This provides good organization while avoiding too many files in one directory.
   *
   * @param array $params File parameters:
   *   - 'filename' => string - Original filename
   *   - 'entity_type' => string - Entity type (activity, contact, etc.)
   *   - 'entity_id' => int - Entity ID
   *   - 'file_id' => int - File ID (for uniqueness)
   *   - 'upload_date' => string - Upload timestamp
   *   - 'mime_type' => string - MIME type
   *
   * @return string Generated path (e.g., "activity/2025/10/07/document_a1b2c3d4.pdf")
   */
  public static function generatePath(array $params): string {
    // Determine date-based path components
    $timestamp = !empty($params['upload_date'])
      ? strtotime($params['upload_date'])
      : time();

    $year = date('Y', $timestamp);
    $month = date('m', $timestamp);
    $day = date('d', $timestamp);

    // Determine entity-based path component
    $entityType = self::normalizeEntityType($params['entity_type'] ?? 'files');

    // Generate unique, clean filename
    $filename = self::generateUniqueFilename(
      $params['filename'] ?? 'file',
      $params['file_id'] ?? NULL,
      $params['mime_type'] ?? NULL
    );

    // Construct path: entity/year/month/day/filename
    $pathParts = [$entityType, $year, $month, $day, $filename];

    return implode('/', $pathParts);
  }

  /**
   * Generate a unique filename with hash to prevent collisions.
   *
   * Takes an original filename and adds a unique hash while preserving
   * the extension. Cleans unsafe characters.
   *
   * @param string $originalFilename Original filename
   * @param int|null $fileId File ID for hash generation
   * @param string|null $mimeType MIME type for extension fallback
   *
   * @return string Unique, safe filename (e.g., "document_a1b2c3d4.pdf")
   */
  public static function generateUniqueFilename(
    string $originalFilename,
    ?int $fileId = NULL,
    ?string $mimeType = NULL
  ): string {
    // Extract extension
    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $basename = pathinfo($originalFilename, PATHINFO_FILENAME);

    // If no extension, try to determine from MIME type
    if (empty($extension) && $mimeType) {
      $extension = self::getExtensionFromMimeType($mimeType);
    }

    // Clean the basename
    $cleanBasename = self::cleanFilename($basename);

    // Truncate if too long (leave room for hash and extension)
    $maxBaseLength = self::MAX_FILENAME_LENGTH - 10 - strlen($extension);
    if (strlen($cleanBasename) > $maxBaseLength) {
      $cleanBasename = substr($cleanBasename, 0, $maxBaseLength);
    }

    // Generate unique hash
    $hash = substr(md5(uniqid() . $fileId . $originalFilename), 0, 8);

    // Construct unique filename
    $uniqueFilename = $cleanBasename . '_' . $hash;

    if ($extension) {
      $uniqueFilename .= '.' . strtolower($extension);
    }

    return $uniqueFilename;
  }

  /**
   * Clean a filename by removing or replacing unsafe characters.
   *
   * Removes special characters that might cause issues in URLs or filesystems.
   * Preserves Unicode characters for international filenames.
   *
   * @param string $filename Filename to clean
   * @param string $replacement Character to replace unsafe chars with (default: '_')
   *
   * @return string Cleaned filename
   */
  public static function cleanFilename(string $filename, string $replacement = '_'): string {
    // Replace unsafe characters
    $cleaned = str_replace(self::$unsafeChars, $replacement, $filename);

    // Remove any control characters
    $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleaned);

    // Replace multiple consecutive replacements with single
    $cleaned = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $cleaned);

    // Trim replacement characters from ends
    $cleaned = trim($cleaned, $replacement);

    // If nothing left, use default
    if (empty($cleaned)) {
      $cleaned = 'file';
    }

    return $cleaned;
  }

  /**
   * Normalize entity type to a clean path component.
   *
   * Converts CiviCRM entity table names to clean directory names.
   * Example: "civicrm_activity" -> "activity"
   *
   * @param string $entityType Entity type or table name
   *
   * @return string Normalized entity type
   */
  public static function normalizeEntityType(string $entityType): string {
    // Remove civicrm_ prefix if present
    $normalized = preg_replace('/^civicrm_/', '', strtolower($entityType));

    // Clean the name
    $normalized = self::cleanFilename($normalized, '-');

    // If empty, use default
    if (empty($normalized)) {
      $normalized = 'files';
    }

    return $normalized;
  }

  /**
   * Validate a path for security issues.
   *
   * Checks for directory traversal attempts and other security issues.
   *
   * @param string $path Path to validate
   * @param bool $throwException Whether to throw exception on invalid path
   *
   * @return bool TRUE if valid, FALSE if invalid
   *
   * @throws CRM_Core_Exception If path is invalid and $throwException is TRUE
   */
  public static function validatePath(string $path, bool $throwException = TRUE): bool {
    // Check for directory traversal attempts
    if (strpos($path, '..') !== FALSE) {
      if ($throwException) {
        throw new CRM_Core_Exception('Path contains directory traversal: ' . $path);
      }
      return FALSE;
    }

    // Check for absolute paths (should be relative)
    if (strpos($path, '/') === 0 || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
      if ($throwException) {
        throw new CRM_Core_Exception('Path must be relative: ' . $path);
      }
      return FALSE;
    }

    // Check for null bytes
    if (strpos($path, "\0") !== FALSE) {
      if ($throwException) {
        throw new CRM_Core_Exception('Path contains null byte');
      }
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Join path components safely.
   *
   * Joins multiple path segments with proper separators and normalization.
   *
   * @param string ...$parts Path components to join
   *
   * @return string Joined path
   */
  public static function join(string ...$parts): string {
    // Filter out empty parts
    $parts = array_filter($parts, function($part) {
      return $part !== NULL && $part !== '';
    });

    // Join with forward slash
    $path = implode('/', $parts);

    // Normalize multiple slashes
    $path = preg_replace('#/+#', '/', $path);

    // Remove leading/trailing slashes
    $path = trim($path, '/');

    return $path;
  }

  /**
   * Get file extension from MIME type.
   *
   * Returns appropriate file extension for common MIME types.
   *
   * @param string $mimeType MIME type
   *
   * @return string File extension (without dot)
   */
  public static function getExtensionFromMimeType(string $mimeType): string {
    $mimeToExt = [
      // Documents
      'application/pdf' => 'pdf',
      'application/msword' => 'doc',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
      'application/vnd.ms-powerpoint' => 'ppt',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
      'application/vnd.oasis.opendocument.text' => 'odt',
      'application/vnd.oasis.opendocument.spreadsheet' => 'ods',

      // Images
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/bmp' => 'bmp',
      'image/webp' => 'webp',
      'image/svg+xml' => 'svg',
      'image/tiff' => 'tiff',

      // Archives
      'application/zip' => 'zip',
      'application/x-rar-compressed' => 'rar',
      'application/x-7z-compressed' => '7z',
      'application/x-tar' => 'tar',
      'application/gzip' => 'gz',

      // Text
      'text/plain' => 'txt',
      'text/html' => 'html',
      'text/css' => 'css',
      'text/javascript' => 'js',
      'text/csv' => 'csv',
      'application/json' => 'json',
      'application/xml' => 'xml',

      // Media
      'audio/mpeg' => 'mp3',
      'audio/wav' => 'wav',
      'audio/ogg' => 'ogg',
      'video/mp4' => 'mp4',
      'video/mpeg' => 'mpeg',
      'video/quicktime' => 'mov',
      'video/x-msvideo' => 'avi',
    ];

    return $mimeToExt[$mimeType] ?? 'bin';
  }

  /**
   * Get MIME type from file extension.
   *
   * Returns common MIME type for file extensions.
   *
   * @param string $extension File extension (with or without dot)
   *
   * @return string MIME type
   */
  public static function getMimeTypeFromExtension(string $extension): string {
    // Remove leading dot if present
    $extension = ltrim(strtolower($extension), '.');

    $extToMime = [
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'bmp' => 'image/bmp',
      'svg' => 'image/svg+xml',
      'zip' => 'application/zip',
      'rar' => 'application/x-rar-compressed',
      'txt' => 'text/plain',
      'html' => 'text/html',
      'csv' => 'text/csv',
      'json' => 'application/json',
      'xml' => 'application/xml',
      'mp3' => 'audio/mpeg',
      'mp4' => 'video/mp4',
    ];

    return $extToMime[$extension] ?? 'application/octet-stream';
  }

  /**
   * Parse a storage path into components.
   *
   * Extracts entity type, date components, and filename from a path.
   *
   * @param string $path Storage path to parse
   *
   * @return array Parsed components:
   *   - 'entity_type' => string
   *   - 'year' => string
   *   - 'month' => string
   *   - 'day' => string
   *   - 'filename' => string
   *   - 'extension' => string
   */
  public static function parsePath(string $path): array {
    $parts = explode('/', $path);
    $count = count($parts);

    $result = [
      'entity_type' => NULL,
      'year' => NULL,
      'month' => NULL,
      'day' => NULL,
      'filename' => NULL,
      'extension' => NULL,
    ];

    // Expected format: entity/year/month/day/filename
    if ($count >= 5) {
      $result['entity_type'] = $parts[$count - 5];
      $result['year'] = $parts[$count - 4];
      $result['month'] = $parts[$count - 3];
      $result['day'] = $parts[$count - 2];
      $result['filename'] = $parts[$count - 1];
    } elseif ($count > 0) {
      // Fallback: just get filename
      $result['filename'] = end($parts);
    }

    // Extract extension
    if ($result['filename']) {
      $result['extension'] = pathinfo($result['filename'], PATHINFO_EXTENSION);
    }

    return $result;
  }

  /**
   * Get the directory portion of a path.
   *
   * @param string $path Full path
   *
   * @return string Directory path (without filename)
   */
  public static function getDirectory(string $path): string {
    $pos = strrpos($path, '/');

    if ($pos === FALSE) {
      return '';
    }

    return substr($path, 0, $pos);
  }

  /**
   * Get the filename portion of a path.
   *
   * @param string $path Full path
   *
   * @return string Filename (without directory)
   */
  public static function getFilename(string $path): string {
    $pos = strrpos($path, '/');

    if ($pos === FALSE) {
      return $path;
    }

    return substr($path, $pos + 1);
  }

  /**
   * Format file size in human-readable format.
   *
   * @param int $bytes File size in bytes
   * @param int $decimals Number of decimal places
   *
   * @return string Formatted size (e.g., "1.5 MB")
   */
  public static function formatFileSize(int $bytes, int $decimals = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
      $bytes /= 1024;
    }

    return round($bytes, $decimals) . ' ' . $units[$i];
  }
}