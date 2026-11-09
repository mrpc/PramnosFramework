<?php

declare(strict_types=1);

namespace Pramnos\Visits;

/**
 * The application's own traffic: its sites, as `visits` in app.php declares them, and what a page
 * prints to count itself.
 *
 * ```php
 * // app/app.php
 * 'visits' => [
 *     'sites'     => ['main' => ['hosts' => ['example.com'], 'visitor' => 'cookie', 'idle' => 1800]],
 *     'dimension' => fn (string $path, array $hit): ?string => explode('/', trim($path, '/'))[0] ?: 'gr',
 *     'sources'   => ['search' => ['example-search.com']],
 *     'geoip'     => ['path' => '/var/lib/GeoIP/GeoIP2-City.mmdb'],
 *     'spam'      => ['bad-referrer.example'],
 * ],
 * ```
 *
 * ```php
 * <?php echo \Pramnos\Visits\Visits::tag(); ?>     // before </body>, on every page — the page cache's too
 * ```
 */
final class Visits
{
    /** The visitor models. */
    public const VISITORS = ['cookie', 'cookieless'];

    /** The cookie a visitor is known by, and the one their visit is. */
    public const VISITOR_COOKIE = 'pf_vid';
    public const VISIT_COOKIE   = 'pf_visit';

    /** `'visits' => [...]` in app.php. @return array<string, mixed> */
    public static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['visits'] ?? []) : [];

        return is_array($config) ? $config : [];
    }

    /**
     * The sites declared, each made whole: `hosts`, `visitor`, `idle`.
     *
     * @return array<string, array{hosts: list<string>, visitor: string, idle: int}>
     */
    public static function sites(): array
    {
        $sites = [];
        foreach ((array) (self::config()['sites'] ?? []) as $name => $site) {
            if (!is_string($name) || !is_array($site)) {
                continue;
            }
            $sites[$name] = [
                'hosts'   => array_values(array_map(static fn ($h): string => strtolower((string) $h), (array) ($site['hosts'] ?? []))),
                'visitor' => in_array($site['visitor'] ?? 'cookie', self::VISITORS, true) ? (string) ($site['visitor'] ?? 'cookie') : 'cookie',
                'idle'    => max(60, (int) ($site['idle'] ?? 1800)),
            ];
        }

        return $sites;
    }

    /**
     * The site a host is one of — the host itself, `www.` or a subdomain of one of its hosts — with
     * its id in `pramnos.sites`, made the first time; null for a host of none.
     *
     * @return array{id: int, name: string, hosts: list<string>, visitor: string, idle: int}|null
     */
    public static function siteFor(string $host): ?array
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        foreach (self::sites() as $name => $site) {
            foreach ($site['hosts'] as $own) {
                $own = preg_replace('/^www\./', '', $own) ?? $own;
                if ($host !== '' && ($host === $own || str_ends_with($host, '.' . $own))) {
                    return ['id' => self::siteId($name, $site), 'name' => $name] + $site;
                }
            }
        }

        return null;
    }

    /** Every host of every site. @return list<string> */
    public static function hosts(): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(static fn (array $s): array => $s['hosts'], self::sites())) ?: [[]])));
    }

    /**
     * The tags a page needs to count itself, for the theme to print before `</body>`: where to
     * send, and the script. Nothing without a site, so a theme can print it always.
     */
    public static function tag(): string
    {
        if (self::sites() === []) {
            return '';
        }
        $site = defined('sURL') ? (string) \sURL : '/';
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<meta name="pf-hits" content="' . $e($site . 'Hits') . '">' . "\n"
            . '<script src="' . $e(assetUrl('assets/js/pf-hits.js')) . '" defer></script>' . "\n"
            . '<noscript><img src="' . $e($site . 'Hits/pixel') . '" alt="" width="1" height="1" style="position:absolute;left:-9999px"></noscript>' . "\n";
    }

    /** The application's own value for a hit — the market, say — from `visits.dimension`. @param array<string, mixed> $hit */
    public static function dimension(string $path, array $hit): ?string
    {
        $make = self::config()['dimension'] ?? null;
        if (!is_callable($make)) {
            return null;
        }
        $value = $make($path, $hit);

        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 20) : null;
    }

    /** Whether a referrer's host is on the spam list: the file `visits:update` keeps, and `visits.spam`. */
    public static function spam(string $host): bool
    {
        static $listed = null;
        if ($listed === null) {
            $file   = (defined('ROOT') ? ROOT : getcwd()) . '/var/visits/referrer-spam.txt';
            $lines  = is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $listed = array_flip(array_map('strtolower', array_map('trim', $lines)));
        }
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        foreach (array_merge([$host], self::parents($host)) as $name) {
            if (isset($listed[$name]) || in_array($name, array_map('strtolower', (array) (self::config()['spam'] ?? [])), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Today's salt for the cookieless visitor: replaced at midnight UTC, kept in the settings, so
     * yesterday's hash cannot be made again.
     */
    public static function salt(): string
    {
        $today = gmdate('Y-m-d');
        $kept  = (string) \Pramnos\Application\Settings::getSetting('visits_salt', '');
        if (str_starts_with($kept, $today . ':')) {
            return substr($kept, 11);
        }
        $salt = bin2hex(random_bytes(16));
        \Pramnos\Application\Settings::setSetting('visits_salt', $today . ':' . $salt);

        return $salt;
    }

    /** A site's id, its row made — and its hosts brought up to date — the first time in a process. @param array<string, mixed> $site */
    private static function siteId(string $name, array $site): int
    {
        static $ids = null;
        $ids ??= new \WeakMap();
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (isset($ids[$db][$name])) {
            return $ids[$db][$name];
        }
        $row = ['hosts' => (string) json_encode($site['hosts']), 'visitor' => $site['visitor'], 'idle' => $site['idle']];
        $q   = static fn () => $db->queryBuilder()->table('pramnos.sites');
        $id  = $q()->where('name', $name)->value('siteid');
        if ($id === null) {
            $q()->insertOrIgnore($row + ['name' => $name]);
            $id = $q()->where('name', $name)->value('siteid');
        } else {
            $q()->where('siteid', (int) $id)->update($row);
        }

        $known        = $ids[$db] ?? [];
        $known[$name] = (int) $id;
        $ids[$db]     = $known;

        return (int) $id;
    }

    /** `a.b.c` → `b.c`. @return list<string> */
    private static function parents(string $host): array
    {
        $parts = explode('.', $host);
        $up    = [];
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $up[] = implode('.', array_slice($parts, $i));
        }

        return $up;
    }
}
