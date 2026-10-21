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
     * With the header the document is still `html` — so a view finds its `.html.php` template;
     * as `raw` every framework screen looked for `history.raw.php` and answered 0 bytes — and it
     * renders the view's output with nothing around it: a scaffold view (queue/history) comes
     * back as itself, without `<html>`, a head or the theme.
     */
    public function testAFragmentIsTheViewWithoutTheShell(): void
    {
        // Arrange — the queue's statistics view, rendered as a controller would render it
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';
        $view = new \stdClass();
        $view->history = [];
        $view->hours   = 48;
        $file = dirname(__DIR__, 4) . '/scaffolding/themes/tailwind/views/queue/history.html.php';
        $run  = \Closure::bind(function () use ($file): void {
            include $file;
        }, $view, null);
        ob_start();
        $run();
        $body = (string) ob_get_clean();

        // Act
        $document = Document::getInstance();
        $document->setContent($body);
        $html = $document->render();

        // Assert
        $this->assertSame('html', $document->type, 'a view would look for a template of another type');
        $this->assertStringContainsString('last 48 hours', $html, 'the view\'s content is missing');
        $this->assertSame(trim($body), trim($html), 'something went around the fragment');
        $this->assertStringNotContainsString('<html', $html);
    }

    /**
     * Without the header the same request is a whole page.
     */
    public function testWithoutTheHeaderItIsAWholePage(): void
    {
        // Act
        $document = Document::getInstance();

        // Assert
        $this->assertFalse(Document::isFragmentRequest());
        $this->assertSame('html', $document->type);
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
     * The dialog is centred: Tailwind's preflight sets every element's margin to 0, which
     * cancels the browser's `dialog:modal { margin: auto }`, so each theme puts it back.
     */
    public function testEveryThemeCentresTheDialog(): void
    {
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            // Arrange
            $css = (string) file_get_contents(dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/components.css');

            // Act
            preg_match('/^\.pf-modal \{([^}]*)\}/m', $css, $rule);

            // Assert
            $this->assertStringContainsString('margin: auto', $rule[1] ?? '', $theme . ' leaves the dialog in a corner');
        }
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
