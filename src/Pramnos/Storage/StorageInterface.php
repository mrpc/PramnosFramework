<?php

declare(strict_types=1);

namespace Pramnos\Storage;

/**
 * Uniform contract for all storage drivers.
 *
 * Every method operates on **relative paths** — the driver is responsible for
 * prepending the root/bucket prefix. Paths use forward slashes regardless of OS.
 *
 */
interface StorageInterface
{
    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Read the entire contents of a file.
     *
     * @param  string $path  Relative path, e.g. 'uploads/photo.jpg'.
     * @return string        File contents.
     * @throws \RuntimeException  When the file cannot be read.
     */
    public function get(string $path): string;

    /**
     * Return a readable stream resource for the given path.
     *
     * @param  string   $path
     * @return resource
     * @throws \RuntimeException
     */
    public function readStream(string $path);

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Write string contents or a stream to a path, creating directories as needed.
     *
     * @param  string          $path
     * @param  string|resource $contents
     * @param  array           $options   Driver-specific options (e.g. ACL for S3).
     * @return bool
     */
    public function put(string $path, $contents, array $options = []): bool;

    /**
     * Prepend content to the beginning of an existing file.
     *
     * @param  string $path
     * @param  string $data
     * @return bool
     */
    public function prepend(string $path, string $data): bool;

    /**
     * Append content to the end of a file (creates the file if absent).
     *
     * @param  string $path
     * @param  string $data
     * @return bool
     */
    public function append(string $path, string $data): bool;

    // -------------------------------------------------------------------------
    // Existence & metadata
    // -------------------------------------------------------------------------

    /**
     * Determine whether a file exists at the given path.
     */
    public function exists(string $path): bool;

    /**
     * Determine whether a file does NOT exist at the given path.
     */
    public function missing(string $path): bool;

    /**
     * Return the file size in bytes.
     *
     * @throws \RuntimeException  When the file does not exist.
     */
    public function size(string $path): int;

    /**
     * Return the last-modified time as a Unix timestamp.
     *
     * @throws \RuntimeException  When the file does not exist.
     */
    public function lastModified(string $path): int;

    /**
     * Detect and return the MIME type of the file.
     *
     * @return string|false  MIME type string, or false on failure.
     */
    public function mimeType(string $path): string|false;

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /**
     * Delete one or more files.
     *
     * @param  string|string[] $paths
     * @return bool  True if all deletions succeeded.
     */
    public function delete(string|array $paths): bool;

    /**
     * Move a file from one path to another (rename).
     *
     * @throws \RuntimeException
     */
    public function move(string $from, string $to): bool;

    /**
     * Copy a file.
     *
     * @throws \RuntimeException
     */
    public function copy(string $from, string $to): bool;

    // -------------------------------------------------------------------------
    // Directories
    // -------------------------------------------------------------------------

    /**
     * Return all file paths directly inside the given directory (non-recursive).
     *
     * @return string[]
     */
    public function files(string $directory = ''): array;

    /**
     * Return all file paths inside the given directory, recursively.
     *
     * @return string[]
     */
    public function allFiles(string $directory = ''): array;

    /**
     * Return all sub-directory names directly inside the given directory.
     *
     * @return string[]
     */
    public function directories(string $directory = ''): array;

    /**
     * Create a directory (and any parents).
     */
    public function makeDirectory(string $path): bool;

    /**
     * Recursively delete a directory and all its contents.
     */
    public function deleteDirectory(string $path): bool;

    // -------------------------------------------------------------------------
    // URLs
    // -------------------------------------------------------------------------

    /**
     * Return a public URL to the file.
     * For drivers that do not support public URLs, throw \RuntimeException.
     *
     * @throws \RuntimeException
     */
    public function url(string $path): string;

    /**
     * Return a pre-signed / temporary URL valid until $expiration.
     * Drivers that do not support temporary URLs throw \RuntimeException.
     *
     * @throws \RuntimeException
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string;
}
