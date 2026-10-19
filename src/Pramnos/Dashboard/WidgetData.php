<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * The data of the shared dashboard cards ({@see Widget::shared()}), kept in the cache under the
 * card's {@see Widget::dataKey()} — so a slow card is worked out once for everybody, not on every
 * dashboard anybody opens.
 *
 * ```php
 * $entry = WidgetData::read($widget);   // ['data' => [...], 'at' => 1760000000], worked out on a miss
 * WidgetData::refresh($widget);         // worked out now — what dashboard:precompute does
 * ```
 *
 * How long an entry is kept: `'dashboard' => ['data_ttl' => 600]` in app.php, five minutes by
 * default. With `dashboard:precompute` run from cron, keep it longer than the cron's interval, so
 * the card is never worked out while somebody waits.
 */
final class WidgetData
{
    /** The cache category the entries are kept in. */
    public const CATEGORY = 'dashboard';

    /** How long an entry is kept unless app.php says, in seconds. */
    public const TTL = 300;

    /**
     * The card's data and when it was worked out: from the cache, or worked out now and kept.
     *
     * @return array{data: array<string, mixed>, at: int}
     */
    public static function read(Widget $widget): array
    {
        $raw   = self::cache()->load(self::key($widget), null, self::ttl());
        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($entry) && is_array($entry['data'] ?? null) && is_int($entry['at'] ?? null)) {
            return $entry;
        }

        return self::refresh($widget);
    }

    /**
     * Work the card's data out now and keep it.
     *
     * @return array{data: array<string, mixed>, at: int}
     */
    public static function refresh(Widget $widget): array
    {
        $entry = ['data' => $widget->data(), 'at' => time()];
        $cache = self::cache();
        $cache->timeout = self::ttl();
        $cache->save((string) json_encode($entry), self::key($widget));

        return $entry;
    }

    /** How long an entry is kept, in seconds. */
    public static function ttl(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $ttl = is_object($app) ? ($app->applicationInfo['dashboard']['data_ttl'] ?? null) : null;

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : self::TTL;
    }

    /** The cache key of a card's data. */
    private static function key(Widget $widget): string
    {
        return 'widget.' . $widget->dataKey();
    }

    /** A store of a test's own in place of the cache's 'dashboard' category; null for that again. */
    public static function useCache(?\Pramnos\Cache\Cache $cache): void
    {
        self::$cache = $cache;
    }

    private static ?\Pramnos\Cache\Cache $cache = null;

    private static function cache(): \Pramnos\Cache\Cache
    {
        return self::$cache ?? \Pramnos\Cache\Cache::getInstance(self::CATEGORY);
    }
}
