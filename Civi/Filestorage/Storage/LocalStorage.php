<?php

namespace Civi\Filestorage\Storage;

use CRM_Core_Exception;

/**
 * Local filesystem storage adapter.
 *
 * This adapter handles file operations on the local filesystem, maintaining
 * compatibility with CiviCRM's existing file storage structure. It wraps
 * native PHP filesystem functions with the StorageInterface API.
 *
 * This is the default and fallback storage method, ensuring backward
 * compatibility with existing CiviCRM installations.
 *
 * @package Civi\Filestorage\Storage
 */
class LocalStorage implements StorageInterface {

  /**
   * Base path for file storage.
   *
   * @var string
   */
  private $basePath;

  /**
   * Base URL for accessing files via HTTP.
   *
   * @var string
   */
  private $baseUrl;

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
   *   - 'base_path' => string - Base directory path
   *   - 'base_url' => string - Base URL for file access
   *   - 'upload_dir' => string - Upload directory (optional)
   *   - 'custom_dir' => string - Custom files directory (optional)
   */
  public function __construct(array $config) {
    $this->config = $config;
    $this->basePath = rtrim($config['base_path'] ?? '', '/');
    $this->baseUrl = rtrim($config['base_url'] ?? '', '/');

    // Ensure base path exists
    if (!is_dir($this->basePath)) {
      throw new CRM_Core_Exception("Base path does not exist: {$this->basePath}");
    }

    // Verify write permissions
    if (!is_writable($this->basePath)) {
      throw new CRM_Core_Exception("Base path is not writable: {$this->basePath}");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, $contents, array $config = []): bool {
    $fullPath = $this->getFullPath($path);

    // Ensure directory exists
    $directory = dirname($fullPath);
    if (!is_dir($directory)) {
      if (!mkdir($directory, 0755, TRUE)) {
        throw new CRM_Core_Exception("Failed to create directory: {$directory}");
      }
    }

    // Write file content
    if (is_resource($contents)) {
      // Handle stream resource
      $destination = fopen($fullPath, 'wb');
      if ($destination === FALSE) {
        throw new CRM_Core_Exception("Failed to open destination file: {$fullPath}");
      }

      stream_copy_to_stream($contents, $destination);
      fclose($destination);

      return TRUE;
    }
    else {
      // Handle string content
      $result = file_put_contents($fullPath, $contents);

      if ($result === FALSE) {
        throw new CRM_Core_Exception("Failed to write file: {$fullPath}");
      }

      return TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      throw new CRM_Core_Exception("File does not exist: {$path}");
    }

    $contents = file_get_contents($fullPath);

    if ($contents === FALSE) {
      throw new CRM_Core_Exception("Failed to read file: {$path}");
    }

    return $contents;
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      throw new CRM_Core_Exception("File does not exist: {$path}");
    }

    $stream = fopen($fullPath, 'rb');

    if ($stream === FALSE) {
      throw new CRM_Core_Exception("Failed to open file stream: {$path}");
    }

    return $stream;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): bool {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      // File doesn't exist, consider it already deleted
      return TRUE;
    }

    $result = unlink($fullPath);

    if (!$result) {
      throw new CRM_Core_Exception("Failed to delete file: {$path}");
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function exists(string $path): bool {
    $fullPath = $this->getFullPath($path);
    return file_exists($fullPath);
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $from, string $to): bool {
    $fromPath = $this->getFullPath($from);
    $toPath = $this->getFullPath($to);

    if (!file_exists($fromPath)) {
      throw new CRM_Core_Exception("Source file does not exist: {$from}");
    }

    // Ensure destination directory exists
    $directory = dirname($toPath);
    if (!is_dir($directory)) {
      mkdir($directory, 0755, TRUE);
    }

    $result = copy($fromPath, $toPath);

    if (!$result) {
      throw new CRM_Core_Exception("Failed to copy file from {$from} to {$to}");
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $from, string $to): bool {
    $fromPath = $this->getFullPath($from);
    $toPath = $this->getFullPath($to);

    if (!file_exists($fromPath)) {
      throw new CRM_Core_Exception("Source file does not exist: {$from}");
    }

    // Ensure destination directory exists
    $directory = dirname($toPath);
    if (!is_dir($directory)) {
      mkdir($directory, 0755, TRUE);
    }

    $result = rename($fromPath, $toPath);

    if (!$result) {
      throw new CRM_Core_Exception("Failed to move file from {$from} to {$to}");
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(string $path, int $ttl = 3600): string {
    // For local storage, return direct HTTP URL (no expiration)
    // TTL parameter is ignored for local storage as files are publicly accessible
    return $this->baseUrl . '/' . ltrim($path, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(string $path): array {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      throw new CRM_Core_Exception("File does not exist: {$path}");
    }

    return [
      'size' => filesize($fullPath),
      'mime_type' => mime_content_type($fullPath),
      'last_modified' => filemtime($fullPath),
      'visibility' => 'public', // Local files are typically public via web server
      'path' => $path,
      'full_path' => $fullPath,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSize(string $path): int {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      throw new CRM_Core_Exception("File does not exist: {$path}");
    }

    return filesize($fullPath);
  }

  /**
   * {@inheritdoc}
   */
  public function getMimeType(string $path): string {
    $fullPath = $this->getFullPath($path);

    if (!file_exists($fullPath)) {
      throw new CRM_Core_Exception("File does not exist: {$path}");
    }

    $mimeType = mime_content_type($fullPath);

    if ($mimeType === FALSE) {
      // Fallback: detect from extension
      return $this->getMimeTypeFromExtension($path);
    }

    return $mimeType;
  }

  /**
   * {@inheritdoc}
   */
  public function listContents(string $directory = '', bool $recursive = FALSE): array {
    $fullPath = $this->getFullPath($directory);

    if (!is_dir($fullPath)) {
      return [];
    }

    $files = [];

    if ($recursive) {
      // Recursive directory iteration
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $file) {
        if ($file->isFile()) {
          $relativePath = str_replace($this->basePath . '/', '', $file->getPathname());
          $files[] = $relativePath;
        }
      }
    }
    else {
      // Non-recursive listing
      $iterator = new \DirectoryIterator($fullPath);

      foreach ($iterator as $file) {
        if ($file->isFile()) {
          $relativePath = $directory ? $directory . '/' . $file->getFilename() : $file->getFilename();
          $files[] = $relativePath;
        }
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(): bool {
    // Test by checking if base path exists and is writable
    if (!is_dir($this->basePath)) {
      return FALSE;
    }

    if (!is_writable($this->basePath)) {
      return FALSE;
    }

    // Try to create and delete a test file
    $testFile = $this->basePath . '/.filestorage_test_' . uniqid();

    try {
      file_put_contents($testFile, 'test');

      if (!file_exists($testFile)) {
        return FALSE;
      }

      unlink($testFile);

      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'local';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): array {
    // Return sanitized config (no sensitive data for local storage)
    return [
      'type' => 'local',
      'base_path' => $this->basePath,
      'base_url' => $this->baseUrl,
    ];
  }

  /**
   * Get the full filesystem path for a relative path.
   *
   * Converts a relative path to an absolute filesystem path within
   * the base directory. Also performs security check to prevent
   * directory traversal attacks.
   *
   * @param string $path Relative path
   *
   * @return string Full filesystem path
   *
   * @throws CRM_Core_Exception If path attempts directory traversal
   */
  private function getFullPath(string $path): string {
    // Remove leading slash
    $path = ltrim($path, '/');

    // Construct full path
    $fullPath = $this->basePath . '/' . $path;

    // Normalize path (resolve . and ..)
    $realBasePath = realpath($this->basePath);
    $realFullPath = realpath($fullPath);

    // Security check: ensure the path is within base directory
    // This prevents directory traversal attacks (e.g., ../../etc/passwd)
    if ($realFullPath === FALSE) {
      // File doesn't exist yet, check parent directory
      $parentDir = dirname($fullPath);
      $realParentDir = realpath($parentDir);

      if ($realParentDir !== FALSE && strpos($realParentDir, $realBasePath) !== 0) {
        throw new CRM_Core_Exception("Invalid path: directory traversal detected");
      }
    }
    elseif (strpos($realFullPath, $realBasePath) !== 0) {
      throw new CRM_Core_Exception("Invalid path: directory traversal detected");
    }

    return $fullPath;
  }

  /**
   * Get MIME type based on file extension.
   *
   * Fallback method when mime_content_type() fails.
   *
   * @param string $path File path
   *
   * @return string MIME type
   */
  private function getMimeTypeFromExtension(string $path): string {
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Common MIME types
    $mimeTypes = [
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'txt' => 'text/plain',
      'html' => 'text/html',
      'csv' => 'text/csv',
      'zip' => 'application/zip',
    ];

    return $mimeTypes[$extension] ?? 'application/octet-stream';
  }
}