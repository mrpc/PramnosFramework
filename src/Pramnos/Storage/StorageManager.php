<?php

declare(strict_types=1);

namespace Pramnos\Storage;

use Pramnos\Storage\Drivers\FtpDriver;
use Pramnos\Storage\Drivers\LocalDriver;
use Pramnos\Storage\Drivers\S3Driver;

/**
 * Factory and registry for named storage disks.
 *
 * Reads disk configuration from an array (typically from `app.php`):
 *
 * ```php
 * 'storage' => [
 *     'default' => 'local',
 *     'disks'   => [
 *         'local'  => ['driver' => 'local',  'root' => __DIR__ . '/storage/app'],
 *         'public' => ['driver' => 'local',  'root' => __DIR__ . '/www/uploads', 'url' => '/uploads'],
 *         's3'     => ['driver' => 's3',     'key' => '...', 'secret' => '...', 'bucket' => '...', 'region' => 'eu-west-1'],
 *         'ftp'    => ['driver' => 'ftp',    'host' => '...', 'username' => '...', 'password' => '...', 'root' => '/public_html/uploads'],
 *     ],
 * ],
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Storage
 */
class StorageManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];

    private string $defaultDisk;

    /** @var array<string, array> */
    private array $config;

    /**
     * @param array{default?: string, disks?: array<string, array>} $config
     */
    public function __construct(array $config = [])
    {
        $this->config      = $config['disks']   ?? [];
        $this->defaultDisk = $config['default'] ?? 'local';
    }

    // -------------------------------------------------------------------------
    // Disk resolution
    // -------------------------------------------------------------------------

    /**
     * Return a named disk driver, creating it lazily on first access.
     *
     * @throws \InvalidArgumentException  When the disk name is not configured.
     * @throws \RuntimeException          When the driver type is unknown.
     */
    public function disk(string $name): StorageInterface
    {
        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->createDriver($name);
        }
        return $this->disks[$name];
    }

    /**
     * Return the default disk driver.
     */
    public function defaultDisk(): StorageInterface
    {
        return $this->disk($this->defaultDisk);
    }

    /**
     * Register a pre-built driver instance under a name.
     * Useful for testing (inject a mock) or for registering custom drivers.
     */
    public function extend(string $name, StorageInterface $driver): void
    {
        $this->disks[$name] = $driver;
    }

    // -------------------------------------------------------------------------
    // Driver factory
    // -------------------------------------------------------------------------

    private function createDriver(string $name): StorageInterface
    {
        if (!isset($this->config[$name])) {
            throw new \InvalidArgumentException(
                "Storage disk [{$name}] is not configured. "
                . "Add it to the 'storage.disks' section of app.php."
            );
        }

        $config = $this->config[$name];
        $driver = strtolower($config['driver'] ?? '');

        return match ($driver) {
            'local' => new LocalDriver($config),
            's3'    => new S3Driver($config),
            'ftp'   => new FtpDriver($config),
            default => throw new \RuntimeException(
                "Unknown storage driver [{$driver}] for disk [{$name}]."
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Convenience: proxy all StorageInterface calls to the default disk
    // -------------------------------------------------------------------------

    public function get(string $path): string                                       { return $this->defaultDisk()->get($path); }
    public function readStream(string $path)                                        { return $this->defaultDisk()->readStream($path); }
    public function put(string $path, $contents, array $options = []): bool        { return $this->defaultDisk()->put($path, $contents, $options); }
    public function prepend(string $path, string $data): bool                      { return $this->defaultDisk()->prepend($path, $data); }
    public function append(string $path, string $data): bool                       { return $this->defaultDisk()->append($path, $data); }
    public function exists(string $path): bool                                     { return $this->defaultDisk()->exists($path); }
    public function missing(string $path): bool                                    { return $this->defaultDisk()->missing($path); }
    public function size(string $path): int                                        { return $this->defaultDisk()->size($path); }
    public function lastModified(string $path): int                                { return $this->defaultDisk()->lastModified($path); }
    public function mimeType(string $path): string|false                           { return $this->defaultDisk()->mimeType($path); }
    public function delete(string|array $paths): bool                              { return $this->defaultDisk()->delete($paths); }
    public function move(string $from, string $to): bool                           { return $this->defaultDisk()->move($from, $to); }
    public function copy(string $from, string $to): bool                           { return $this->defaultDisk()->copy($from, $to); }
    public function files(string $directory = ''): array                           { return $this->defaultDisk()->files($directory); }
    public function allFiles(string $directory = ''): array                        { return $this->defaultDisk()->allFiles($directory); }
    public function directories(string $directory = ''): array                     { return $this->defaultDisk()->directories($directory); }
    public function makeDirectory(string $path): bool                              { return $this->defaultDisk()->makeDirectory($path); }
    public function deleteDirectory(string $path): bool                            { return $this->defaultDisk()->deleteDirectory($path); }
    public function url(string $path): string                                      { return $this->defaultDisk()->url($path); }
    public function temporaryUrl(string $path, \DateTimeInterface $exp, array $o = []): string { return $this->defaultDisk()->temporaryUrl($path, $exp, $o); }
}
