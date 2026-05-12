<?php

declare(strict_types=1);

namespace Pramnos\Storage;

/**
 * Static façade for the StorageManager.
 *
 * All calls proxy to the underlying {@see StorageManager} singleton.
 * Use `Storage::disk('name')` to target a specific disk; calls without
 * `disk()` target the configured default disk.
 *
 * ## Setup
 *
 * Bootstrap once (e.g. in your ServiceProvider or application bootstrap):
 *
 * ```php
 * Storage::init([
 *     'default' => 'local',
 *     'disks'   => [
 *         'local'  => ['driver' => 'local', 'root' => ROOT . '/storage'],
 *         'public' => ['driver' => 'local', 'root' => ROOT . '/www/uploads', 'url' => '/uploads'],
 *         's3'     => ['driver' => 's3', 'key' => env('AWS_KEY'), 'secret' => env('AWS_SECRET'),
 *                      'bucket' => env('AWS_BUCKET'), 'region' => env('AWS_REGION')],
 *     ],
 * ]);
 * ```
 *
 * ## Usage
 *
 * ```php
 * // Default disk
 * Storage::put('invoices/2026-001.pdf', $pdfContent);
 * $pdf   = Storage::get('invoices/2026-001.pdf');
 * $url   = Storage::disk('public')->url('avatars/alice.jpg');
 * $exists = Storage::exists('reports/annual.xlsx');
 * Storage::delete('tmp/upload_12345.tmp');
 *
 * // Named disk
 * Storage::disk('s3')->put('backups/db.sql.gz', $stream);
 * $signedUrl = Storage::disk('s3')->temporaryUrl('reports/q1.pdf', new DateTime('+1 hour'));
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Storage
 */
class Storage
{
    private static ?StorageManager $manager = null;

    /**
     * Bootstrap the Storage façade with configuration.
     * Must be called once before any other Storage method.
     */
    public static function init(array $config): void
    {
        self::$manager = new StorageManager($config);
    }

    /**
     * Replace the underlying manager (useful for testing).
     */
    public static function setManager(StorageManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Return the underlying StorageManager.
     */
    public static function getManager(): StorageManager
    {
        if (self::$manager === null) {
            throw new \RuntimeException(
                'Storage has not been initialised. Call Storage::init($config) during bootstrap.'
            );
        }
        return self::$manager;
    }

    /**
     * Select a named disk to operate on.
     */
    public static function disk(string $name): StorageInterface
    {
        return self::getManager()->disk($name);
    }

    // -------------------------------------------------------------------------
    // Default-disk proxies — mirrors every StorageInterface method
    // -------------------------------------------------------------------------

    public static function get(string $path): string
    {
        return self::getManager()->get($path);
    }

    /** @return resource */
    public static function readStream(string $path)
    {
        return self::getManager()->readStream($path);
    }

    /** @param string|resource $contents */
    public static function put(string $path, $contents, array $options = []): bool
    {
        return self::getManager()->put($path, $contents, $options);
    }

    public static function prepend(string $path, string $data): bool
    {
        return self::getManager()->prepend($path, $data);
    }

    public static function append(string $path, string $data): bool
    {
        return self::getManager()->append($path, $data);
    }

    public static function exists(string $path): bool
    {
        return self::getManager()->exists($path);
    }

    public static function missing(string $path): bool
    {
        return self::getManager()->missing($path);
    }

    public static function size(string $path): int
    {
        return self::getManager()->size($path);
    }

    public static function lastModified(string $path): int
    {
        return self::getManager()->lastModified($path);
    }

    public static function mimeType(string $path): string|false
    {
        return self::getManager()->mimeType($path);
    }

    /** @param string|string[] $paths */
    public static function delete(string|array $paths): bool
    {
        return self::getManager()->delete($paths);
    }

    public static function move(string $from, string $to): bool
    {
        return self::getManager()->move($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        return self::getManager()->copy($from, $to);
    }

    /** @return string[] */
    public static function files(string $directory = ''): array
    {
        return self::getManager()->files($directory);
    }

    /** @return string[] */
    public static function allFiles(string $directory = ''): array
    {
        return self::getManager()->allFiles($directory);
    }

    /** @return string[] */
    public static function directories(string $directory = ''): array
    {
        return self::getManager()->directories($directory);
    }

    public static function makeDirectory(string $path): bool
    {
        return self::getManager()->makeDirectory($path);
    }

    public static function deleteDirectory(string $path): bool
    {
        return self::getManager()->deleteDirectory($path);
    }

    public static function url(string $path): string
    {
        return self::getManager()->url($path);
    }

    public static function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        return self::getManager()->temporaryUrl($path, $expiration, $options);
    }
}
