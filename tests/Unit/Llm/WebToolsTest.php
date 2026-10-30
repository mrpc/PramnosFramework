<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Web\PageText;
use Pramnos\Llm\Web\Search;
use Pramnos\Llm\Web\Web;

/**
 * The web, for a model: a page's own words, a search through the providers an application has
 * keys for, and Bing's feed when none answers.
 *
 * What holds: an address on the server's own network is never fetched — and never handed to the
 * reader either; a page's article is found before its furniture; a type that is not text is said
 * not to be read; a provider that refuses passes to the next; Bing's links are the articles, not
 * its counter; and both tools' results are marked as somebody else's words.
 *
 * Fetched addresses are public IP literals: the guard resolves a name before any fake answers.
 */
#[CoversClass(Web::class)]
#[CoversClass(Search::class)]
#[CoversClass(PageText::class)]
class WebToolsTest extends LlmTestCase
{
    /** A public address, so the guard lets it through to the fake. */
    private const SITE = 'https://93.184.215.14';

    /** An article page: menus, a JSON-LD body, paragraphs. */
    private static function article(): string
    {
        $body = str_repeat('The council approved the new water plan after a long debate. ', 12);

        return '<html><head><title>Water plan approved</title><script type="application/ld+json">'
            . json_encode(['@type' => 'NewsArticle', 'articleBody' => '<p>' . $body . '</p>']) . '</script></head>'
            . '<body><nav><p>Home News Sport Weather and everything else in the menu</p></nav><p>Share</p></body></html>';
    }

    /**
     * A page's own words: the declared article first; without one, the paragraphs in <article>,
     * then any paragraphs — the short ones and the menu left out, a repeated one kept once.
     */
    public function testPageText(): void
    {
        // Arrange
        $long      = str_repeat('A paragraph of the article that is long enough to count. ', 3);
        $paragraphs = '<html><body><nav><p>' . $long . 'MENU</p></nav><article><p>' . $long . '</p><p>' . $long . '</p><p>Share</p></article></body></html>';

        // Act
        $declared = PageText::fromPage(self::article());
        $plain    = PageText::fromPage($paragraphs);

        // Assert
        $this->assertStringStartsWith('The council approved', $declared);
        $this->assertSame(trim($long), $plain, 'once, without the menu or Share');
        $this->assertSame('Water plan approved', PageText::title(self::article()));
        $this->assertSame('', PageText::fromPage(''));
        $this->assertSame("One\n\nTwo", PageText::fromFragment('<p>One</p><p>Two</p><script>x()</script>'));
        $this->assertSame('plain words', PageText::fromFragment(' plain   words '));
        $this->assertSame('', PageText::fromFragment(''));
        $this->assertSame(trim($long), PageText::fromMarkdown("Title: T\nMarkdown Content:\n\n# Heading\n\n[" . trim($long) . "](https://x.test)\n\n![pic](https://x.test/p.png)"));
    }

    /**
     * fetch_url: a page's title and words; plain text as it is; a PDF refused; a 404 said; an
     * address that is not the web refused; and one on the server's own network never fetched.
     */
    public function testFetchUrl(): void
    {
        // Arrange
        Http::fake([
            self::SITE . '/article' => ClientResponse::make(self::article(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            self::SITE . '/notes.txt' => ClientResponse::make('Just notes.', 200, ['Content-Type' => 'text/plain']),
            self::SITE . '/file.pdf' => ClientResponse::make('%PDF', 200, ['Content-Type' => 'application/pdf']),
            self::SITE . '/empty' => ClientResponse::make('<html><title>Empty</title><body><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']),
            self::SITE . '/gone' => ClientResponse::make('no', 404),
            '*' => ClientResponse::make('reached', 200, ['Content-Type' => 'text/plain']),
        ]);
        $fetch = (new Web())->tools()['fetch_url'];

        // Act / Assert
        $this->assertStringStartsWith("Water plan approved\n\nThe council approved", ($fetch['run'])(['url' => self::SITE . '/article']));
        $this->assertSame('Just notes.', ($fetch['run'])(['url' => self::SITE . '/notes.txt']));
        $this->assertSame('Error: it is a application/pdf file, which is not read.', ($fetch['run'])(['url' => self::SITE . '/file.pdf']));
        $this->assertSame('Error: the page has no words a server can read.', ($fetch['run'])(['url' => self::SITE . '/empty']));
        $this->assertSame('Error: it answered 404.', ($fetch['run'])(['url' => self::SITE . '/gone']));
        $this->assertSame('Error: that is not a web address.', ($fetch['run'])(['url' => 'file:///etc/passwd']));
        $this->assertStringStartsWith('Error: it could not be fetched', ($fetch['run'])(['url' => 'http://169.254.169.254/latest/meta-data']), 'the server\'s own network');
        $this->assertTrue($fetch['untrusted']);
        $this->assertTrue($fetch['parallel']);
        $this->assertSame(['web'], $fetch['skills']);
    }

    /**
     * A page that refuses servers is asked through the reader only where the application turned
     * it on — with its key — and an address the guard refused is never sent there.
     */
    public function testTheReader(): void
    {
        // Arrange
        $asked = [];
        Http::fake([
            Web::READER . '*' => function (Http $request) use (&$asked): ClientResponse {
                $asked[] = $request->url();

                return ClientResponse::make("Title: Blocked page\nMarkdown Content:\n\n" . str_repeat('Words the reader could read for us here. ', 3), 200);
            },
            self::SITE . '/blocked' => ClientResponse::make('challenge', 403),
        ]);
        $web = new Web();

        // Act
        $off = $web->page(self::SITE . '/blocked');
        $this->configure(['web' => ['reader' => true, 'reader_key' => 'jk']]);
        $on      = $web->page(self::SITE . '/blocked');
        $refused = $web->page('http://169.254.169.254/');

        // Assert
        $this->assertSame('it answered 403.', $off['error']);
        $this->assertSame('Blocked page', $on['title']);
        $this->assertStringStartsWith('Words the reader could read', $on['text']);
        $this->assertSame([Web::READER . self::SITE . '/blocked'], $asked, 'once, and not for the refused address');
        $this->assertNotSame('', $refused['error']);
    }

    /**
     * Search: the providers with keys in the application's order — one that refuses passes to
     * the next — each answer in one shape; without a key, Bing's feed, its links unwrapped, news
     * newest first; Bing turned off, nothing.
     */
    public function testSearch(): void
    {
        // Arrange
        $this->configure(['web' => ['search' => ['serper' => 'sk1', 'brave' => 'bk', 'nonsense' => 'x', 'tavily' => '']]]);
        Http::fake([
            'https://google.serper.dev/*' => ClientResponse::make('{"message":"Not enough credits"}', 400),
            'https://api.search.brave.com/res/v1/news/*' => ClientResponse::make(['results' => [
                ['title' => 'Plan &amp; vote', 'url' => 'https://news.test/a', 'page_age' => '2026-10-29T10:00:00Z', 'description' => '<b>The</b>   council'],
                ['title' => 'No link', 'url' => 'ftp://x'],
            ]]),
            'https://api.search.brave.com/res/v1/web/*' => ClientResponse::make(['web' => ['results' => []]]),
            'https://www.bing.com/news/search*' => ClientResponse::make('<rss><channel>'
                . '<item><title>Older</title><link>https://www.bing.com/news/apiclick.aspx?ref=1&amp;url=https%3A%2F%2Fold.test%2F</link><pubDate>Mon, 26 Oct 2026 08:00:00 GMT</pubDate><description>&lt;p&gt;old&lt;/p&gt;</description></item>'
                . '<item><title>Newer</title><link>https://new.test/</link><pubDate>Thu, 29 Oct 2026 08:00:00 GMT</pubDate><description></description></item>'
                . '</channel></rss>', 200),
            'https://www.bing.com/search*' => ClientResponse::make('not a feed', 200),
        ]);
        $search = new Search();

        // Act
        $news = $search->search('water plan', 'news');
        $web  = $search->search('water plan');
        $this->configure([]);
        $bing = $search->search('water plan', 'news', 'el', 5);
        $this->configure(['web' => ['bing' => false]]);
        $off  = $search->search('water plan', 'news');

        // Assert
        $this->assertSame(['serper' => 'sk1', 'brave' => 'bk'], (function () {
            $this->configure(['web' => ['search' => ['serper' => 'sk1', 'brave' => 'bk', 'nonsense' => 'x', 'tavily' => '']]]);

            return Search::keys();
        })());
        $this->assertSame([['title' => 'Plan & vote', 'link' => 'https://news.test/a', 'published_at' => '2026-10-29 10:00:00+00:00', 'content' => 'The council']], $news);
        $this->assertSame([], $web, 'brave found nothing on the web, and Bing\'s web answer was no feed');
        $this->assertSame(['https://new.test/', 'https://old.test/'], array_column($bing, 'link'), 'newest first, the article behind the counter');
        $this->assertSame('old', $bing[1]['content']);
        $this->assertNull($bing[0]['content']);
        $this->assertSame([], $off);
        $this->assertSame([], $search->search('   '));
    }

    /**
     * web_search as the model reads it: numbered, with address, date and snippet; "No results."
     * when there are none.
     */
    public function testTheSearchTool(): void
    {
        // Arrange
        $this->configure(['web' => ['search' => ['tavily' => 'tk'], 'bing' => false]]);
        Http::fake(['https://api.tavily.com/*' => ClientResponse::make(['results' => [
            ['title' => 'A', 'url' => 'https://a.test/', 'published_date' => null, 'content' => 'About A'],
        ]])]);
        $tool = (new Web())->tools()['web_search'];

        // Act
        $found = ($tool['run'])(['query' => 'a']);
        Http::fake(['*' => ClientResponse::make(['results' => []])]);
        $none = ($tool['run'])(['query' => 'b', 'kind' => 'news']);

        // Assert
        $this->assertSame("1. A\n   https://a.test/\n   About A", $found);
        $this->assertSame('No results.', $none);
        $this->assertTrue($tool['untrusted']);
        $this->assertFalse(Web::on());
        $this->configure(['chat' => ['web' => true]]);
        $this->assertTrue(Web::on());
    }

    /** Every provider's request goes where its API is, and a transport failure passes on. */
    public function testEveryProvidersRequest(): void
    {
        // Arrange
        $this->configure(['web' => ['search' => ['exa' => 'e', 'jina' => 'j'], 'bing' => false]]);
        $urls = [];
        Http::fake([
            'https://api.exa.ai/*' => function (Http $request) use (&$urls): ClientResponse {
                $urls[] = $request->url();
                throw new \Pramnos\Http\ClientException('down');
            },
            'https://s.jina.ai/*' => function (Http $request) use (&$urls): ClientResponse {
                $urls[] = $request->url();

                return ClientResponse::make(['data' => [['title' => 'J', 'url' => 'https://j.test/', 'date' => 'not a date']]]);
            },
        ]);

        // Act
        $found = (new Search())->search('q', 'news', 'Greek');

        // Assert
        $this->assertSame('https://api.exa.ai/search', $urls[0]);
        $this->assertStringStartsWith('https://s.jina.ai/?q=q&hl=en', $urls[1], 'a language that is not two letters is English');
        $this->assertSame([['title' => 'J', 'link' => 'https://j.test/', 'published_at' => null, 'content' => null]], $found);
        $this->assertSame('https://plain.test/', Search::unwrap('https://plain.test/'));
        $this->assertSame('https://www.bing.com/x', Search::unwrap('https://www.bing.com/x'));
    }
}
