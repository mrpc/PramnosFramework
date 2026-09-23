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
     * Return the underlying StorageManager, building it from settings if nobody has.
     *
     * ## Why this no longer throws
     *
     * It used to require `Storage::init()` during bootstrap, and **nothing in the framework
     * called it** — so the whole subsystem was unreachable unless an application knew it
     * existed and wired it up. Three drivers, a façade, and no way in.
     *
     * Now the `storage` block of `app/config/app.php` is read on first use:
     *
     * ```php
     * 'storage' => [
     *     'default' => 'media',
     *     'disks'   => [
     *         'media' => ['driver' => 's3', 'bucket' => …, 'url' => 'https://cdn.example.com'],
     *     ],
     * ],
     * ```
     *
     * **An application that configures nothing gets one `local` disk rooted at `www/`**,
     * which is where uploads already are — so the façade works out of the box and changes
     * nothing about where files land. That is the whole of "optional": the subsystem is
     * available without being imposed, and an installation that never mentions storage
     * behaves exactly as it did.
     *
     * `init()` still works and still wins, for an application that would rather configure
     * in code.
     */
    public static function getManager(): StorageManager
    {
        return self::$manager ??= new StorageManager(self::configuredDisks());
    }

    /**
     * Forget the manager, so the next call rebuilds it from settings.
     *
     * For tests, and for a long-running worker whose configuration was reloaded.
     */
    public static function reset(): void
    {
        self::$manager = null;
    }

    /**
     * The `storage` block, with a local disk when there is none.
     *
     * `Settings::getSetting()` hands back a **`stdClass`** for an array setting, so the
     * nested structure is cast back on the way in. Reading it with `is_array()` is how a
     * configured block silently reads as no configuration — which this framework has now
     * done twice in one day, once in this very file's neighbour.
     *
     * @return array{default: string, disks: array<string, array<string, mixed>>}
     */
    private static function configuredDisks(): array
    {
        $configured = self::toArray(
            \Pramnos\Application\Settings::getSetting('storage')
        );

        $disks = self::toArray($configured['disks'] ?? []);
        foreach ($disks as $name => $disk) {
            $disks[$name] = self::toArray($disk);
        }

        if ($disks === []) {
            /*
             * The default nobody has to write.
             *
             * Rooted at `www/` rather than `www/uploads/`, because the paths the media
             * system stores are already relative to it — `uploads/2026/09/x.png` — and
             * re-rooting would mean rewriting every row in `media`.
             */
            $root  = defined('ROOT') ? \ROOT . '/www' : getcwd() . '/www';
            $disks = ['local' => ['driver' => 'local', 'root' => $root, 'url' => '']];

            return ['default' => 'local', 'disks' => $disks];
        }

        return [
            'default' => (string) ($configured['default'] ?? array_key_first($disks)),
            'disks'   => $disks,
        ];
    }

    /**
     * Whatever a setting came back as, as an array.
     *
     * @param mixed $value
     * @return array<string|int, mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (is_object($value)) {
            return (array) $value;
        }

        return is_array($value) ? $value : [];
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
