<?php

declare(strict_types=1);

namespace Pramnos\Visits;

/**
 * Page views and clicks out, taken and written: refused before they cost anything, the visitor
 * and the visit worked out, where from, place and device added, and appended to
 * `pramnos.page_hits` through the write spool — never an INSERT in the request.
 *
 * ```php
 * // the beacon (`Hits` controller): what the page sent
 * Hits::take($events, $_SERVER, $_COOKIE, $userid);   // ['rows' => [...], 'cookies' => [...]]
 * // an application's own tracker, writing the framework's row beside its own
 * Hits::record(['site' => 'main', 'url' => $url, 'referrer' => $ref, 'visitor' => $v, 'visit' => $id, 'seq' => 1,
 *     'userid' => $user, 'ip' => $ip, 'ua' => $ua, 'at' => time()]);
 * ```
 *
 * Refused, and not written: a page or an origin that is none of the sites' hosts, a referrer on
 * the spam list, more than {@see PER_MINUTE} hits a minute from one visitor, more than
 * {@see MAX_EVENTS} in one request. A bot, or a page without JavaScript, is written with its
 * flag, to be counted apart.
 */
final class Hits
{
    /** Hits a minute from one visitor. */
    public const PER_MINUTE = 60;

    /** Hits in one request. */
    public const MAX_EVENTS = 20;

    /** The flags. */
    public const BOT       = 1;
    public const NO_JS     = 2;
    public const PRERENDER = 4;
    public const CACHED    = 8;

    /** The kinds of hit. */
    public const KINDS = ['view', 'click', 'event'];

    /** @var (\Closure(string): bool)|null A test's limit: whether one more is allowed for this key */
    private static ?\Closure $limit = null;

    /** Count the limit another way — a test's; null restores the cache's. */
    public static function limitWith(?\Closure $limit): void
    {
        self::$limit = $limit;
    }

    /**
     * What one request of the beacon sent: each `{k, u, r, t, e, s, p, c, n}` — kind, the page,
     * its referrer, a click's target, an event's name, the screen, prerendered, from the page
     * cache, without JavaScript.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>       $server  `$_SERVER`
     * @param array<string, mixed>       $cookies `$_COOKIE`
     * @return array{rows: list<array<string, mixed>>, cookies: array<string, array{0: string, 1: int}>} What was written, and the cookies to set: name => [value, expires]
     */
    public static function take(array $events, array $server, array $cookies, ?int $userid = null, ?int $now = null): array
    {
        $none = ['rows' => [], 'cookies' => []];
        $now ??= time();
        $events = array_values(array_filter($events, 'is_array'));
        if ($events === [] || count($events) > self::MAX_EVENTS) {
            return $none;
        }
        $page = (string) ($events[0]['u'] ?? '');
        $site = Visits::siteFor((string) parse_url($page, PHP_URL_HOST));
        $from = (string) ($server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '');
        if ($site === null || $from === '' || (Visits::siteFor((string) parse_url($from, PHP_URL_HOST))['id'] ?? null) !== $site['id']) {
            return $none;
        }
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        [$visitor, $visit, $seq, $set] = self::visitor($site, $events[0], $cookies, $ip, $ua, $now);
        $rows = [];
        foreach ($events as $event) {
            if (!self::allowed($site['id'] . ':' . $visitor)) {
                break;
            }
            $flags = (!empty($event['p']) ? self::PRERENDER : 0) | (!empty($event['c']) ? self::CACHED : 0) | (!empty($event['n']) ? self::NO_JS : 0);
            $row   = self::record(['site' => $site, 'at' => $now, 'visitor' => $visitor, 'visit' => $visit, 'seq' => $visit === null ? null : $seq++,
                'kind' => (string) ($event['k'] ?? 'view'), 'event' => $event['e'] ?? null, 'url' => (string) ($event['u'] ?? $page),
                'target' => $event['t'] ?? null, 'referrer' => $event['r'] ?? null, 'userid' => $userid, 'ip' => $ip, 'ua' => $ua,
                'screen' => $event['s'] ?? null, 'flags' => $flags]);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        if ($visit !== null) {
            $set[Visits::VISIT_COOKIE] = [$visit . '.' . ($seq - 1) . '.' . $now . '.' . self::campaignKey($events[0]), $now + $site['idle']];
        }

        return ['rows' => $rows, 'cookies' => $rows === [] ? [] : $set];
    }

    /**
     * Write one hit: its site (a name, or as {@see Visits::siteFor()} answers), `at`, `visitor`,
     * `visit`, `seq`, `kind`, `event`, `url`, `target`, `referrer`, `userid`, `ip`, `ua`, `screen`,
     * `flags`, and `utm` where the page's address does not carry them. Where it came from is worked
     * out on a visit's first hit, and on every hit of a cookieless one. The address and the user
     * agent are not kept.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>|null The row appended; null where it was refused
     */
    public static function record(array $hit): ?array
    {
        $site = is_array($hit['site'] ?? null) ? $hit['site'] : (Visits::sites()[(string) ($hit['site'] ?? '')] ?? null);
        if ($site === null) {
            return null;
        }
        if (!isset($site['id'])) {
            $site = Visits::siteFor($site['hosts'][0] ?? '') ?? $site;
        }
        $url = (string) ($hit['url'] ?? '');
        if ($url === '' || !isset($site['id'])) {
            return null;
        }
        $kind     = in_array($hit['kind'] ?? 'view', self::KINDS, true) ? (string) ($hit['kind'] ?? 'view') : 'view';
        $referrer = is_string($hit['referrer'] ?? null) && $hit['referrer'] !== '' ? (string) $hit['referrer'] : null;
        $refHost  = $referrer === null ? null : strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if ($refHost !== null && $refHost !== '' && Visits::spam($refHost)) {
            return null;
        }
        $ua    = (string) ($hit['ua'] ?? '');
        $flags = (int) ($hit['flags'] ?? 0) | ($ua === '' || (new \Pramnos\Http\Middleware\BotDetector())->isBot($ua) ? self::BOT : 0);
        $first = ($hit['visit'] ?? null) === null || (int) ($hit['seq'] ?? 1) === 1;
        $row   = ['at' => \Pramnos\Database\Instant::local((int) ($hit['at'] ?? time())), 'site' => (int) $site['id'],
            'visitor' => (int) ($hit['visitor'] ?? 0), 'visit' => isset($hit['visit']) ? (int) $hit['visit'] : null,
            'seq' => isset($hit['seq']) ? (int) $hit['seq'] : null, 'kind' => $kind,
            'event' => $kind === 'event' && is_string($hit['event'] ?? null) ? mb_substr($hit['event'], 0, 64) : null,
            'urlid' => (int) \Pramnos\User\Token::urlId(mb_substr($url, 0, 2048)),
            'target' => $kind === 'click' && is_string($hit['target'] ?? null) && $hit['target'] !== '' ? (int) \Pramnos\User\Token::urlId(mb_substr($hit['target'], 0, 2048)) : null,
            'userid' => isset($hit['userid']) && (int) $hit['userid'] > 1 ? (int) $hit['userid'] : null, 'flags' => $flags,
            'screen' => is_string($hit['screen'] ?? null) && preg_match('/^\d{2,5}x\d{2,5}$/', $hit['screen']) === 1 ? $hit['screen'] : null];
        if ($first) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $utm  = array_intersect_key((array) ($hit['utm'] ?? []) + $query, array_flip(['utm_source', 'utm_medium', 'utm_campaign']));
            $from = Source::classify($referrer, array_map('strval', array_filter($utm, 'is_scalar')), $url, Visits::hosts());
            $row += ['source' => $from['source'], 'medium' => $from['medium'], 'campaign' => $from['campaign'],
                'referrer_host' => $refHost !== null && $refHost !== '' ? mb_substr($refHost, 0, 255) : null,
                'referrer' => $referrer !== null && $from['source'] !== 'internal' ? (int) \Pramnos\User\Token::urlId(mb_substr($referrer, 0, 2048)) : null];
        }
        $place = GeoIp::lookup((string) ($hit['ip'] ?? ''));
        if ($place !== null) {
            $row += $place;
        }
        [$browser, $os] = explode('|', \Pramnos\Auth\SignInFingerprint::fromUserAgent($ua));
        $row += ['device' => self::device($ua), 'browser' => $browser, 'os' => $os,
            'dimension' => Visits::dimension((string) (parse_url($url, PHP_URL_PATH) ?? '/'), $row)];
        \Pramnos\Database\WriteSpool::append('pramnos.page_hits', $row);

        return $row;
    }

    /** `mobile`, `tablet` or `desktop`, from the user agent. */
    public static function device(string $ua): string
    {
        $ua = strtolower($ua);

        return match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && !str_contains($ua, 'mobile')) => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')                                 => 'mobile',
            default                                                                                                                  => 'desktop',
        };
    }

    /**
     * The visitor, the visit, the next hit's number in it, and the cookies to set.
     *
     * Cookie model: `pf_vid` (two years) and `pf_visit` — `visit.seq.last.campaign` — a new visit
     * after the site's idle time, or when the page carries another campaign. Cookieless: eight
     * bytes of `sha256(today's salt · address · agent · site)`, no visit.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $first   The request's first event
     * @param array<string, mixed> $cookies
     * @return array{0: int, 1: ?int, 2: int, 3: array<string, array{0: string, 1: int}>}
     */
    private static function visitor(array $site, array $first, array $cookies, string $ip, string $ua, int $now): array
    {
        if ($site['visitor'] === 'cookieless') {
            $hash = unpack('J', substr(hash('sha256', Visits::salt() . '|' . $ip . '|' . $ua . '|' . $site['id'], true), 0, 8))[1];

            return [$hash & PHP_INT_MAX, null, 1, []];
        }
        $set     = [];
        $visitor = (string) ($cookies[Visits::VISITOR_COOKIE] ?? '');
        if (!ctype_digit($visitor) || (int) $visitor <= 0) {
            $visitor = (string) random_int(1, PHP_INT_MAX);
        }
        $set[Visits::VISITOR_COOKIE] = [$visitor, $now + 2 * 365 * 86400];
        $parts = explode('.', (string) ($cookies[Visits::VISIT_COOKIE] ?? ''));
        $same  = count($parts) === 4 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && ctype_digit($parts[2])
            && $now - (int) $parts[2] <= $site['idle'] && (self::campaignKey($first) === '0' || self::campaignKey($first) === $parts[3]);

        return $same ? [(int) $visitor, (int) $parts[0], (int) $parts[1] + 1, $set] : [(int) $visitor, random_int(1, PHP_INT_MAX), 1, $set];
    }

    /** The campaign a page carries, as a short key; `0` for none. @param array<string, mixed> $event */
    private static function campaignKey(array $event): string
    {
        parse_str((string) parse_url((string) ($event['u'] ?? ''), PHP_URL_QUERY), $query);
        $campaign = implode('|', array_map(static fn (string $k): string => (string) ($query[$k] ?? ''), ['utm_source', 'utm_medium', 'utm_campaign']));

        return $campaign === '||' ? '0' : (string) crc32($campaign);
    }

    /** Whether one more hit is allowed for this visitor this minute. */
    private static function allowed(string $key): bool
    {
        if (self::$limit !== null) {
            return (self::$limit)($key);
        }
        // @codeCoverageIgnoreStart
        try {
            return (new \Pramnos\Http\TokenBucket())->take('visits:' . $key, self::PER_MINUTE, self::PER_MINUTE, 60)['allowed'];
        } catch (\Throwable) {
            return true;
        }
        // @codeCoverageIgnoreEnd
    }
}
