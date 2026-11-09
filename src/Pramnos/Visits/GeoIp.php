<?php

declare(strict_types=1);

namespace Pramnos\Visits;

/**
 * The country, region and city of an address, from a MaxMind-format database: DB-IP's City Lite
 * by default (`visits:update` fetches it monthly), or the application's own GeoIP2 file
 * (`'visits' => ['geoip' => ['path' => '/…/GeoIP2-City.mmdb']]`).
 *
 * `maxmind-db/reader` is a suggest: without it, or without the file, there is no place, and the
 * hit is counted all the same. DB-IP's licence (CC BY 4.0) asks for "IP Geolocation by DB-IP"
 * with a link to https://db-ip.com wherever places are shown: {@see ATTRIBUTION}.
 */
final class GeoIp
{
    /** What a screen showing places from DB-IP's database says. */
    public const ATTRIBUTION = '<a href="https://db-ip.com">IP Geolocation by DB-IP</a>';

    /** Where DB-IP keeps a month's City Lite: `%s` is `YYYY-MM`. */
    public const SOURCE = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    /** @var object|null|false The reader: an object with `get(string $ip)`; false for none */
    private static object|false|null $reader = null;

    /** Read with another reader — a test's; null forgets the one opened. */
    public static function useReader(?object $reader): void
    {
        self::$reader = $reader;
    }

    /**
     * Where an address is, or null where it is not known.
     *
     * @return array{country: ?string, region: ?string, city: ?string}|null
     */
    public static function lookup(string $ip): ?array
    {
        $reader = self::reader();
        if ($reader === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        try {
            $r = $reader->get($ip);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }
        $name    = static fn (?array $n): ?string => is_array($n) && isset($n['names']) ? (string) ($n['names']['en'] ?? reset($n['names'])) : null;
        $country = isset($r['country']['iso_code']) ? strtoupper(substr((string) $r['country']['iso_code'], 0, 2)) : null;

        return ['country' => $country, 'region' => self::cut($name($r['subdivisions'][0] ?? null), 64), 'city' => self::cut($name($r['city'] ?? null), 128)];
    }

    /** The database file: `visits.geoip.path`, else the one `visits:update` keeps. */
    public static function path(): string
    {
        $path = Visits::config()['geoip']['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : (defined('ROOT') ? ROOT : getcwd()) . '/var/visits/dbip-city-lite.mmdb';
    }

    /**
     * Fetch this month's City Lite into {@see path()}: written beside it and moved, so a reader
     * never opens half a file. Last month's, when this month's is not out yet.
     *
     * @param (callable(string): ?string)|null $fetch How a URL is read (a test's); its body, or null
     * @return string Which month was taken
     * @throws \RuntimeException When neither month could be fetched
     */
    public static function update(?callable $fetch = null): string
    {
        $fetch ??= static function (string $url): ?string {
            $context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'PramnosFramework visits:update']]);
            $body    = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        };
        foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $month) {
            $gz = $fetch(sprintf(self::SOURCE, $month));
            $db = is_string($gz) && $gz !== '' ? @gzdecode($gz) : false;
            if (is_string($db) && $db !== '') {
                $path = self::path();
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                file_put_contents($path . '.part', $db);
                rename($path . '.part', $path);
                self::$reader = null;

                return $month;
            }
        }

        throw new \RuntimeException('DB-IP\'s City Lite could not be fetched, for this month or the last.');
    }

    /** The reader, opened once; null without the package or the file. */
    private static function reader(): ?object
    {
        if (self::$reader === null) {
            $path = self::path();
            // @codeCoverageIgnoreStart
            self::$reader = class_exists(\MaxMind\Db\Reader::class) && is_file($path) ? new \MaxMind\Db\Reader($path) : false;
            // @codeCoverageIgnoreEnd
        }

        return self::$reader === false ? null : self::$reader;
    }

    /** Cut to a column's length. */
    private static function cut(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
