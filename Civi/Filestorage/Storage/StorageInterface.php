<?php

namespace Civi\Filestorage\Storage;

/**
 * Interface for file storage adapters.
 *
 * This interface defines the contract that all storage backend adapters must implement.
 * It provides a consistent API for file operations across different storage providers
 * (local filesystem, S3, Google Cloud Storage, Azure, etc.).
 *
 * @package Civi\Filestorage\Storage
 */
interface StorageInterface {

  /**
   * Write content to a file in the storage backend.
   *
   * This method handles uploading or writing file content to the storage provider.
   * For remote storage (S3, GCS), this performs an upload. For local storage,
   * it writes to the filesystem.
   *
   * @param string $path The destination path/key where the file should be stored
   * @param string|resource $contents The file content (string or stream resource)
   * @param array $config Optional configuration (e.g., ACL, content-type, metadata)
   *   - 'visibility' => 'public'|'private' - File access level
   *   - 'mime_type' => string - Content type
   *   - 'metadata' => array - Additional provider-specific metadata
   *
   * @return bool TRUE on success, FALSE on failure
   *
   * @throws \Exception If write operation fails
   */
  public function write(string $path, $contents, array $config = []): bool;

  /**
   * Read file content from the storage backend.
   *
   * Retrieves the complete file content as a string. For large files,
   * consider using readStream() instead to avoid memory issues.
   *
   * @param string $path The path/key of the file to read
   *
   * @return string The file content
   *
   * @throws \Exception If file doesn't exist or read fails
   */
  public function read(string $path): string;

  /**
   * Read file content as a stream resource.
   *
   * Returns a PHP stream resource for memory-efficient reading of large files.
   * This is preferred over read() for files larger than a few MB.
   *
   * @param string $path The path/key of the file to read
   *
   * @return resource A stream resource
   *
   * @throws \Exception If file doesn't exist or stream creation fails
   */
  public function readStream(string $path);

  /**
   * Delete a file from the storage backend.
   *
   * Permanently removes the file. This operation cannot be undone.
   *
   * @param string $path The path/key of the file to delete
   *
   * @return bool TRUE on success, FALSE if file doesn't exist or deletion fails
   *
   * @throws \Exception If deletion fails for reasons other than file not existing
   */
  public function delete(string $path): bool;

  /**
   * Check if a file exists in the storage backend.
   *
   * @param string $path The path/key to check
   *
   * @return bool TRUE if file exists, FALSE otherwise
   */
  public function exists(string $path): bool;

  /**
   * Copy a file from one location to another within the same storage.
   *
   * @param string $from Source path/key
   * @param string $to Destination path/key
   *
   * @return bool TRUE on success, FALSE on failure
   *
   * @throws \Exception If copy operation fails
   */
  public function copy(string $from, string $to): bool;

  /**
   * Move/rename a file within the storage backend.
   *
   * @param string $from Source path/key
   * @param string $to Destination path/key
   *
   * @return bool TRUE on success, FALSE on failure
   *
   * @throws \Exception If move operation fails
   */
  public function move(string $from, string $to): bool;

  /**
   * Get a public or signed URL for accessing the file.
   *
   * For public files, returns a direct URL. For private files, generates
   * a time-limited signed URL that grants temporary access.
   *
   * @param string $path The path/key of the file
   * @param int $ttl Time-to-live in seconds for signed URLs (default: 3600 = 1 hour)
   *   - Set to 0 for permanent URLs (public files only)
   *   - For private files, URL will expire after $ttl seconds
   *
   * @return string The URL to access the file
   *
   * @throws \Exception If URL generation fails
   */
  public function getUrl(string $path, int $ttl = 3600): string;

  /**
   * Get file metadata (size, mime type, last modified, etc.).
   *
   * @param string $path The path/key of the file
   *
   * @return array File metadata with keys:
   *   - 'size' => int - File size in bytes
   *   - 'mime_type' => string - MIME type
   *   - 'last_modified' => int - Unix timestamp
   *   - 'visibility' => string - 'public' or 'private'
   *   - Additional provider-specific metadata
   *
   * @throws \Exception If file doesn't exist or metadata retrieval fails
   */
  public function getMetadata(string $path): array;

  /**
   * Get the file size in bytes.
   *
   * @param string $path The path/key of the file
   *
   * @return int File size in bytes
   *
   * @throws \Exception If file doesn't exist
   */
  public function getSize(string $path): int;

  /**
   * Get the MIME type of the file.
   *
   * @param string $path The path/key of the file
   *
   * @return string MIME type (e.g., 'application/pdf', 'image/jpeg')
   *
   * @throws \Exception If file doesn't exist or MIME type detection fails
   */
  public function getMimeType(string $path): string;

  /**
   * List files in a directory/prefix.
   *
   * For hierarchical storage (local FS), lists files in a directory.
   * For flat storage (S3), lists files matching a prefix.
   *
   * @param string $directory The directory path or prefix
   * @param bool $recursive Whether to list recursively (default: false)
   *
   * @return array Array of file paths/keys
   *
   * @throws \Exception If listing fails
   */
  public function listContents(string $directory = '', bool $recursive = FALSE): array;

  /**
   * Test the connection to the storage backend.
   *
   * Verifies that the storage is accessible with the current configuration.
   * Useful for validating credentials and permissions.
   *
   * @return bool TRUE if connection successful, FALSE otherwise
   */
  public function testConnection(): bool;

  /**
   * Get the storage type identifier.
   *
   * @return string Storage type: 'local', 's3', 'gcs', 'azure', 'spaces'
   */
  public function getType(): string;

  /**
   * Get storage configuration information (sanitized, no credentials).
   *
   * Returns configuration details safe for logging/display, with sensitive
   * information (credentials, secrets) removed or masked.
   *
   * @return array Configuration array with sensitive data removed
   */
  public function getConfig(): array;
}