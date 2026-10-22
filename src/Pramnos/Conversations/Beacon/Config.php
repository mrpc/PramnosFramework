<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

/**
 * The beacon's settings: `app/beacon.php` — the same file on the primary and on the beacon, each
 * with its own `role` — else `'conversations' => ['beacon' => […]]` in app.php. A file of its own,
 * because the beacon's endpoints run without the application — and without its database.
 *
 * ```php
 * // app/beacon.php
 * return [
 *     'role'           => 'primary',                       // or 'beacon'
 *     'secret'         => getenv('BEACON_SECRET'),         // primary ↔ beacon: sync, export
 *     'watcher_secret' => getenv('BEACON_WATCHER_SECRET'), // the outside watcher: health, alert
 *     'signing_key'    => getenv('BEACON_SIGNING_KEY'),    // the primary only: mints emergency keys
 *     'kid'            => '2026-10',
 *     'public_keys'    => ['2026-10' => '…'],              // the beacon: verifies them
 *     'beacon_url'     => 'https://server.example.com',    // where the primary syncs to
 *     'urls'           => ['https://chat.example.com', 'https://server.example.com'], // what a browser tries
 *     'origins'        => ['https://www.example.com', 'https://chat.example.com'],    // who may call the beacon
 *     'redis'          => ['host' => '127.0.0.1', 'prefix' => 'app_'],
 *     'daemon'         => ['host' => '127.0.0.1', 'port' => 6001],   // the primary's health
 *     'settings_file'  => 'app/config/settings.php',        // the database the health checks; else found
 *     'db_check'       => fn (): bool => …,                 // or the application's own check of it
 *     'channel'        => 'έκτακτο',
 *     'drill_url'      => 'https://watcher.example.workers.dev/drill',
 *     'page'           => 'https://www.example.com/admin/Conversations', // where the push takes the team
 *     'words'          => [                                 // the beacon's own lines, in the team's language
 *         'down'  => '⚠ Η εφαρμογή δεν απαντά από τις %s: %s.',
 *         'back'  => '✓ Η εφαρμογή απαντά ξανά από τις %s, διακοπή %s λεπτά.',
 *         'title' => 'Η εφαρμογή δεν απαντά',
 *         'body'  => 'Από τις %s — %s. Άνοιξε το #έκτακτο.',
 *     ],
 * ];
 * ```
 */
final class Config
{
    /** @var array<string, mixed>|null Set by a test, or read once */
    private static ?array $config = null;

    /** @var string|null The settings file, where not `app/beacon.php` under ROOT */
    private static ?string $file = null;

    /** Read the settings from this file — a test's — or, with null, from `app/beacon.php`. */
    public static function readFrom(?string $file): void
    {
        self::$file   = $file;
        self::$config = null;
    }

    /**
     * The settings: a test's, the file's, or app.php's.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $file = self::$file ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()) . '/app/beacon.php';
        if (is_file($file)) {
            $read = include $file;
            if (is_array($read)) {
                return self::$config = $read;
            }
        }
        $app = \Pramnos\Application\Application::currentInstance();

        return self::$config = is_object($app) && is_array($app->applicationInfo['conversations']['beacon'] ?? null)
            ? $app->applicationInfo['conversations']['beacon'] : [];
    }

    /** Use these settings — a test's — or read them again with null. @param array<string, mixed>|null $config */
    public static function use(?array $config): void
    {
        self::$config = $config;
    }

    /** One setting. */
    public static function value(string $key, mixed $default = null): mixed
    {
        return self::get()[$key] ?? $default;
    }

    /** Whether this installation has a beacon at all. */
    public static function enabled(): bool
    {
        return in_array(self::value('role'), ['primary', 'beacon'], true);
    }

    /**
     * Whether this installation is a primary that can hand browsers their emergency keys: a
     * primary, with the beacon's addresses, and a signing key that is one. Without the key the
     * browsers are not told of a beacon at all — they could not reach it with a key of their own.
     */
    public static function ready(): bool
    {
        $key = base64_decode((string) self::value('signing_key', ''), true);

        return self::value('role') === 'primary' && (array) self::value('urls', []) !== []
            && $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
    }

    /** One of the beacon's own lines: the team's wording, else English. */
    public static function words(string $key): string
    {
        $defaults = ['down' => '⚠ Down since %s: %s.', 'back' => '✓ Back since %s, after %s minutes.',
            'title' => 'The application is down', 'body' => 'Since %s — %s. Open the emergency channel.'];

        return (string) (((array) self::value('words', []))[$key] ?? $defaults[$key] ?? $key);
    }

    /**
     * The application's settings file, for the primary's health to reach its database: the one
     * named (`settings_file`, under ROOT unless absolute), else `app/config/settings.php` or
     * `app/settings.php`, whichever is there — null where none is.
     */
    public static function settingsFile(?string $root = null): ?string
    {
        $root  = rtrim($root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()), '/');
        $named = trim((string) self::value('settings_file', ''));
        if ($named !== '') {
            return str_starts_with($named, '/') ? $named : $root . '/' . $named;
        }
        foreach (['app/config/settings.php', 'app/settings.php'] as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                return $root . '/' . $candidate;
            }
        }

        return null;
    }

    /** The emergency channel's name. */
    public static function channel(): string
    {
        return (string) self::value('channel', 'emergency');
    }
}
