<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Document\Document;
use Pramnos\Html\Breadcrumb;
use Pramnos\Http\Request;

/**
 * A page asked for with `X-Pf-Fragment: 1` is its content alone.
 *
 * `pf-utils.js` opens a `data-pf-modal` link in a dialog by fetching it with that header. No screen
 * has logic of its own for it: the document the request gets is `raw` instead of `html`, so the
 * view's output goes out without the theme around it; the page cache keeps the two apart, or a
 * stored fragment would be served as the whole page; and the admin trail is left out, because a
 * page in a dialog is not where the reader is.
 */
#[CoversClass(Document::class)]
#[CoversClass(PageCache::class)]
#[CoversClass(Breadcrumb::class)]
final class FragmentRequestTest extends TestCase
{
    /**
     * A clean document registry and no header.
     */
    protected function setUp(): void
    {
        Document::reset();
        unset($_SERVER['HTTP_X_PF_FRAGMENT']);
    }

    /**
     * The same, for the next test.
     */
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_PF_FRAGMENT']);
        Document::reset();
        Request::resetInstance();
    }

    /**
     * With the header, the document is `raw` and renders the view's output with nothing around
     * it; without it, the same request gets the themed `html` document.
     */
    public function testTheHeaderMakesTheDocumentTheContentAlone(): void
    {
        // Arrange
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';

        // Act
        $fragment = Document::getInstance();
        $fragment->setContent('<table><tr><td>42</td></tr></table>');
        $html = $fragment->render();
        Document::reset();
        unset($_SERVER['HTTP_X_PF_FRAGMENT']);
        $page = Document::getInstance();

        // Assert
        $this->assertSame('raw', $fragment->type);
        $this->assertSame('<table><tr><td>42</td></tr></table>', $html, 'the theme or a head went around the fragment');
        $this->assertSame('html', $page->type, 'a plain request lost its page');
    }

    /**
     * A fragment and its page are two cache entries; a page's key is what it was.
     */
    public function testTheCacheKeepsAFragmentApartFromItsPage(): void
    {
        // Arrange
        $cache = new PageCache(['enabled' => true], new ArrayAdapter());
        $_SERVER['REQUEST_URI'] = '/admin/Queue/history';
        Request::resetInstance();
        $request = new Request();

        // Act
        $page = $cache->keyFor($request);
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';
        $fragment = $cache->keyFor($request);

        // Assert
        $this->assertNotSame($page, $fragment, 'a stored fragment would be served as the page');
    }

    /**
     * The admin trail is not drawn into a fragment, nor handed to the shell.
     */
    public function testAFragmentHasNoAdminTrail(): void
    {
        // Arrange
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';

        // Act
        $trail = Breadcrumb::admin([['Queue', '/admin/Queue'], ['Statistics', '']]);

        // Assert
        $this->assertSame('', $trail);
    }

    /**
     * Only `1` asks for a fragment: an empty or another value is a page.
     */
    public function testOnlyTheValueOneIsAFragment(): void
    {
        // Arrange / Act / Assert — each value, then its answer
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '0';
        $this->assertFalse(Document::isFragmentRequest());
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '';
        $this->assertFalse(Document::isFragmentRequest());
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';
        $this->assertTrue(Document::isFragmentRequest());
    }
}
