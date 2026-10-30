<?php

declare(strict_types=1);

namespace Pramnos\Llm\Web;

use Pramnos\Http\Client;

/**
 * The web, for a model: `web_search` ({@see Search}) and `fetch_url` ({@see page()}) — offered in
 * a person's conversations with `'llm' => ['chat' => ['web' => true]]`, in the `web` skill.
 *
 * **What it may fetch.** An address the model chose is a user-supplied URL: fetched with
 * {@see Client::forUserSuppliedUrl()}, so every hop must resolve to a public address, the address
 * is pinned against DNS rebinding, and nothing on the server's own network — `169.254.169.254`
 * included — is reached. At most {@see BYTES}, in {@see TIMEOUT} seconds.
 *
 * **What it reads.** An HTML page's own words ({@see PageText}); plain text, JSON, XML and Markdown
 * as they are. Anything else — a PDF, a picture — is said not to be read.
 *
 * **A page that refuses servers.** Many answer a server with a challenge (403). With
 * `'llm' => ['web' => ['reader' => true]]` such a page is asked again through a reader that fetches
 * from its own servers ({@see READER}; `reader_key` for its paid rate) — off by default, because it
 * tells a third party which address was read. An address the guard refused is never sent there.
 *
 * Both tools' results are somebody else's words: `untrusted`, so the model is told they are data
 * and writes wait for the person after them. Both run at once with others (`parallel`).
 */
final class Web
{
    public const BYTES   = 3_000_000;
    public const TIMEOUT = 15;

    /** What a page answers at most, in characters. */
    public const TEXT = 20_000;

    public const READER = 'https://r.jina.ai/';

    public const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    /** Types read as text, as they are. */
    private const PLAIN = ['text/plain', 'application/json', 'text/markdown', 'application/xml', 'text/xml', 'text/csv'];

    public function __construct(private readonly Search $search = new Search())
    {
    }

    /** Whether the application offers the web in conversations. */
    public static function on(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['web']);
    }

    /**
     * A page's title and words, or why not.
     *
     * @return array{title: string, text: string, error: string}
     */
    public function page(string $url): array
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url) !== 1) {
            return ['title' => '', 'text' => '', 'error' => 'that is not a web address.'];
        }

        try {
            $response = Client::get($url)->forUserSuppliedUrl(true)->maxResponseBytes(self::BYTES)->timeout(self::TIMEOUT)
                ->header('User-Agent', self::AGENT)->header('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')->send();
        } catch (\Throwable $why) {
            // Refused by the guard, or not there at all: nothing for a reader to try.
            return ['title' => '', 'text' => '', 'error' => 'it could not be fetched (' . $why->getMessage() . ').'];
        }

        if (!$response->successful()) {
            return $this->viaReader($url) ?? ['title' => '', 'text' => '', 'error' => 'it answered ' . $response->status() . '.'];
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $body = $response->body();
        if (in_array($type, self::PLAIN, true)) {
            return ['title' => '', 'text' => mb_substr(trim($body), 0, self::TEXT), 'error' => ''];
        }
        if ($type !== '' && !str_contains($type, 'html')) {
            return ['title' => '', 'text' => '', 'error' => 'it is a ' . $type . ' file, which is not read.'];
        }

        $text = PageText::fromPage($body);
        if ($text === '') {
            return $this->viaReader($url) ?? ['title' => PageText::title($body), 'text' => '', 'error' => 'the page has no words a server can read.'];
        }

        return ['title' => PageText::title($body), 'text' => mb_substr($text, 0, self::TEXT), 'error' => ''];
    }

    /**
     * `web_search` and `fetch_url`, in the `web` skill.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            'web_search' => [
                'description' => 'Search the web, or recent news. Returns titles, addresses, dates and short snippets: '
                    . 'snippets are for finding pages, not facts — read a page with fetch_url before relying on it. '
                    . 'Supports site: to search one site. Write the query in the language of the pages you want.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'query'    => ['type' => 'string'],
                    'kind'     => ['type' => 'string', 'enum' => ['web', 'news'], 'description' => 'news for recent articles; web by default'],
                    'language' => ['type' => 'string', 'description' => 'Two letters, e.g. en or el; en by default'],
                ], 'required' => ['query']],
                'run'       => function (array $a): string {
                    $found = $this->search->search((string) $a['query'], (string) ($a['kind'] ?? 'web'), (string) ($a['language'] ?? 'en'));

                    return $found === [] ? 'No results.' : implode("\n", array_map(
                        static fn (array $r, int $i): string => ($i + 1) . '. ' . $r['title'] . "\n   " . $r['link']
                            . ($r['published_at'] !== null ? "\n   " . $r['published_at'] : '')
                            . ($r['content'] !== null ? "\n   " . mb_substr($r['content'], 0, 300) : ''),
                        $found,
                        array_keys($found)
                    ));
                },
                'untrusted' => true,
                'parallel'  => true,
                'skills'    => ['web'],
            ],
            'fetch_url' => [
                'description' => 'Read a web page, by its address, and return its title and words.',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']],
                'run'       => function (array $a): string {
                    $page = $this->page((string) $a['url']);

                    return $page['error'] !== '' ? 'Error: ' . $page['error'] : trim($page['title'] . "\n\n" . $page['text']);
                },
                'untrusted' => true,
                'parallel'  => true,
                'skills'    => ['web'],
            ],
        ];
    }

    /**
     * A page through the reader, where the application turned it on; null otherwise or when it
     * has nothing either.
     *
     * @return array{title: string, text: string, error: string}|null
     */
    private function viaReader(string $url): ?array
    {
        $web = \Pramnos\Llm\Llm::config('web', []);
        if (!is_array($web) || empty($web['reader'])) {
            return null;
        }

        try {
            $request = Client::get(self::READER . $url)->timeout(self::TIMEOUT)->maxResponseBytes(self::BYTES)->header('Accept', 'text/plain');
            if (is_string($web['reader_key'] ?? null) && $web['reader_key'] !== '') {
                $request = $request->bearerToken($web['reader_key']);
            }
            $response = $request->send();
        } catch (\Throwable) {
            return null;
        }
        $body = $response->successful() ? $response->body() : '';
        $text = PageText::fromMarkdown($body);
        if ($text === '') {
            return null;
        }

        return [
            'title' => preg_match('/^Title:\s*(.+)$/m', $body, $m) === 1 ? trim($m[1]) : '',
            'text'  => mb_substr($text, 0, self::TEXT),
            'error' => '',
        ];
    }
}
