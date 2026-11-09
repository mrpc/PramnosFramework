<?php

declare(strict_types=1);

namespace Pramnos\Visits;

/**
 * Where a visit came from: `direct`, `search`, `social`, `ai` (an assistant), `email`,
 * `campaign`, `referral` (another website) or `internal` (one of the application's own sites).
 *
 * ```php
 * Source::classify('https://www.google.gr/', [], 'https://example.com/');
 * // ['source' => 'search', 'medium' => 'organic', 'campaign' => null]
 * Source::classify(null, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'nov'], '…');
 * // ['source' => 'email', 'medium' => 'email', 'campaign' => 'nov']
 * ```
 *
 * The utm parameters win over the referrer; a host in `visits.sources` (app.php) is the
 * application's own addition to the framework's lists: `'sources' => ['search' => ['example-search.com']]`.
 */
final class Source
{
    /** The framework's hosts, by kind; a host matches itself and its subdomains. */
    public const HOSTS = [
        'search' => ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.com', 'ecosia.org', 'qwant.com', 'search.brave.com', 'startpage.com'],
        'social' => ['facebook.com', 'fb.com', 'instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'pinterest.', 'reddit.com',
            'tiktok.com', 'youtube.com', 'youtu.be', 'threads.net', 'bsky.app', 'mastodon.', 'whatsapp.com', 'telegram.org', 't.me', 'viber.com'],
        'ai'     => ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'chat.deepseek.com', 'grok.com'],
        'email'  => ['mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.com', 'webmail.', 'mail.'],
    ];

    /** The kinds a source may be. */
    public const KINDS = ['direct', 'search', 'social', 'ai', 'email', 'campaign', 'referral', 'internal'];

    /**
     * The visit's source, medium and campaign.
     *
     * @param array<string, string> $utm   `utm_source`, `utm_medium`, `utm_campaign`
     * @param list<string>          $own   The hosts of the application's own sites
     * @return array{source: string, medium: ?string, campaign: ?string}
     */
    public static function classify(?string $referrer, array $utm, string $landing, array $own = []): array
    {
        $cut      = static fn ($v, int $n): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $n) : null;
        $medium   = $cut($utm['utm_medium'] ?? null, 64);
        $campaign = $cut($utm['utm_campaign'] ?? null, 128);
        $from     = $cut($utm['utm_source'] ?? null, 64);
        if ($from !== null || $medium !== null || $campaign !== null) {
            $kind = strtolower((string) $medium);
            $type = match (true) {
                in_array($kind, ['email', 'e-mail', 'newsletter'], true)              => 'email',
                in_array($kind, ['social', 'social-media', 'social_media'], true)     => 'social',
                in_array($kind, ['organic'], true)                                    => 'search',
                default                                                                => self::byHost((string) $from) ?? 'campaign',
            };

            return ['source' => $type, 'medium' => $medium ?? $from, 'campaign' => $campaign];
        }
        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        if ($host === '') {
            return ['source' => 'direct', 'medium' => null, 'campaign' => null];
        }
        $landingHost = strtolower((string) parse_url($landing, PHP_URL_HOST));
        foreach (array_merge($own, $landingHost !== '' ? [$landingHost] : []) as $mine) {
            if (self::under($host, strtolower($mine))) {
                return ['source' => 'internal', 'medium' => null, 'campaign' => null];
            }
        }
        $type = self::byHost($host) ?? 'referral';

        return ['source' => $type, 'medium' => $type === 'search' ? 'organic' : null, 'campaign' => null];
    }

    /** The kind a host — or a utm_source's name — is, by the framework's lists and the application's; null for none. */
    public static function byHost(string $host): ?string
    {
        $host  = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        // A utm_source is a name — `facebook`, `google` — as often as a host: the lists' first labels.
        if ($host !== '' && !str_contains($host, '.')) {
            foreach (['ai', 'email', 'search', 'social'] as $kind) {
                foreach (array_merge((array) ((Visits::config()['sources'] ?? [])[$kind] ?? []), self::HOSTS[$kind]) as $needle) {
                    if (explode('.', strtolower((string) $needle))[0] === $host) {
                        return $kind;
                    }
                }
            }

            return null;
        }
        $extra = (array) (Visits::config()['sources'] ?? []);
        foreach (['ai', 'email', 'search', 'social'] as $kind) {
            foreach (array_merge((array) ($extra[$kind] ?? []), self::HOSTS[$kind]) as $needle) {
                $needle = strtolower((string) $needle);
                if (str_ends_with($needle, '.') ? (str_starts_with($host, $needle) || str_contains($host, '.' . $needle)) : self::under($host, $needle)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /** Whether a host is the domain or one of its subdomains. */
    private static function under(string $host, string $domain): bool
    {
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
        $host   = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }
}
