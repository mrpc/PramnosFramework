<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers;

use Pramnos\Storage\StorageInterface;

/**
 * FTP storage driver.
 *
 * Requires PHP's `ext-ftp` extension.
 *
 * Configuration keys:
 *   - `host`     (string)  — FTP server hostname.
 *   - `username` (string)  — FTP username.
 *   - `password` (string)  — FTP password.
 *   - `root`     (string)  — Remote base path, e.g. '/public_html/uploads'.
 *   - `port`     (int, optional, default 21)
 *   - `passive`  (bool, optional, default true) — passive mode.
 *   - `ssl`      (bool, optional, default false) — use FTP over SSL (FTPS).
 *   - `timeout`  (int, optional, default 30) — connection timeout in seconds.
 *   - `url`      (string, optional) — public base URL for url().
 *
 */
class FtpDriver implements StorageInterface
{
    private string $root;
    private ?string $baseUrl;

    /** @var resource|null */
    private $connection = null;

    public function __construct(private array $config)
    {
        if (!extension_loaded('ftp')) {
            throw new \RuntimeException(
                'FtpDriver requires the PHP ftp extension. Enable it with: extension=ftp'
            );
        }
        if (empty($config['host']) || empty($config['username'])) {
            throw new \InvalidArgumentException('FtpDriver requires "host" and "username" config keys.');
        }
        $this->root    = rtrim($config['root'] ?? '', '/');
        $this->baseUrl = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
    }

    // -------------------------------------------------------------------------
    // Connection management
    // -------------------------------------------------------------------------

    /** @return resource */
    private function connection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $host    = $this->config['host'];
        $port    = (int) ($this->config['port']    ?? 21);
        $timeout = (int) ($this->config['timeout'] ?? 30);
        $ssl     = (bool) ($this->config['ssl']    ?? false);

        $conn = $ssl
            ? ftp_ssl_connect($host, $port, $timeout)
            : ftp_connect($host, $port, $timeout);

        if ($conn === false) {
            throw new \RuntimeException("FTP connection failed to {$host}:{$port}");
        }

        if (!ftp_login($conn, $this->config['username'], $this->config['password'] ?? '')) {
            ftp_close($conn);
            throw new \RuntimeException("FTP login failed for user {$this->config['username']}");
        }

        $passive = (bool) ($this->config['passive'] ?? true);
        ftp_pasv($conn, $passive);

        $this->connection = $conn;
        return $this->connection;
    }

    public function __destruct()
    {
        if ($this->connection !== null) {
            ftp_close($this->connection);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fullPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return $this->root === '' ? $path : $this->root . '/' . $path;
    }

    private function ensureRemoteDirectory(string $remotePath): void
    {
        $conn = $this->connection();
        $dir  = dirname($remotePath);
        if ($dir === '.' || $dir === '') {
            return;
        }
        // Try to create each segment
        $segments = explode('/', ltrim($dir, '/'));
        $current  = '';
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (@ftp_chdir($conn, $current) === false) {
                ftp_mkdir($conn, $current);
            }
        }
        // Return to root
        ftp_chdir($conn, '/');
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function get(string $path): string
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file for FTP download.');
        }
        $meta = stream_get_meta_data($tmp);
        if (!ftp_get($this->connection(), $meta['uri'], $this->fullPath($path), FTP_BINARY)) {
            fclose($tmp);
            throw new \RuntimeException("FTP get failed: {$path}");
        }
        rewind($tmp);
        $contents = stream_get_contents($tmp);
        fclose($tmp);
        return $contents === false ? '' : $contents;
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create temp stream for FTP download.');
        }
        if (!ftp_fget($this->connection(), $stream, $this->fullPath($path), FTP_BINARY)) {
            fclose($stream);
            throw new \RuntimeException("FTP readStream failed: {$path}");
        }
        rewind($stream);
        return $stream;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public function put(string $path, $contents, array $options = []): bool
    {
        $full = $this->fullPath($path);
        $this->ensureRemoteDirectory($full);

        if (is_resource($contents)) {
            return ftp_fput($this->connection(), $full, $contents, FTP_BINARY);
        }

        $tmp = tmpfile();
        if ($tmp === false) {
            return false;
        }
        fwrite($tmp, $contents);
        rewind($tmp);
        $result = ftp_fput($this->connection(), $full, $tmp, FTP_BINARY);
        fclose($tmp);
        return $result;
    }

    public function prepend(string $path, string $data): bool
    {
        $existing = $this->exists($path) ? $this->get($path) : '';
        return $this->put($path, $data . $existing);
    }

    public function append(string $path, string $data): bool
    {
        $existing = $this->exists($path) ? $this->get($path) : '';
        return $this->put($path, $existing . $data);
    }

    // -------------------------------------------------------------------------
    // Existence & metadata
    // -------------------------------------------------------------------------

    public function exists(string $path): bool
    {
        return ftp_size($this->connection(), $this->fullPath($path)) >= 0;
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function size(string $path): int
    {
        $size = ftp_size($this->connection(), $this->fullPath($path));
        if ($size < 0) {
            throw new \RuntimeException("FTP size failed (file not found?): {$path}");
        }
        return $size;
    }

    public function lastModified(string $path): int
    {
        $mtime = ftp_mdtm($this->connection(), $this->fullPath($path));
        if ($mtime < 0) {
            throw new \RuntimeException("FTP lastModified failed: {$path}");
        }
        return $mtime;
    }

    public function mimeType(string $path): string|false
    {
        // FTP protocol does not expose MIME types natively — derive from extension
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map  = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif',  'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'txt' => 'text/plain', 'html' => 'text/html', 'css' => 'text/css',
            'js'  => 'application/javascript', 'json' => 'application/json',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    public function delete(string|array $paths): bool
    {
        $all = true;
        foreach ((array) $paths as $path) {
            if (!ftp_delete($this->connection(), $this->fullPath($path))) {
                $all = false;
            }
        }
        return $all;
    }

    public function move(string $from, string $to): bool
    {
        $this->ensureRemoteDirectory($this->fullPath($to));
        return ftp_rename($this->connection(), $this->fullPath($from), $this->fullPath($to));
    }

    public function copy(string $from, string $to): bool
    {
        // FTP has no native copy — download then re-upload
        $contents = $this->get($from);
        return $this->put($to, $contents);
    }

    // -------------------------------------------------------------------------
    // Directories
    // -------------------------------------------------------------------------

    public function files(string $directory = ''): array
    {
        $full = $directory === '' ? $this->root : $this->fullPath($directory);
        $list = ftp_nlist($this->connection(), $full);
        if ($list === false) {
            return [];
        }
        return array_filter($list, fn($item) => ftp_size($this->connection(), $item) >= 0);
    }

    public function allFiles(string $directory = ''): array
    {
        $full = $directory === '' ? ($this->root ?: '.') : $this->fullPath($directory);
        return $this->collectFiles($full);
    }

    private function collectFiles(string $remote): array
    {
        $conn  = $this->connection();
        $list  = ftp_nlist($conn, $remote) ?: [];
        $files = [];
        foreach ($list as $item) {
            if (ftp_size($conn, $item) >= 0) {
                $files[] = $item;
            } else {
                $files = array_merge($files, $this->collectFiles($item));
            }
        }
        return $files;
    }

    public function directories(string $directory = ''): array
    {
        $full = $directory === '' ? ($this->root ?: '.') : $this->fullPath($directory);
        $conn = $this->connection();
        $list = ftp_nlist($conn, $full) ?: [];
        return array_filter($list, fn($item) => ftp_size($conn, $item) < 0);
    }

    public function makeDirectory(string $path): bool
    {
        $result = ftp_mkdir($this->connection(), $this->fullPath($path));
        return $result !== false;
    }

    public function deleteDirectory(string $path): bool
    {
        $files = $this->allFiles($path);
        foreach ($files as $file) {
            ftp_delete($this->connection(), $file);
        }
        return ftp_rmdir($this->connection(), $this->fullPath($path));
    }

    // -------------------------------------------------------------------------
    // URLs
    // -------------------------------------------------------------------------

    public function url(string $path): string
    {
        if ($this->baseUrl === null) {
            throw new \RuntimeException(
                'FtpDriver: no "url" config key set — cannot generate public URL.'
            );
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        throw new \RuntimeException('FtpDriver does not support temporary URLs.');
    }
}
