<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Pagination;

/**
 * Pagination links from a page count, a current page and a URL pattern.
 *
 * Requested as FW-025: `QueryBuilder` already covers the query side — `forPage()`,
 * `limit()`, `offset()` — and nothing turned "page 3 of 17" into links, so every
 * application wrote that loop again.
 *
 * Four of these tests exist because of defects in the implementation being replaced, and
 * all four were in its *output*, which is why they survived: a stray quote in the opening
 * tag, a per-item container opened at both ends instead of closed, `alt` on an anchor, and
 * a URL pattern mutated in place so a second render doubled the placeholder.
 */
#[CoversClass(Pagination::class)]
class PaginationTest extends TestCase
{
    /**
     * The pattern the request was made for.
     *
     * A SEO path with `:page`, a container class, image markup for previous, and first/last
     * suppressed.
     */
    public function testTheDocumentedPatternRenders(): void
    {
        // Arrange
        $pagination = new Pagination(17, 4, '/genres/p/:page');
        $pagination->containerElementClass = 'results-pages';
        $pagination->previousButtonText    = '<img src="/img/prev.svg" alt="Previous" />';
        $pagination->displayFirstLast      = false;

        // Act
        $html = $pagination->render();

        // Assert
        $this->assertStringContainsString('<div class="results-pages">', $html);
        $this->assertStringContainsString('href="/genres/p/3"', $html);
        $this->assertStringContainsString('<img src="/img/prev.svg" alt="Previous" />', $html);
        $this->assertStringContainsString('href="/genres/p/4" class="current"', $html);

        // displayFirstLast off, so nothing jumps to page 1 or 17 *as a first/last button*
        $this->assertStringNotContainsString('&laquo;&laquo;', $html);
        $this->assertStringNotContainsString('&raquo;&raquo;', $html);
    }

    /**
     * The opening tag is well-formed with no class set.
     *
     * The implementation this replaces built it as `'<' . $element . $class . '">'`, so
     * with no class it emitted `<span">` — a stray quote inside the tag. Markup that
     * browsers forgive, which is why nobody found it.
     */
    public function testTheOpeningTagIsWellFormedWithoutAClass(): void
    {
        // Act
        $html = (new Pagination(3, 1, '/x/:page'))->render();

        // Assert
        $this->assertStringStartsWith('<div>', $html);
        $this->assertStringNotContainsString('<div"', $html);
    }

    /**
     * A per-item container is closed, not opened twice.
     *
     * The legacy emitted the opening tag at both ends, so a `<ul>` of pages nested each
     * page inside the previous one instead of listing them.
     */
    public function testAPerItemContainerIsClosed(): void
    {
        // Arrange
        $pagination = new Pagination(3, 2, '/x/:page');
        $pagination->containerElement     = 'ul';
        $pagination->pageContainerElement = 'li';

        // Act
        $html = $pagination->render();

        // Assert — as many closings as openings.
        $this->assertSame(
            substr_count($html, '<li'),
            substr_count($html, '</li>'),
            'every <li> must be closed, or the list nests instead of listing'
        );
        $this->assertStringContainsString('</ul>', $html);
    }

    /**
     * Rendering twice gives the same thing.
     *
     * The legacy appended `/:page` to its own `$url` property inside `render()`, so a
     * second call produced `/items/:page/:page`. And the second call is the ordinary one:
     * a template that shows a pager above and below a list.
     */
    public function testRenderingTwiceIsIdempotent(): void
    {
        // Arrange — no placeholder, so the appending path runs.
        $pagination = new Pagination(3, 2, '/items');

        // Act
        $first  = $pagination->render();
        $second = $pagination->render();

        // Assert
        $this->assertSame($first, $second);
        $this->assertStringNotContainsString(':page', $second);
        $this->assertStringContainsString('href="/items/3"', $second);
    }

    /**
     * No `alt` attribute on the anchors, and an accessible name instead.
     *
     * `alt` is not valid on `<a>`. The links whose label is an image or an ellipsis would
     * otherwise have no accessible name at all — a screen reader reads the URL.
     */
    public function testLinksAreLabelledWithoutAnInvalidAttribute(): void
    {
        // Act
        $html = (new Pagination(5, 3, '/x/:page'))->render();

        // Assert
        $this->assertStringNotContainsString('<a alt=', $html);
        $this->assertStringContainsString('aria-label="Page 3"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    /**
     * A single page renders nothing at all.
     *
     * One page is not a paginated result, and an empty container is something a stylesheet
     * still puts margins around.
     */
    public function testASinglePageRendersNothing(): void
    {
        // Act & Assert
        $this->assertSame('', (new Pagination(1, 1, '/x/:page'))->render());
    }

    /**
     * A pattern with no `:page` gets it appended, once.
     */
    public function testAPatternWithoutThePlaceholderGetsOne(): void
    {
        // Act
        $html = (new Pagination(3, 1, '/items/'))->render();

        // Assert — and no double slash from the trailing one.
        $this->assertStringContainsString('href="/items/2"', $html);
        $this->assertStringNotContainsString('//', $html);
    }

    /**
     * A query-string pattern works too.
     *
     * Substitution rather than path building, so a listing that has to paginate with a
     * parameter is not excluded.
     */
    public function testAQueryStringPatternWorks(): void
    {
        // Act
        $html = (new Pagination(3, 1, '/search?q=x&amp;page=:page'))->render();

        // Assert
        $this->assertStringContainsString('page=2', $html);
    }

    /**
     * `firstPageUrl` gives page 1 its own address.
     *
     * `/genres` rather than `/genres/p/1`. Two URLs for one page is duplicate content as
     * far as a crawler is concerned, which is the whole reason these are paths.
     */
    public function testTheFirstPageCanHaveItsOwnUrl(): void
    {
        // Arrange
        $pagination = new Pagination(5, 3, '/genres/p/:page');
        $pagination->firstPageUrl = '/genres';

        // Act
        $html = $pagination->render();

        // Assert
        $this->assertStringContainsString('href="/genres"', $html);
        $this->assertStringNotContainsString('/genres/p/1', $html);
    }

    /**
     * Both ends are always reachable, and elision is only claimed when true.
     *
     * The point of a numbered pager over previous/next alone is one click to either end.
     * And `1 … 2` would be a lie about a page that is right there, so the dots appear only
     * when something is actually hidden.
     */
    public function testBothEndsAreReachableAndDotsAreHonest(): void
    {
        // Act — a long list, current page in the middle.
        $middle = (new Pagination(50, 25, '/x/:page'))->render();

        // Assert
        $this->assertStringContainsString('href="/x/1"', $middle);
        $this->assertStringContainsString('href="/x/50"', $middle);
        $this->assertSame(2, substr_count($middle, '&hellip;'), 'elided on both sides');

        // Act — a window that reaches the start, so nothing is hidden on the left.
        $pagination = new Pagination(50, 3, '/x/:page');
        $pagination->adjacents = 2;
        $near = $pagination->render();

        // Assert — page 1 is inside the window, so no dots before it.
        $this->assertSame(1, substr_count($near, '&hellip;'));
    }

    /**
     * The pinned first/last page numbers can be turned off.
     *
     * Requested as FW-026, with a count: a consuming application sets the equivalent flag
     * off in 12 of 13 places, while leaving the first/last **buttons** on in 9 of them.
     * The default does not change — one click to either end is the point of a numbered
     * pager — but always-on made an upgrade a silent product decision on twelve pages,
     * and the alternative was keeping 367 lines of the class this replaces for two links.
     */
    public function testTheEdgePageNumbersCanBeTurnedOff(): void
    {
        // Arrange — a middle page, far from both ends, and only the numbers rendered.
        $pagination = new Pagination(20, 10, '/p/:page');
        $pagination->displayEdgePages    = false;
        $pagination->displayNextPrevious = false;
        $pagination->displayFirstLast    = false;

        // Act
        $html = $pagination->render();

        // Assert — the window is there and the ends are not.
        $this->assertStringContainsString('href="/p/8"', $html);
        $this->assertStringContainsString('href="/p/12"', $html);
        $this->assertStringNotContainsString('href="/p/1"', $html);
        $this->assertStringNotContainsString('href="/p/20"', $html);
        // Still elided on both sides: pages *are* hidden, so the dots are true.
        $this->assertSame(2, substr_count($html, '&hellip;'));
    }

    /**
     * With the edges off, the ellipsis threshold moves by one.
     *
     * The subtle half of FW-026, and the reason this is not simply two `if`s. With `1` on
     * screen a gap exists from page 3, so `1 … 2` would be a lie. With `1` *not* on
     * screen a gap exists from page 2, and omitting the dots there would hide the fact
     * that page 1 exists at all.
     */
    public function testTheEllipsisThresholdFollowsTheSetting(): void
    {
        // Arrange — page 3 with two adjacents: the window starts at page 1, so nothing
        // is hidden on the left in either mode.
        $nothingHidden = new Pagination(20, 3, '/p/:page');
        $nothingHidden->displayEdgePages    = false;
        $nothingHidden->displayNextPrevious = false;
        $nothingHidden->displayFirstLast    = false;

        // Page 4: the window starts at 2, so page 1 is hidden — and with the edges off
        // there is nothing else on screen to say so.
        $oneHidden = new Pagination(20, 4, '/p/:page');
        $oneHidden->displayEdgePages    = false;
        $oneHidden->displayNextPrevious = false;
        $oneHidden->displayFirstLast    = false;

        // Act
        $withoutGap = $nothingHidden->render();
        $withGap    = $oneHidden->render();

        // Assert — one set of dots (the right-hand side) versus two.
        $this->assertSame(1, substr_count($withoutGap, '&hellip;'), 'nothing is hidden on the left');
        $this->assertSame(2, substr_count($withGap, '&hellip;'), 'page 1 is hidden and nothing else says so');
    }

    /**
     * The first/last buttons are independent of the edge numbers.
     *
     * The combination the request is actually for: no `1 … … 20` in the number row, but
     * « and » still present, so both ends stay one click away while the row keeps a
     * constant width as the reader moves through it.
     */
    public function testTheEdgeNumbersAndTheFirstLastButtonsAreIndependent(): void
    {
        // Arrange
        $pagination = new Pagination(20, 10, '/p/:page');
        $pagination->displayEdgePages = false;
        $pagination->displayFirstLast = true;

        // Act
        $html = $pagination->render();

        // Assert — the buttons reach the ends even though no number does.
        $this->assertStringContainsString('&laquo;&laquo;', $html);
        $this->assertStringContainsString('&raquo;&raquo;', $html);
        $this->assertStringContainsString('href="/p/1"', $html, 'the first button still links to page 1');
        $this->assertStringContainsString('aria-label="Page 1"', $html);
    }

    /**
     * The default is unchanged.
     *
     * Stated as a test because the request explicitly did not ask for the default to
     * change, and a new flag is exactly where a default quietly flips.
     */
    public function testTheEdgePagesAreShownByDefault(): void
    {
        // Act
        $html = (new Pagination(20, 10, '/p/:page'))->render();

        // Assert
        $this->assertTrue((new Pagination(20, 10, '/p/:page'))->displayEdgePages);
        $this->assertStringContainsString('href="/p/1"', $html);
        $this->assertStringContainsString('href="/p/20"', $html);
    }

    /**
     * Previous and next disappear at the ends.
     *
     * A "previous" on page 1 either links to page 1 or to page 0. Both are worse than
     * absent.
     */
    public function testPreviousAndNextDisappearAtTheEnds(): void
    {
        // Act
        $first = (new Pagination(5, 1, '/x/:page'))->render();
        $last  = (new Pagination(5, 5, '/x/:page'))->render();

        // Assert
        $this->assertStringNotContainsString('&laquo;', $first, 'no previous on page 1');
        $this->assertStringNotContainsString('&raquo;', $last, 'no next on the last page');
        $this->assertStringContainsString('&raquo;', $first);
        $this->assertStringContainsString('&laquo;', $last);
    }

    /**
     * An out-of-range current page is clamped, not rendered.
     *
     * Page numbers arrive from a URL, so they arrive wrong. Page 0 and page 99 of 5 both
     * have to produce a usable pager rather than a negative link or a window past the end.
     */
    public function testAnOutOfRangePageIsClamped(): void
    {
        // Act
        $low  = new Pagination(5, 0, '/x/:page');
        $high = new Pagination(5, 99, '/x/:page');

        // Assert
        $this->assertSame(1, $low->page);
        $this->assertSame(5, $high->page);
        $this->assertStringNotContainsString('/x/0', $low->render());
        $this->assertStringNotContainsString('/x/6', $high->render());
    }

    /**
     * The container class and the URL are escaped; the button markup is not.
     *
     * The one asymmetry in the class, and it is deliberate: the button properties exist to
     * hold an `<img>`. That makes them the only place a caller's string reaches the page
     * unfiltered, which is worth a test so nobody "fixes" it later and breaks every arrow
     * icon.
     */
    public function testUrlsAreEscapedAndButtonMarkupIsNot(): void
    {
        // Arrange
        $pagination = new Pagination(3, 2, '/x/:page?a="b');
        $pagination->containerElementClass = 'a"b';
        $pagination->nextButtonText = '<img src="/n.svg" alt="Next" />';

        // Act
        $html = $pagination->render();

        // Assert
        $this->assertStringContainsString('class="a&quot;b"', $html);
        $this->assertStringContainsString('&quot;b', $html, 'the URL is escaped');
        $this->assertStringContainsString('<img src="/n.svg" alt="Next" />', $html);
    }

    /**
     * `echo $pagination` renders it.
     */
    public function testItRendersWhenCastToString(): void
    {
        // Arrange
        $pagination = new Pagination(3, 2, '/x/:page');

        // Act & Assert
        $this->assertSame($pagination->render(), (string) $pagination);
    }
}
