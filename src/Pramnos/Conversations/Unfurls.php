<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Security\OutboundUrl;

/**
 * A link's preview card under a message, as Slack draws one: its site, title, description,
 * picture — and, for a video, a player.
 *
 * When a message is written or edited, {@see remember()} keeps its links (at most {@see MAX}, none
 * inside code, none the application's own references or pages) on the message and queues
 * `conversation_unfurl`; the worker ({@see run()}) reads each link once a week at most — oEmbed
 * where the site has it, OpenGraph and Twitter cards otherwise — keeps the pictures beside the
 * conversations' files, so a reader's browser never asks the other site, and tells the
 * conversation `message.unfurled`. A writer takes a card off their message with {@see hide()}.
 * A preview nobody's link has pointed at again in a while, and its picture, leave with
 * {@see prune()} — scheduled as `conversations:unfurls-prune`.
 *
 * ```php
 * 'conversations' => ['unfurl' => false],                                     // none at all
 * 'conversations' => ['unfurl' => ['deny' => ['example.com']]],               // not these sites
 * 'conversations' => ['unfurl' => ['allow' => ['youtube.com', 'vimeo.com']]], // only these
 * ```
 *
 * Every read goes through {@see OutboundUrl::fetch()}: public addresses only, a timeout, a ceiling.
 */
final class Unfurls
{
    /** The queue task type. */
    public const TASK = 'conversation_unfurl';

    /** The most links of one message with a card. */
    public const MAX = 3;

    /** How long what a link shows is kept before it is read again, in seconds. */
    public const FRESH = 7 * 86400;

    /** The sites whose oEmbed answers for them, by host, and the player each embeds. */
    private const OEMBED = [
        'youtube.com' => 'https://www.youtube.com/oembed?format=json&url=', 'youtu.be' => 'https://www.youtube.com/oembed?format=json&url=',
        'vimeo.com' => 'https://vimeo.com/api/oembed.json?url=', 'loom.com' => 'https://www.loom.com/v1/oembed?url=',
        'twitter.com' => 'https://publish.twitter.com/oembed?url=', 'x.com' => 'https://publish.twitter.com/oembed?url=',
        'youtube-nocookie.com' => 'https://www.youtube.com/oembed?format=json&url=', 'soundcloud.com' => 'https://soundcloud.com/oembed?format=json&url=',
        'spotify.com' => 'https://open.spotify.com/oembed?url=', 'tiktok.com' => 'https://www.tiktok.com/oembed?url=',
    ];

    /** @var (callable(string, int): (string|false))|null What reads a URL, in place of the network: tests. */
    private static $fetcher = null;

    /** Read URLs with this in place of the network — a test's pages — or with the network again (null). */
    public static function fetchWith(?callable $fetch): void
    {
        self::$fetcher = $fetch;
    }

    /**
     * The links of a text that get a card: http(s), outside code, not the application's own
     * references or pages, as `conversations.unfurl` allows — each once, at most {@see MAX}.
     *
     * @return list<string>
     */
    public static function urls(string $body): array
    {
        $config = self::config();
        if ($config === null) {
            return [];
        }
        preg_match_all('#https?://[^\s<>()\[\]"\'`]+#i', (string) preg_replace('/```.*?```|`[^`\n]*`/s', ' ', $body), $m);
        $own = self::ownHosts();
        $out = [];
        foreach ($m[0] as $url) {
            $url  = self::normalize(rtrim($url, '.,;:!?*_~'));
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '' || in_array($url, $out, true) || in_array($host, $own, true) || References::find($url) !== []
                || self::listed($host, (array) ($config['deny'] ?? [])) || (!empty($config['allow']) && !self::listed($host, (array) $config['allow']))) {
                continue;
            }
            $out[] = $url;
            if (count($out) === self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * Keep a message's links on it — a card its writer took away stays away — and queue reading
     * them. Whatever fails here — no queue, a migration not run — leaves the message without
     * cards, never unwritten.
     */
    public static function remember(int $id, string $body): void
    {
        try {
            $urls = self::urls($body);
            $kept = self::kept($id);
            if ($urls === [] && $kept === []) {
                return;
            }
            $hidden = array_column(array_filter($kept, static fn (array $u): bool => $u['hidden']), 'url');
            $links  = array_map(static fn (string $url): array => ['url' => $url, 'hidden' => in_array($url, $hidden, true)], $urls);
            self::write($id, $links);
            if ($links !== []) {
                (new \Pramnos\Queue\QueueManager(\Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')))
                    ->addTask(self::TASK, ['message' => $id], 20, 2);
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: the links of message ' . $id . ' get no preview: ' . $e->getMessage(), 'queue');
        }
    }

    /**
     * The worker's half: read each link of a message, where it is not read already, and tell the
     * conversation.
     *
     * @return int How many of its links show something
     */
    public static function run(int $id): int
    {
        $links = self::kept($id);
        if ($links === []) {
            return 0;
        }
        $shown = count(array_filter(array_map(static fn (array $u): ?array => self::preview($u['url']), $links)));
        Realtime::changed($id, 'message.unfurled');

        return $shown;
    }

    /**
     * Drop link previews not read again in this long, and the pictures only they kept —
     * `pramnos.link_previews` otherwise grows forever, one row per link ever mentioned, and
     * `previews/` with it.
     *
     * @return int How many previews were dropped
     */
    public static function prune(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $query = self::table()->where('fetched_at', '<', time() - $days * 86400);
        $count = (int) (clone $query)->count();
        if ($count > 0) {
            $query->delete();
            self::sweepFiles();
        }

        return $count;
    }

    /** Delete pictures under {@see dir()} no row still names — only pruning calls this. */
    private static function sweepFiles(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $used = [];
        foreach (self::table()->select(['preview'])->getAllOrFail() as $row) {
            $preview = $row['preview'] === null ? null : json_decode((string) $row['preview'], true);
            foreach (['image', 'icon'] as $key) {
                if (isset($preview[$key]['name'])) {
                    $used[(string) $preview[$key]['name']] = true;
                }
            }
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..' && !isset($used[$file])) {
                @unlink($dir . '/' . $file);
            }
        }
    }

    /** Take a link's card off a message — by its writer only. */
    public static function hide(int $id, string $url, int $by): void
    {
        $message = Messages::get($id) ?? throw new \InvalidArgumentException(t('That message no longer exists.'));
        if ($message['authorId'] !== $by) {
            throw new \InvalidArgumentException(t('Only its writer changes a message.'));
        }
        self::write($id, array_map(static fn (array $u): array => ['url' => $u['url'], 'hidden' => $u['hidden'] || $u['url'] === $url], self::kept($id)));
        Realtime::changed($id, 'message.unfurled');
    }

    /**
     * The cards of messages as a reader sees them: those read, not hidden, in the message's order.
     *
     * @param array<int, list<array{url: string, hidden: bool}>> $links By message
     * @return array<int, list<array<string, mixed>>> By message
     */
    public static function cards(array $links): array
    {
        $urls = array_values(array_unique(array_column(array_merge([], ...array_values($links)), 'url')));
        $read = [];
        try {
            foreach ($urls === [] ? [] : self::table()->whereIn('url_hash', array_map(static fn (string $u): string => hash('sha256', $u), $urls))->getAllOrFail() as $row) {
                $read[(string) $row['url']] = $row['preview'] === null ? null : json_decode((string) $row['preview'], true);
            }
        } catch (\Throwable) {
            // No table yet: the messages without cards, still listed.
        }
        $out = [];
        foreach ($links as $id => $list) {
            $out[$id] = [];
            foreach ($list as $link) {
                if (!$link['hidden'] && is_array($read[$link['url']] ?? null)) {
                    $out[$id][] = self::card($link['url'], $read[$link['url']]);
                }
            }
        }

        return $out;
    }

    /**
     * What a link shows: kept, while it is fresh, or read now and kept — null where it shows nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function preview(string $url): ?array
    {
        $url  = self::normalize($url);
        $hash = hash('sha256', $url);
        $row  = self::table()->where('url_hash', $hash)->limit(1)->getAllOrFail()[0] ?? null;
        if ($row !== null && (int) $row['fetched_at'] > time() - self::FRESH) {
            return $row['preview'] === null ? null : json_decode((string) $row['preview'], true);
        }
        $preview = self::read($url);
        self::table()->where('url_hash', $hash)->delete();
        self::table()->insert(['url_hash' => $hash, 'url' => $url, 'preview' => $preview === null ? null : (string) json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fetched_at' => time()]);

        return $preview;
    }

    /**
     * Send a kept picture — a card's image or a site's icon — to a reader: from here, so their
     * browser never asks the other site.
     *
     * @return bool Whether it was sent; false is a 404
     */
    public static function sendImage(string $name): bool
    {
        if (!preg_match('/^[a-f0-9]{40}\.(jpg|png|gif|webp|ico)$/', $name, $m) || !is_file($path = self::dir() . '/' . $name)) {
            return false;
        }
        if (!headers_sent()) {
            header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon'][$m[1]]);
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, max-age=604800');
            header('X-Content-Type-Options: nosniff');
        }
        readfile($path);

        return true;
    }

    /** A link as it is kept: scheme and host in lower case, no fragment. */
    public static function normalize(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return $url;
        }

        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '')
            . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /**
     * Read a link: its oEmbed, where its site has one or its page names one, and its page's
     * OpenGraph and Twitter card; the pictures kept here.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $url): ?array
    {
        $host   = (string) parse_url($url, PHP_URL_HOST);
        $oembed = null;
        foreach (self::OEMBED as $site => $endpoint) {
            if (self::listed($host, [$site])) {
                $oembed = $endpoint . rawurlencode($url);
            }
        }
        // A site with oEmbed often shows a robot a consent wall: its oEmbed first, the page where
        // that says nothing — a private or removed item.
        $o    = $oembed === null ? null : json_decode((string) self::get($oembed, 262144), true);
        $meta = [];
        if (!is_array($o) || ($o['title'] ?? $o['html'] ?? '') === '') {
            $page = self::get($url, 1048576, true);
            if ($page === false) {
                return null;
            }
            $meta = self::meta($page, $url);
            $o    = isset($meta['oembed']) ? json_decode((string) self::get($meta['oembed'], 262144), true) : null;
        }
        $o = is_array($o) ? $o : [];
        // A post with no title — a tweet — is its words: the first paragraph of its markup, as text.
        $tweet = isset($o['html']) && !isset($o['title']) && preg_match('#<p[^>]*>(.*?)</p>#su', (string) $o['html'], $words)
            ? trim(html_entity_decode(strip_tags($words[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $title = trim((string) ($o['title'] ?? $meta['title'] ?? $o['author_name'] ?? ''));
        $image = (string) ($o['thumbnail_url'] ?? $meta['image'] ?? '');
        // A title that is only the link is what a page behind a wall gives a robot: nothing.
        if (rtrim($title, '/') === rtrim($url, '/')) {
            $title = '';
        }
        if ($title === '' && $image === '' && $tweet === '') {
            return null;
        }
        $embed = self::embed($url);
        if ($embed !== null && isset($o['width'], $o['height']) && (int) $o['height'] > 0) {
            $embed['aspect'] = round((int) $o['width'] / (int) $o['height'], 4);
        }

        return [
            'title'       => mb_substr($title, 0, 300),
            'description' => mb_substr(trim((string) ($meta['description'] ?? $tweet)), 0, 500),
            'site'        => mb_substr(trim((string) ($o['provider_name'] ?? $meta['site'] ?? preg_replace('/^www\./', '', $host))), 0, 100),
            'author'      => isset($o['author_name']) && $o['author_name'] !== $title ? mb_substr((string) $o['author_name'], 0, 150) : null,
            'image'       => $image === '' ? null : self::keep(OutboundUrl::resolveLocation($url, $image), 3145728),
            'icon'        => self::keep(OutboundUrl::resolveLocation($url, (string) ($meta['icon'] ?? '/favicon.ico')), 262144),
            'embed'       => $embed,
        ];
    }

    /**
     * What a page's head says of it: OpenGraph, Twitter card, `<title>`, its icon, its oEmbed.
     *
     * @return array<string, string>
     */
    private static function meta(string $html, string $base): array
    {
        $doc = new \DOMDocument();
        $was = libxml_use_internal_errors(true);
        $doc->loadHTML(self::entities($html), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($was);
        $tags = [];
        foreach ($doc->getElementsByTagName('meta') as $tag) {
            $key = strtolower($tag->getAttribute('property') ?: $tag->getAttribute('name'));
            if ($key !== '' && !isset($tags[$key])) {
                $tags[$key] = trim($tag->getAttribute('content'));
            }
        }
        $out = array_filter([
            'title'       => $tags['og:title'] ?? $tags['twitter:title'] ?? trim((string) $doc->getElementsByTagName('title')->item(0)?->textContent),
            'description' => $tags['og:description'] ?? $tags['twitter:description'] ?? $tags['description'] ?? '',
            'site'        => $tags['og:site_name'] ?? '',
            'image'       => $tags['og:image'] ?? $tags['og:image:url'] ?? $tags['twitter:image'] ?? $tags['twitter:image:src'] ?? '',
        ], static fn (string $v): bool => $v !== '');
        foreach ($doc->getElementsByTagName('link') as $link) {
            $rel = ' ' . strtolower($link->getAttribute('rel')) . ' ';
            if (str_contains($rel, ' alternate ') && strtolower($link->getAttribute('type')) === 'application/json+oembed') {
                $out['oembed'] ??= OutboundUrl::resolveLocation($base, $link->getAttribute('href'));
            } elseif (str_contains($rel, ' icon ') && $link->getAttribute('href') !== '') {
                $out['icon'] ??= $link->getAttribute('href');
            }
        }

        return $out;
    }

    /**
     * A page as libxml reads it right, whatever its charset: into UTF-8 from what it declares, then
     * every character past ASCII as a numeric entity — libxml takes a page with no early charset
     * for ISO-8859-1, and Greek would come out as `Îœ…`.
     */
    private static function entities(string $html): string
    {
        if (preg_match('/<meta[^>]+charset=["\']?\s*([a-z0-9][a-z0-9_-]*)/i', $html, $m) && !in_array(strtoupper($m[1]), ['UTF-8', 'UTF8'], true)) {
            // iconv knows the Windows code pages mbstring does not (Greek sites still serve 1253).
            $converted = @iconv($m[1], 'UTF-8//IGNORE', $html);
            if ($converted === false) {
                try {
                    $converted = mb_convert_encoding($html, 'UTF-8', $m[1]);
                } catch (\ValueError) {
                    $converted = $html;
                }
            }
            $html = (string) $converted;
        } elseif (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /**
     * The player a link opens in place, where its site has one: YouTube, Vimeo, Loom — by the
     * video's id, never the site's own markup.
     *
     * @return array{provider: string, id: string, aspect: float}|null
     */
    private static function embed(string $url): ?array
    {
        $patterns = [
            'youtube' => '#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([\w-]{11})#i',
            'vimeo'   => '#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d+)#i',
            'loom'    => '#^https?://(?:www\.)?loom\.com/(?:share|embed)/([a-f0-9]{32})#i',
        ];
        foreach ($patterns as $provider => $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return ['provider' => $provider, 'id' => $m[1], 'aspect' => round(16 / 9, 4)];
            }
        }

        return null;
    }

    /**
     * Keep a picture here — an image, by what it is — and say how to ask for it; null where it is
     * not one or cannot be read.
     *
     * @return array{name: string, width: int, height: int}|null
     */
    private static function keep(string $url, int $max): ?array
    {
        $bytes = $url === '' ? false : self::get($url, $max);
        $size  = $bytes === false ? false : @getimagesizefromstring($bytes);
        $ext   = is_array($size) ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', IMAGETYPE_ICO => 'ico'][$size[2]] ?? null) : null;
        if ($ext === null || (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true))) {
            return null;
        }
        $name = sha1($url) . '.' . $ext;

        return @file_put_contents(self::dir() . '/' . $name, $bytes) === false ? null : ['name' => $name, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    /** A URL's body, read safely — or false. */
    private static function get(string $url, int $max, bool $truncated = false): string|false
    {
        if (self::$fetcher !== null) {
            return (self::$fetcher)($url, $max);
        }
        $body = OutboundUrl::fetch($url, $max, $reason, 5, 3, $status, $truncated);

        return $body !== false && $status >= 200 && $status < 300 ? $body : false;
    }

    /**
     * A card as the API gives it: the pictures as this server's addresses.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function card(string $url, array $p): array
    {
        $src = static fn (?array $image): ?string => $image === null ? null : adminUrl('Conversations/preview') . '?name=' . $image['name'];

        return ['url' => $url, 'title' => (string) $p['title'], 'description' => (string) $p['description'], 'site' => (string) $p['site'], 'author' => $p['author'] ?? null,
            'icon' => $src($p['icon'] ?? null),
            'image' => isset($p['image']) ? ['url' => $src($p['image']), 'width' => $p['image']['width'], 'height' => $p['image']['height']] : null,
            'embed' => $p['embed'] ?? null];
    }

    /** @return list<array{url: string, hidden: bool}> A message's links, as kept on it. */
    private static function kept(int $id): array
    {
        $row = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages')->select(['unfurls'])->where('messageid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return self::decode($row['unfurls'] ?? null);
    }

    /** @return list<array{url: string, hidden: bool}> The column, read. */
    public static function decode(mixed $column): array
    {
        $list = $column === null ? [] : json_decode((string) $column, true);

        return is_array($list) ? array_values(array_map(static fn ($u): array => ['url' => (string) ($u['url'] ?? ''), 'hidden' => (bool) ($u['hidden'] ?? false)],
            array_filter($list, 'is_array'))) : [];
    }

    /** @param list<array{url: string, hidden: bool}> $links */
    private static function write(int $id, array $links): void
    {
        Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages')->where('messageid', $id)
            ->update(['unfurls' => $links === [] ? null : (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** Whether a host is one of these sites, or under one. @param list<string> $sites */
    private static function listed(string $host, array $sites): bool
    {
        foreach ($sites as $site) {
            $site = strtolower(trim((string) $site));
            if ($site !== '' && ($host === $site || str_ends_with($host, '.' . $site))) {
                return true;
            }
        }

        return false;
    }

    /** `conversations.unfurl`: null for none; the allowed and refused sites otherwise. @return array<string, mixed>|null */
    private static function config(): ?array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['conversations']['unfurl'] ?? true) : true;

        return $config === false ? null : (is_array($config) ? $config : []);
    }

    /** This application's own hosts — its address, and the request's — whose links are not read. @return list<string> */
    private static function ownHosts(): array
    {
        return array_values(array_filter([strtolower((string) parse_url(defined('sURL') ? (string) \sURL : '', PHP_URL_HOST)),
            strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')))]));
    }

    /** Where the pictures are kept: beside the conversations' files. */
    private static function dir(): string
    {
        return Files::root() . '/previews';
    }

    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('pramnos.link_previews');
    }
}
