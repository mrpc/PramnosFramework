<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers;

use Pramnos\Filesystem\Filesystem;
use Pramnos\Storage\StorageInterface;

/**
 * Local filesystem storage driver.
 *
 * All paths are relative to the configured `root` directory.
 * Directory-level operations delegate to the existing
 * {@see \Pramnos\Filesystem\Filesystem} utility (reuse tested code).
 * File-level operations (put/get/exists/size/mimeType) use PHP functions
 * directly because Filesystem does not provide them.
 *
 * Configuration keys:
 *   - `root` (string, required) — absolute path to the storage root.
 *   - `url`  (string, optional) — public base URL, used by url(). E.g. '/uploads'.
 *
 */
class LocalDriver implements StorageInterface
{
    private string $root;
    private ?string $baseUrl;
    private Filesystem $fs;

    /**
     * @param array{root: string, url?: string} $config
     */
    public function __construct(array $config)
    {
        if (empty($config['root'])) {
            throw new \InvalidArgumentException('LocalDriver requires a "root" config key.');
        }
        $this->root    = rtrim($config['root'], '/\\');
        $this->baseUrl = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
        $this->fs      = Filesystem::getInstance();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Resolve a relative path to an absolute one under the configured root. */
    private function fullPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return $this->root . '/' . $path;
    }

    /** Ensure the parent directory of a file path exists. */
    private function ensureDirectory(string $fullPath): void
    {
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function get(string $path): string
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $contents = file_get_contents($full);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }
        return $contents;
    }

    public function readStream(string $path)
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $stream = fopen($full, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open stream for: {$path}");
        }
        return $stream;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public function put(string $path, $contents, array $options = []): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory($full);

        if (is_resource($contents)) {
            $dest = fopen($full, 'wb');
            if ($dest === false) {
                return false;
            }
            stream_copy_to_stream($contents, $dest);
            fclose($dest);
            return true;
        }

        return file_put_contents($full, $contents) !== false;
    }

    public function prepend(string $path, string $data): bool
    {
        $existing = $this->exists($path) ? $this->get($path) : '';
        return $this->put($path, $data . $existing);
    }

    public function append(string $path, string $data): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory($full);
        return file_put_contents($full, $data, FILE_APPEND) !== false;
    }

    // -------------------------------------------------------------------------
    // Existence & metadata
    // -------------------------------------------------------------------------

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function size(string $path): int
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        return (int) filesize($full);
    }

    public function lastModified(string $path): int
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        return (int) filemtime($full);
    }

    public function mimeType(string $path): string|false
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            return false;
        }
        return mime_content_type($full);
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    public function delete(string|array $paths): bool
    {
        $all = true;
        foreach ((array) $paths as $path) {
            // Delegates to Filesystem::removeFile() — reuses tested logic
            $result = $this->fs->removeFile($this->fullPath($path));
            if (!$result) {
                $all = false;
            }
        }
        return $all;
    }

    public function move(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        if (!is_file($fullFrom)) {
            throw new \RuntimeException("Source file not found: {$from}");
        }
        $this->ensureDirectory($fullTo);
        return rename($fullFrom, $fullTo);
    }

    public function copy(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        if (!is_file($fullFrom)) {
            throw new \RuntimeException("Source file not found: {$from}");
        }
        $this->ensureDirectory($fullTo);
        // Delegates to Filesystem::recurseCopy() for directory sources;
        // for single files, use copy() directly.
        if (is_dir($fullFrom)) {
            return $this->fs->recurseCopy($fullFrom, $fullTo);
        }
        return copy($fullFrom, $fullTo);
    }

    // -------------------------------------------------------------------------
    // Directories
    // -------------------------------------------------------------------------

    public function files(string $directory = ''): array
    {
        $full = $directory === '' ? $this->root : $this->fullPath($directory);
        if (!is_dir($full)) {
            return [];
        }
        $result = [];
        foreach (scandir($full) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_file($full . '/' . $item)) {
                $result[] = ($directory === '' ? '' : $directory . '/') . $item;
            }
        }
        return $result;
    }

    public function allFiles(string $directory = ''): array
    {
        $full = $directory === '' ? $this->root : $this->fullPath($directory);
        if (!is_dir($full)) {
            return [];
        }
        // Delegates to Filesystem::listDirectoryFiles() — reuses tested logic
        $absolute = $this->fs->listDirectoryFiles($full);
        // Convert absolute paths back to relative paths under root
        $rootLen = strlen($this->root) + 1;
        return array_map(fn($p) => substr(str_replace('\\', '/', $p), $rootLen), $absolute);
    }

    public function directories(string $directory = ''): array
    {
        $full = $directory === '' ? $this->root : $this->fullPath($directory);
        if (!is_dir($full)) {
            return [];
        }
        $result = [];
        foreach (scandir($full) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_dir($full . '/' . $item)) {
                $result[] = ($directory === '' ? '' : $directory . '/') . $item;
            }
        }
        return $result;
    }

    public function makeDirectory(string $path): bool
    {
        $full = $this->fullPath($path);
        if (is_dir($full)) {
            return true;
        }
        return mkdir($full, 0755, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $full = $this->fullPath($path);
        if (!is_dir($full)) {
            return false;
        }
        // Delegates to Filesystem::destroyDirectory() — reuses tested logic
        return $this->fs->destroyDirectory($full);
    }

    // -------------------------------------------------------------------------
    // URLs
    // -------------------------------------------------------------------------

    public function url(string $path): string
    {
        if ($this->baseUrl === null) {
            throw new \RuntimeException(
                'LocalDriver: no "url" config key set — cannot generate public URL.'
            );
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        throw new \RuntimeException('LocalDriver does not support temporary URLs.');
    }
}
