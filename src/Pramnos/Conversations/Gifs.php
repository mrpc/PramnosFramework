<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Security\OutboundUrl;

/**
 * GIFs in the conversations, from Giphy or Klipy: the search and the trending ones asked by this
 * server with the application's key — never the browser's — and a GIF that is sent kept as a file
 * of its message.
 *
 * ```php
 * 'conversations' => ['gifs' => ['provider' => 'giphy', 'key' => '…', 'rating' => 'pg']],
 * ```
 *
 * The key may also be an administrator's setting, `conversations_gif_key`, which wins. Without a
 * key there are no GIFs ({@see enabled()}).
 *
 * A sent GIF is kept as a file rather than linked: it stays with the message when the provider
 * removes it, a reader's browser never asks the provider for it, and it counts in the
 * conversations' space and leaves with their files. Only a GIF from the provider's own media
 * hosts is taken, so this is not a way to make the server fetch anything else.
 */
final class Gifs
{
    /** The administrator's setting for the key. */
    public const SETTING = 'conversations_gif_key';

    /** The ratings a provider takes, mildest first. */
    public const RATINGS = ['g', 'pg', 'pg-13', 'r'];

    /** The most a sent GIF weighs. */
    public const MAX_BYTES = 8388608;

    /** The hosts each provider serves its GIFs from: what a sent GIF may come from. */
    private const MEDIA = [
        'giphy' => '/^(media\d*|i)\.giphy\.com$/',
        'klipy' => '/^([a-z0-9-]+\.)?klipy\.com$/',
    ];

    /** @var (callable(string, int): (string|false))|null What reads a URL, in place of the network: tests. */
    private static $fetcher = null;

    /** Read URLs with this in place of the network, or with the network again (null). */
    public static function fetchWith(?callable $fetch): void
    {
        self::$fetcher = $fetch;
    }

    /** Whether the conversations offer GIFs: a provider this knows, with a key. */
    public static function enabled(): bool
    {
        return isset(self::MEDIA[self::provider()]) && self::key() !== '';
    }

    /** The provider in use, `giphy` unless app.php names another. */
    public static function provider(): string
    {
        return strtolower((string) (self::config()['provider'] ?? 'giphy'));
    }

    /**
     * GIFs for a search, or the trending ones for none: each with a small picture for the picker
     * and the one to send.
     *
     * @return list<array{id: string, title: string, preview: array{url: string, width: int, height: int}, url: string}>
     * @throws \DomainException          Where there are no GIFs here — a 404 to the API
     * @throws \InvalidArgumentException Where the provider cannot be asked or refuses the key — a 422
     */
    public static function search(string $query, int $limit = 24): array
    {
        if (!self::enabled()) {
            throw new \DomainException(t('GIFs are not set up here.'));
        }
        $query  = trim(mb_substr($query, 0, 100));
        $limit  = max(1, min(50, $limit));
        $rating = in_array($r = strtolower((string) (self::config()['rating'] ?? 'g')), self::RATINGS, true) ? $r : 'g';
        $key    = rawurlencode(self::key());
        $url    = self::provider() === 'klipy'
            ? 'https://api.klipy.com/api/v1/' . $key . '/gifs/' . ($query === '' ? 'trending?' : 'search?q=' . rawurlencode($query) . '&') . 'per_page=' . $limit . '&rating=' . $rating
            : 'https://api.giphy.com/v1/gifs/' . ($query === '' ? 'trending?' : 'search?q=' . rawurlencode($query) . '&') . 'api_key=' . $key . '&limit=' . $limit . '&rating=' . $rating;
        $body = self::get($url, 2097152);
        $data = $body === false ? null : json_decode($body, true);
        if (!is_array($data) || (int) ($data['meta']['status'] ?? 200) >= 400 || ($data['result'] ?? true) === false) {
            throw new \InvalidArgumentException(t('The GIFs could not be found just now.'));
        }

        return array_values(array_filter(array_map(
            self::provider() === 'klipy' ? self::klipy(...) : self::giphy(...),
            (array) (self::provider() === 'klipy' ? ($data['data']['data'] ?? []) : ($data['data'] ?? []))
        )));
    }

    /**
     * Keep a GIF the picker offered as a file of a conversation, for the message about to be
     * written — only from the provider's media hosts, only a GIF.
     *
     * @return int The file
     * @throws \InvalidArgumentException Where it is not one of the provider's GIFs
     */
    public static function keep(int $conversationId, int $by, string $url, string $title = ''): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!self::enabled() || parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match(self::MEDIA[self::provider()], $host)) {
            throw new \InvalidArgumentException(t('That is not a GIF from here.'));
        }
        $bytes = self::get($url, self::MAX_BYTES);
        if ($bytes === false || !str_starts_with($bytes, 'GIF8')) {
            throw new \InvalidArgumentException(t('That GIF could not be brought here.'));
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pfgif');
        file_put_contents($tmp, $bytes);
        $name = trim((string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $title));

        try {
            return Files::store($conversationId, $by, $tmp, mb_substr($name !== '' ? $name : 'GIF', 0, 80) . '.gif');
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @param array<string, mixed> $item @return array<string, mixed>|null A Giphy result, as the picker reads it. */
    private static function giphy(mixed $item): ?array
    {
        $images  = is_array($item) ? (array) ($item['images'] ?? []) : [];
        $preview = $images['fixed_width_small'] ?? $images['fixed_width'] ?? null;
        $send    = $images['downsized_medium'] ?? $images['original'] ?? null;

        return is_array($preview) && is_array($send) && isset($preview['url'], $send['url']) ? ['id' => (string) ($item['id'] ?? ''), 'title' => (string) ($item['title'] ?? ''),
            'preview' => ['url' => (string) $preview['url'], 'width' => (int) ($preview['width'] ?? 0), 'height' => (int) ($preview['height'] ?? 0)],
            'url' => explode('?', (string) $send['url'])[0]] : null;
    }

    /**
     * A Klipy result, as the picker reads it: its renditions by size, the largest within a budget —
     * a small one for the picker, one that loads quickly for the message.
     *
     * @return array<string, mixed>|null
     */
    private static function klipy(mixed $item): ?array
    {
        $files = is_array($item) ? (array) ($item['file'] ?? $item['files'] ?? []) : [];
        $pick  = static function (int $budget) use ($files): ?array {
            $all = [];
            foreach (['xs', 'sm', 'md', 'hd'] as $size) {
                $gif = $files[$size]['gif'] ?? null;
                if (is_array($gif) && isset($gif['url'])) {
                    $all[] = $gif + ['size' => 0];
                }
            }
            usort($all, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);
            $within = array_values(array_filter($all, static fn (array $g): bool => $g['size'] <= $budget));

            return $within[0] ?? ($all === [] ? null : end($all));
        };
        $preview = $pick(409600);
        $send    = $pick(1572864);

        return $preview === null || $send === null ? null : ['id' => (string) ($item['slug'] ?? $item['id'] ?? ''), 'title' => (string) ($item['title'] ?? $item['slug'] ?? ''),
            'preview' => ['url' => (string) $preview['url'], 'width' => (int) ($preview['width'] ?? 0), 'height' => (int) ($preview['height'] ?? 0)],
            'url' => explode('?', (string) $send['url'])[0]];
    }

    /** The key: the administrator's setting, else app.php's. */
    private static function key(): string
    {
        $set = trim((string) \Pramnos\Application\Settings::getSetting(self::SETTING, ''));

        return $set !== '' ? $set : trim((string) (self::config()['key'] ?? ''));
    }

    /** @return array<string, mixed> `conversations.gifs` */
    private static function config(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) ? (array) ($app->applicationInfo['conversations']['gifs'] ?? []) : [];
    }

    /** A URL's body, read safely — or false. */
    private static function get(string $url, int $max): string|false
    {
        if (self::$fetcher !== null) {
            return (self::$fetcher)($url, $max);
        }
        $body = OutboundUrl::fetch($url, $max, $reason, 8, 3, $status);

        return $body !== false && $status >= 200 && $status < 300 ? $body : false;
    }
}
