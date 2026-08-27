<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * Pagination links from a page count, a current page, and a URL pattern.
 *
 * ```php
 * $pagination = new \Pramnos\Html\Pagination($totalPages, $page, '/genres/p/:page');
 * $pagination->containerElementClass = 'results-pages';
 * $pagination->previousButtonText    = '<img src="/img/prev.svg" alt="Previous" />';
 * $pagination->displayFirstLast      = false;
 * echo $pagination->render();
 * ```
 *
 * ## What this is the other half of
 *
 * {@see \Pramnos\Database\QueryBuilder} already covers the query side — `forPage()`,
 * `limit()`, `offset()`. What was missing was the presentation: nothing turned "page 3 of
 * 17" into links, so every application wrote that loop again.
 *
 * ## The URL is a pattern, not a query string
 *
 * `:page` is replaced with the page number:
 *
 * ```
 * '/genres/p/:page'        ->  /genres/p/4
 * '/search?q=x&page=:page' ->  /search?q=x&page=4
 * ```
 *
 * A path pattern rather than an appended `?page=` because paginated listings are usually
 * indexable pages, and a crawler treats `/genres/p/4` as a page while it may treat
 * `?page=4` as a variant of `/genres`. A pattern with no `:page` in it gets `/:page`
 * appended — which is what the class this replaces did, except that it appended to its
 * own property, so rendering twice produced `/:page/:page`.
 *
 * ## Three defects in the implementation this replaces
 *
 * All three were in its output rather than its logic, which is why they survived:
 *
 *   - the opening container tag was built as `'<' . $element . $class . '">'`, so with no
 *     class set it emitted `<span">` — a stray quote inside the tag;
 *   - the per-page container was *opened* twice, `'<li>'` where `'</li>'` was meant, so
 *     the list nested instead of listing;
 *   - each link carried `alt=""`, which is not a valid attribute on `<a>`.
 *
 * ## Button text is markup on purpose
 *
 * `previousButtonText` and the rest are **not escaped**, because their documented use is
 * an `<img>`. That makes them the one place here where a caller's string reaches the page
 * unfiltered, so they must never be built from user input. Page numbers and URLs are
 * escaped.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class Pagination extends \Pramnos\Framework\Base
{
    /** Total number of pages. */
    public int $pages = 1;

    /** The page being displayed, 1-based. */
    public int $page = 1;

    /** URL pattern; `:page` is replaced with the page number. */
    public string $url = '';

    /** Element wrapping the whole thing. */
    public string $containerElement = 'div';

    /** Class on that element. */
    public string $containerElementClass = '';

    /** Element wrapping each link, or '' for none. */
    public string $pageContainerElement = '';

    /** Class on the link for the current page. */
    public string $currentPageClass = 'current';

    /** How many pages to show either side of the current one. */
    public int $adjacents = 2;

    /** Whether to show previous/next. */
    public bool $displayNextPrevious = true;

    /** Whether to show first/last. */
    public bool $displayFirstLast = true;

    /**
     * Whether the first and last page **numbers** are pinned beside the ellipses.
     *
     * On by default, because one click to either end is the point of a numbered pager
     * over previous/next alone. Off gives a window that is purely relative to the current
     * page — `… 8 9 [10] 11 12 …` with no `1` and no `20`.
     *
     * Distinct from {@see $displayFirstLast}, which controls the « and » **buttons**.
     * Turning these off while leaving those on is the common combination: the ends stay
     * reachable, but the number row does not change width as you move through it.
     */
    public bool $displayEdgePages = true;

    /** Markup — not escaped. See the class docblock. */
    public string $previousButtonText = '&laquo;';

    /** Markup — not escaped. */
    public string $nextButtonText = '&raquo;';

    /** Markup — not escaped. */
    public string $firstButtonText = '&laquo;&laquo;';

    /** Markup — not escaped. */
    public string $lastButtonText = '&raquo;&raquo;';

    /** Shown where a run of pages is elided. Markup — not escaped. */
    public string $dotsText = '&hellip;';

    /**
     * URL for page 1, when it is not the pattern with 1 in it.
     *
     * `/genres` rather than `/genres/p/1`. Two URLs for one page is the kind of thing a
     * crawler counts as duplicate content, so a listing whose first page has its own
     * address should say so here.
     */
    public string $firstPageUrl = '';

    /**
     * @param int    $pages Total pages
     * @param int    $page  Current page, 1-based
     * @param string $url   Pattern containing `:page`
     */
    public function __construct(int $pages = 1, int $page = 1, string $url = '')
    {
        parent::__construct();

        $this->pages = max(1, $pages);
        $this->page  = min(max(1, $page), $this->pages);
        $this->url   = $url;
    }

    /**
     * The links, as markup.
     *
     * **Nothing at all for a single page.** One page is not a paginated result, and an
     * empty container is something a stylesheet still puts margins around.
     */
    public function render(): string
    {
        if ($this->pages <= 1) {
            return '';
        }

        $class = $this->containerElementClass === ''
            ? ''
            : ' class="' . $this->attr($this->containerElementClass) . '"';

        $out = '<' . $this->containerElement . $class . '>';

        if ($this->displayFirstLast && $this->page > 1) {
            $out .= $this->link(1, $this->firstButtonText);
        }
        if ($this->displayNextPrevious && $this->page > 1) {
            $out .= $this->link($this->page - 1, $this->previousButtonText);
        }

        $out .= $this->numbers();

        if ($this->displayNextPrevious && $this->page < $this->pages) {
            $out .= $this->link($this->page + 1, $this->nextButtonText);
        }
        if ($this->displayFirstLast && $this->page < $this->pages) {
            $out .= $this->link($this->pages, $this->lastButtonText);
        }

        return $out . '</' . $this->containerElement . '>';
    }

    /** `echo $pagination;` renders it. */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * The numbered links, with runs elided around the current page.
     *
     * The first and last page are shown by default, so the reader can always reach both
     * ends in one click — which is the point of a numbered pager over previous/next
     * alone. {@see $displayEdgePages} turns that off for a design that wants a window
     * relative to the current page and nothing else.
     *
     * The ellipses stay honest either way: they appear only where a page is actually
     * hidden. Which pages *are* hidden depends on whether the edges are pinned, so the
     * threshold moves with the setting rather than being a fixed number — with `1` on
     * screen a gap exists from page 3, without it from page 2.
     */
    protected function numbers(): string
    {
        $from = max(1, $this->page - $this->adjacents);
        $to   = min($this->pages, $this->page + $this->adjacents);

        $out = '';

        if ($from > 1) {
            if ($this->displayEdgePages) {
                $out .= $this->link(1);
            }
            // `1 … 2` would be a lie about a page that is right there.
            if ($from > ($this->displayEdgePages ? 2 : 1)) {
                $out .= $this->dots();
            }
        }

        for ($page = $from; $page <= $to; $page++) {
            $out .= $this->link($page);
        }

        if ($to < $this->pages) {
            if ($to < $this->pages - ($this->displayEdgePages ? 1 : 0)) {
                $out .= $this->dots();
            }
            if ($this->displayEdgePages) {
                $out .= $this->link($this->pages);
            }
        }

        return $out;
    }

    /**
     * One link.
     *
     * The current page is still a link rather than inert text. Making it inert reads as
     * cleaner and costs the reader a way to reload the page they are on, which is what
     * they click it for.
     *
     * @param int    $page The page it goes to
     * @param string $text Markup for the label; the page number when empty
     */
    protected function link(int $page, string $text = ''): string
    {
        $label   = $text === '' ? (string) $page : $text;
        $current = $page === $this->page;

        $anchor = '<a href="' . $this->attr($this->pageUrl($page)) . '"'
            . ($current ? ' class="' . $this->attr($this->currentPageClass) . '"' : '')
            // The accessible name of a link that is an image or an ellipsis is otherwise
            // whatever the markup happens to contain, which for an <img> with no alt is
            // the URL.
            . ' aria-label="' . $this->attr('Page ' . $page) . '"'
            . ($current ? ' aria-current="page"' : '')
            . '>' . $label . '</a>';

        return $this->wrap($anchor, $current);
    }

    /** The elision marker, wrapped like a link so a list stays a list. */
    protected function dots(): string
    {
        return $this->wrap('<span>' . $this->dotsText . '</span>', false);
    }

    /**
     * Put one item inside `pageContainerElement`, when there is one.
     *
     * Closing the element it opened, which the implementation this replaces did not: it
     * emitted the opening tag at both ends, so a `<ul>` of pages nested one page inside
     * the next instead of listing them.
     */
    protected function wrap(string $inner, bool $current): string
    {
        if ($this->pageContainerElement === '') {
            return $inner;
        }

        $class = $current && $this->currentPageClass !== ''
            ? ' class="' . $this->attr($this->currentPageClass) . '"'
            : '';

        return '<' . $this->pageContainerElement . $class . '>'
            . $inner
            . '</' . $this->pageContainerElement . '>';
    }

    /**
     * The URL for a page.
     *
     * `:page` is substituted without touching {@see $url}. The class this replaces
     * appended `/:page` to its own property inside `render()`, so a second render on the
     * same object produced `/items/:page/:page` — and the second call is the ordinary one
     * in a template that shows a pager above and below a list.
     */
    protected function pageUrl(int $page): string
    {
        if ($page === 1 && $this->firstPageUrl !== '') {
            return $this->firstPageUrl;
        }

        $pattern = $this->url;
        if (!str_contains($pattern, ':page')) {
            $pattern = rtrim($pattern, '/') . '/:page';
        }

        return str_replace(':page', (string) $page, $pattern);
    }

    /** Escape for an attribute. */
    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
