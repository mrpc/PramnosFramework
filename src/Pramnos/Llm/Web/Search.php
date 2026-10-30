<?php

declare(strict_types=1);

namespace Pramnos\Llm\Web;

use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * A web or news search, through the providers an application has keys for, in its order —
 * and Bing's own feed when none answers.
 *
 * ```php
 * 'llm' => ['web' => [
 *     'search' => ['brave' => getenv('BRAVE_KEY'), 'serper' => getenv('SERPER_KEY')],  // asked in this order
 *     'bing'   => true,    // the free fallback; false to never use it
 * ]],
 * ```
 *
 * Providers: `brave`, `tavily`, `exa`, `serper`, `jina`. The first that answers with results wins;
 * one that refuses (a bad or spent key, its own trouble) passes to the next. Bing answers its
 * search as RSS when asked (`format=rss`) — undocumented and not a promise, which is why it is the
 * last resort and can be turned off. What each answers is one shape:
 * `{title, link, published_at, content}`.
 */
final class Search
{
    public const PROVIDERS = ['brave', 'tavily', 'exa', 'serper', 'jina'];

    public const TIMEOUT = 15;

    /** Bing's search and news search, as feeds. */
    public const BING = ['web' => 'https://www.bing.com/search', 'news' => 'https://www.bing.com/news/search'];

    /** How far back a news search looks, where the provider takes a date. */
    public const NEWS_DAYS = 30;

    /**
     * What a search found.
     *
     * @param string $kind `web`, or `news` for recent articles
     * @return list<array{title: string, link: string, published_at: ?string, content: ?string}>
     */
    public function search(string $query, string $kind = 'web', string $language = 'en', int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $kind  = $kind === 'news' ? 'news' : 'web';
        $limit = max(1, min(20, $limit));
        $lang  = preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : 'en';

        foreach (self::keys() as $provider => $key) {
            try {
                $response = $this->ask($provider, (string) $key, $query, $kind, $lang, $limit);
            } catch (\Throwable) {
                continue;
            }
            if ($response->successful()) {
                $found = self::results($provider, (array) ($response->json() ?? []));
                if ($found !== []) {
                    return array_slice($found, 0, $limit);
                }
            }
        }

        $web = \Pramnos\Llm\Llm::config('web', []);
        if (is_array($web) && array_key_exists('bing', $web) && !$web['bing']) {
            return [];
        }

        return array_slice($this->bing($query, $kind, $lang), 0, $limit);
    }

    /**
     * The providers with a key, in the application's order.
     *
     * @return array<string, string>
     */
    public static function keys(): array
    {
        $web  = \Pramnos\Llm\Llm::config('web', []);
        $keys = is_array($web) && is_array($web['search'] ?? null) ? $web['search'] : [];

        return array_filter(
            array_intersect_key($keys, array_flip(self::PROVIDERS)),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        );
    }

    /** One provider's request. */
    private function ask(string $provider, string $key, string $query, string $kind, string $lang, int $limit): ClientResponse
    {
        $news   = $kind === 'news';
        $client = match ($provider) {
            'brave' => Client::get('https://api.search.brave.com/res/v1/' . ($news ? 'news' : 'web') . '/search?' . http_build_query([
                'q' => $query, 'count' => $limit, 'search_lang' => $lang,
            ]))->header('X-Subscription-Token', $key)->header('Accept', 'application/json'),
            'tavily' => Client::post('https://api.tavily.com/search')->bearerToken($key)->json([
                'query' => $query, 'topic' => $news ? 'news' : 'general', 'max_results' => $limit,
            ]),
            'exa' => Client::post('https://api.exa.ai/search')->header('x-api-key', $key)->json(array_filter([
                'query' => $query, 'numResults' => $limit,
                'category' => $news ? 'news' : null,
                'startPublishedDate' => $news ? gmdate('Y-m-d\TH:i:s\Z', time() - self::NEWS_DAYS * 86400) : null,
                'contents' => ['highlights' => ['maxCharacters' => 300]],
            ], static fn ($v): bool => $v !== null)),
            'serper' => Client::post('https://google.serper.dev/' . ($news ? 'news' : 'search'))->header('X-API-KEY', $key)->json([
                'q' => $query, 'hl' => $lang, 'num' => $limit,
            ]),
            // Titles and descriptions only: the pages' words would be billed, and fetch_url reads the one chosen.
            default => Client::get('https://s.jina.ai/?' . http_build_query(['q' => $query, 'hl' => $lang]))
                ->bearerToken($key)->header('Accept', 'application/json')->header('X-Respond-With', 'no-content'),
        };

        return $client->timeout(self::TIMEOUT)->send();
    }

    /**
     * A provider's answer in the one shape.
     *
     * @param array<string, mixed> $json
     * @return list<array{title: string, link: string, published_at: ?string, content: ?string}>
     */
    private static function results(string $provider, array $json): array
    {
        $rows = (array) ($json['results'] ?? $json['news'] ?? $json['organic'] ?? $json['web']['results'] ?? $json['data'] ?? []);
        $out  = [];
        foreach ($rows as $row) {
            $url = is_array($row) ? (string) ($row['url'] ?? $row['link'] ?? '') : '';
            if (preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $when = $row['page_age'] ?? $row['age'] ?? $row['published_date'] ?? $row['publishedDate'] ?? $row['date'] ?? null;
            $text = $row['description'] ?? $row['content'] ?? $row['snippet'] ?? (implode(' ', (array) ($row['highlights'] ?? [])) ?: null);
            $at   = is_string($when) ? strtotime($when) : false;
            $out[] = [
                'title'        => trim(strip_tags(html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES))),
                'link'         => $url,
                'published_at' => $at !== false ? gmdate('Y-m-d H:i:sP', $at) : null,
                'content'      => is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($text, ENT_QUOTES)))) : null,
            ];
        }

        return $out;
    }

    /**
     * Bing's search as a feed, each link the article itself rather than Bing's click counter.
     *
     * @return list<array{title: string, link: string, published_at: ?string, content: ?string}>
     */
    private function bing(string $query, string $kind, string $lang): array
    {
        try {
            $response = Client::get(self::BING[$kind] . '?' . http_build_query(['q' => $query, 'setlang' => $lang, 'format' => 'rss']))
                ->timeout(self::TIMEOUT)->maxResponseBytes(2_000_000)
                ->header('Accept', 'application/rss+xml, application/xml; q=0.9, */*; q=0.8')->send();
        } catch (\Throwable) {
            return [];
        }
        $quiet = libxml_use_internal_errors(true);
        $feed  = $response->successful() ? simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($quiet);
        if ($feed === false || !isset($feed->channel->item)) {
            return [];
        }

        $out = [];
        foreach ($feed->channel->item as $item) {
            $link = self::unwrap(trim((string) $item->link));
            if (preg_match('#^https?://#i', $link) !== 1) {
                continue;
            }
            $at    = strtotime((string) $item->pubDate);
            $out[] = [
                'title'        => trim(html_entity_decode((string) $item->title, ENT_QUOTES)),
                'link'         => $link,
                'published_at' => $at !== false && (string) $item->pubDate !== '' ? gmdate('Y-m-d H:i:sP', $at) : null,
                'content'      => trim(PageText::fromFragment((string) $item->description)) ?: null,
            ];
        }
        // News newest first; a web search in Bing's own order, which is its relevance.
        if ($kind === 'news') {
            usort($out, static fn (array $a, array $b): int => strcmp((string) $b['published_at'], (string) $a['published_at']));
        }

        return $out;
    }

    /** The article behind Bing's click-through address (`bing.com/news/apiclick.aspx?…&url=<article>`). */
    public static function unwrap(string $link): string
    {
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        if ($host !== 'bing.com' && !str_ends_with($host, '.bing.com')) {
            return $link;
        }
        parse_str((string) parse_url($link, PHP_URL_QUERY), $params);
        $target = trim((string) ($params['url'] ?? ''));

        return preg_match('#^https?://#i', $target) === 1 ? $target : $link;
    }
}
