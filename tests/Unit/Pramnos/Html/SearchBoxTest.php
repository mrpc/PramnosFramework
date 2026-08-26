<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\SearchBox;

/**
 * The markup half of the cross-entity search box.
 *
 * The behaviour lives in `pf-utils.js` and the results come from
 * {@see \Pramnos\Search\Registry}; this class only emits a tag and the data attributes
 * that configure the handler. So the tests are about the contract between the two — a
 * renamed attribute here is a silently dead search box — and about the ARIA wiring, which
 * is the one part that cannot be verified by looking at the page.
 */
#[CoversClass(SearchBox::class)]
class SearchBoxTest extends TestCase
{
    /**
     * The configuration the handler reads is all present.
     *
     * Each of these is looked up by name in `pf-utils.js`. A typo in either place gives a
     * box that renders, focuses, accepts typing and never sends a request — which looks
     * like a server problem.
     */
    public function testEveryAttributeTheHandlerReadsIsEmitted(): void
    {
        // Arrange
        $box = new SearchBox('/api/1.0/admin/search');
        $box->minimumCharacters = 3;
        $box->debounce          = 400;

        // Act
        $html = $box->render();

        // Assert
        $this->assertStringContainsString('data-pf-omnibox ', $html);
        $this->assertStringContainsString('data-pf-omnibox-url="/api/1.0/admin/search"', $html);
        $this->assertStringContainsString('data-pf-omnibox-min="3"', $html);
        $this->assertStringContainsString('data-pf-omnibox-debounce="400"', $html);
        $this->assertStringContainsString('data-pf-omnibox-loading="Searching…"', $html);
        $this->assertStringContainsString('data-pf-omnibox-empty="No results"', $html);
    }

    /**
     * The combobox is wired to its own popup by id.
     *
     * The reason this class defaults an id while {@see \Pramnos\Html\Input} and
     * {@see \Pramnos\Html\Select} refuse to invent one: `aria-controls` is an association
     * by id, and without it a screen reader has a text field and an unrelated list rather
     * than a combobox.
     */
    public function testTheComboboxIsWiredToItsPopup(): void
    {
        // Act
        $html = (new SearchBox('/s'))->render();

        // Assert
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-controls="pf-omnibox-results"', $html);
        $this->assertStringContainsString('id="pf-omnibox-results"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        // Closed until the handler opens it, and announced as closed.
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    /**
     * A custom id renames every associated element together.
     *
     * Two boxes on one page is the case this exists for, and half-renamed ids would point
     * the second box's input at the first box's results panel.
     */
    public function testACustomIdRenamesTheWholeWidget(): void
    {
        // Arrange
        $box = new SearchBox('/s');
        $box->id = 'header-search';

        // Act
        $html = $box->render();

        // Assert
        $this->assertStringContainsString('id="header-search"', $html);
        $this->assertStringContainsString('id="header-search-input"', $html);
        $this->assertStringContainsString('for="header-search-input"', $html);
        $this->assertStringContainsString('aria-controls="header-search-results"', $html);
        $this->assertStringContainsString('id="header-search-results"', $html);
    }

    /**
     * The panel starts closed with `hidden`, not with a class.
     *
     * A class needs the stylesheet to have loaded. `hidden` is honoured before any CSS
     * arrives, which is the difference between a clean first paint and a results panel
     * that flashes open on every page load.
     */
    public function testThePanelStartsClosedWithoutNeedingCss(): void
    {
        // Act & Assert
        $this->assertStringContainsString('role="listbox" aria-label="Search" hidden>', (new SearchBox('/s'))->render());
    }

    /**
     * The accessible name is a real label, not an `aria-label`.
     *
     * So it goes through the same translation path as the rest of the page. An
     * `aria-label` is invisible to a translation tool that only reads element text, and
     * the result is a Greek interface whose search box announces itself in English.
     */
    public function testTheAccessibleNameIsALabelElement(): void
    {
        // Arrange
        $box = new SearchBox('/s');
        $box->label = 'Αναζήτηση';

        // Act
        $html = $box->render();

        // Assert
        $this->assertStringContainsString('<label class="pf-omnibox-label" for="pf-omnibox-input">Αναζήτηση</label>', $html);
        $this->assertStringNotContainsString('aria-label="Search"', $html);
    }

    /**
     * It renders no script and no stylesheet.
     *
     * The same rule as {@see \Pramnos\Html\Input}: an element that pushes assets into the
     * document while rendering itself makes echoing a search box change the page's asset
     * list, and the framework's CSP would then need a nonce per widget.
     */
    public function testItContributesNoAssets(): void
    {
        // Act
        $html = (new SearchBox('/s'))->render();

        // Assert
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * Everything a caller supplies is escaped.
     *
     * The endpoint and the labels are configuration, but configuration is read from
     * `app.php` and from translation files, and both are strings somebody edits.
     */
    public function testCallerSuppliedValuesAreEscaped(): void
    {
        // Arrange
        $box = new SearchBox('/s?a="b');
        $box->placeholder = '" autofocus';
        $box->emptyText   = '<script>x</script>';

        // Act
        $html = $box->render();

        // Assert
        $this->assertStringNotContainsString('" autofocus', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * A nonsensical minimum or debounce is clamped rather than emitted.
     *
     * `min="0"` would make the box query on focus, before a single character — a request
     * per registered entity for the empty string.
     */
    public function testNonsensicalNumbersAreClamped(): void
    {
        // Arrange
        $box = new SearchBox('/s');
        $box->minimumCharacters = 0;
        $box->debounce          = -100;

        // Act
        $html = $box->render();

        // Assert
        $this->assertStringContainsString('data-pf-omnibox-min="1"', $html);
        $this->assertStringContainsString('data-pf-omnibox-debounce="0"', $html);
    }

    /**
     * The default endpoint uses the application's own API prefix.
     *
     * A project scaffolded under `/v1` would otherwise get a box pointing at
     * `/api/1.0/admin/search`, and a 404 on a search box reads as "search is broken".
     */
    public function testTheDefaultEndpointFollowsTheConfiguredApiPrefix(): void
    {
        // Act — no application is constructed in a unit test, so the scaffolder's own
        // default is what must come back. Anything else (an empty prefix, say) would
        // build a path no scaffolded project registers.
        $url = SearchBox::defaultUrl();

        // Assert
        $this->assertStringEndsWith('/admin/search', $url);
        $this->assertStringStartsWith('/', $url);
    }

    /**
     * `echo $box` renders it.
     */
    public function testItRendersWhenCastToString(): void
    {
        // Arrange
        $box = new SearchBox('/s');

        // Act & Assert
        $this->assertSame($box->render(), (string) $box);
    }
}
