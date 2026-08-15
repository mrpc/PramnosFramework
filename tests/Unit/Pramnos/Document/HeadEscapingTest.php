<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Amp;
use Pramnos\Document\DocumentTypes\Html;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;

/**
 * Values a document type puts in the `<head>` are escaped.
 *
 * Every renderer built the head by concatenation — `content="' . $value . '"` — so a
 * value containing a double quote ended the attribute and everything after it became
 * markup. That matters because of *what* these values are: on a server-rendered page
 * the title is a record's name, the description is operator-written copy, the OG tags
 * are both. They are the strings least likely to be trusted and they were in the one
 * part of the page nobody reads.
 *
 * The tests are split three ways on purpose, because "escape the head" is easy to
 * over-apply and the damage from over-applying it is silent too:
 *
 *   - values that MUST be escaped (attributes and element text);
 *   - values that MUST NOT be escaped (`headContent`, `extraHtmlTag`, `header`) —
 *     those exist to carry markup, and escaping them breaks every application using
 *     them as documented;
 *   - input that is ALREADY escaped, which must survive unchanged, because an
 *     application doing the right thing must not be punished with `&amp;amp;`.
 */
class HeadEscapingTest extends TestCase
{
    /**
     * Gives the language the two keys every document type asks for.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!class_exists('pramnos_request')) {
            class_alias(Request::class, 'pramnos_request');
        }

        Factory::getLanguage()->addlang([
            'LangShort' => 'en',
            'CHARSET'   => 'UTF-8',
        ]);

        Request::$originalRequestNoChange = '/a-page';
        Document::_setContent('');
    }

    /**
     * Empties the shared static content buffer between tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Document::_setContent('');
    }

    /**
     * A quote in the title cannot end the `<title>` element or start a tag.
     *
     * The title is element text rather than an attribute, but it is escaped with the
     * same call: `</title><script>` inside it would otherwise close the element and
     * open a script, which is the whole attack in one string.
     *
     * @return void
     */
    public function testTitleIsEscaped(): void
    {
        // Arrange
        $doc = new Html();
        $doc->title = 'Radio </title><script>alert(1)</script>';

        // Act
        $output = $doc->render();

        // Assert — the payload is present as text, never as markup
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
    }

    /**
     * A double quote in the description cannot end the attribute.
     *
     * This is the exact shape the fix exists for: one `"` and everything after it is
     * parsed as further attributes on the `<meta>` tag.
     *
     * @return void
     */
    public function testDescriptionQuoteCannotEscapeTheAttribute(): void
    {
        // Arrange
        $doc = new Html();
        $doc->description = 'Jazz " onmouseover="alert(1)';

        // Act
        $output = $doc->render();

        // Assert — the injected attribute never becomes one
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $output);
        $this->assertStringContainsString('&quot;', $output);
    }

    /**
     * Every Open Graph slot is escaped, not only the ones with obvious user input.
     *
     * `og_url` and `og_image` are the easy ones to forget, and both are routinely
     * built from a database value plus a base URL.
     *
     * @return void
     */
    public function testEveryOpenGraphValueIsEscaped(): void
    {
        // Arrange
        $doc = new Html();
        $doc->og_title       = 'a" x="1';
        $doc->og_type        = 'b" x="2';
        $doc->og_url         = 'c" x="3';
        $doc->og_image       = 'd" x="4';
        $doc->og_site_name   = 'e" x="5';
        $doc->og_description = 'f" x="6';

        // Act
        $output = $doc->render();

        // Assert — none of the six produced a second attribute
        foreach (['x="1', 'x="2', 'x="3', 'x="4', 'x="5', 'x="6'] as $injected) {
            $this->assertStringNotContainsString(
                $injected,
                $output,
                'An og_* value escaped its attribute.'
            );
        }
    }

    /**
     * Both meta loops escape the key as well as the value.
     *
     * The key is attacker-controlled wherever `addMetaTag()` is called with anything
     * derived from input — and a test that only checked the value would pass while
     * the tag name carried the payload.
     *
     * @return void
     */
    public function testMetaTagNamesAndValuesAreBothEscaped(): void
    {
        // Arrange
        $doc = new Html();
        $doc->addMetaTag('prop" x="1', 'val" y="2');
        $doc->addMetaTag('name" x="3', 'val" y="4', true);

        // Act
        $output = $doc->render();

        // Assert
        foreach (['x="1', 'y="2', 'x="3', 'y="4'] as $injected) {
            $this->assertStringNotContainsString($injected, $output);
        }
    }

    /**
     * Content that is already escaped is left exactly as it is.
     *
     * `double_encode: false` is the reason. An application that escapes its own
     * metadata is doing the right thing; turning its `&amp;` into `&amp;amp;` would
     * make the output worse for the careful caller and better for nobody.
     *
     * @return void
     */
    public function testAlreadyEscapedInputIsNotDoubleEncoded(): void
    {
        // Arrange
        $doc = new Html();
        $doc->title = 'Rock &amp; Roll';

        // Act
        $output = $doc->render();

        // Assert — one level of encoding, not two
        $this->assertStringContainsString('<title>Rock &amp; Roll</title>', $output);
        $this->assertStringNotContainsString('&amp;amp;', $output);
    }

    /**
     * The slots that exist to carry markup still carry it.
     *
     * `headContent` sits inside the `<head>` tag, `extraHtmlTag` inside `<html>`, and
     * `header` is arbitrary head markup added by `addHeadContent()`. Escaping any of
     * them would break every application using them as documented — a regression that
     * would present as attributes and `<link>` tags appearing as visible text.
     *
     * @return void
     */
    public function testMarkupCarryingSlotsAreNotEscaped(): void
    {
        // Arrange
        $doc = new Html();
        $doc->addHeadTagContent('data-theme="dark"');
        $doc->addHeadContent('<link rel="canonical" href="https://example.com/x">');

        // Act
        $output = $doc->render();

        // Assert — both survive as markup
        $this->assertStringContainsString('data-theme="dark"', $output);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/x">',
            $output
        );
    }

    /**
     * Non-ASCII text survives escaping intact.
     *
     * A charset-blind escape would mangle multi-byte characters, and the failure only
     * shows up in the languages the developer does not read.
     *
     * @return void
     */
    public function testUnicodeIsPreserved(): void
    {
        // Arrange
        $doc = new Html();
        $doc->title = 'Ραδιόφωνο — 音楽';

        // Act
        $output = $doc->render();

        // Assert
        $this->assertStringContainsString('<title>Ραδιόφωνο — 音楽</title>', $output);
    }

    /**
     * Invalid UTF-8 becomes a replacement character rather than an empty string.
     *
     * `ENT_SUBSTITUTE` decides this. Without it `htmlspecialchars()` returns `''` for
     * the whole value, so one bad byte in a database row silently erases the entire
     * page title — a worse outcome, and far harder to trace, than a visible `<?>`.
     *
     * @return void
     */
    public function testInvalidUtf8DoesNotEraseTheWholeValue(): void
    {
        // Arrange — a lone continuation byte is not valid UTF-8
        $doc = new Html();
        $doc->title = "Radio \xB1 Station";

        // Act
        $output = $doc->render();

        // Assert — the surrounding text is still there
        $this->assertStringContainsString('Radio', $output);
        $this->assertStringContainsString('Station', $output);
    }

    /**
     * A null or array value renders as empty instead of raising.
     *
     * These slots are plain public properties with no type, so an application can put
     * anything in them. `htmlspecialchars(null)` is deprecated on PHP 8.1+ and
     * `htmlspecialchars([])` is a TypeError — a page that renders blank is bad, a page
     * that fatals while rendering its `<head>` is worse.
     *
     * @return void
     */
    public function testNullAndArrayValuesDoNotRaise(): void
    {
        // Arrange
        $doc = new Html();
        $doc->title       = null;
        $doc->description = ['unexpected'];

        // Act
        $output = $doc->render();

        // Assert
        $this->assertStringContainsString('<title></title>', $output);
        $this->assertStringContainsString('name="description" content=""', $output);
    }

    /**
     * `no-js` is on the element CSS can reach, and something turns it into `js`.
     *
     * The class was on `<head>`, which no stylesheet can match — browsers do not
     * render it and `head.no-js` selects nothing. So the standard progressive-
     * enhancement pattern, `.no-js .thing { display: none }`, never worked, and the
     * marker sat there looking as though it did.
     *
     * That was survivable while a Modernizr script was being injected (it puts its
     * own classes on `<html>`). The framework stopped injecting it and left the
     * marker behind, so a page declared `no-js` permanently — and any CSS written
     * against it hid content forever, in a browser with JavaScript fully working.
     *
     * Both halves are asserted: the class must be somewhere useful, and something
     * must flip it. Either alone is worse than neither.
     *
     * @return void
     */
    public function testNoJsIsOnTheHtmlElementAndIsFlipped(): void
    {
        // Act
        $output = (new Html())->render();

        // Assert — on <html>, where a stylesheet can match it
        $this->assertMatchesRegularExpression(
            '#<html[^>]*class="no-js"#',
            $output,
            'no-js on <head> is inert: CSS cannot match an element that is not rendered.'
        );

        // Assert — and something turns it into js, without an external file
        $this->assertStringContainsString('no-js', $output);
        $this->assertStringContainsString(
            "replace(/\bno-js\b/,'js')",
            $output,
            'A marker nothing flips is a permanent lie about the browser.'
        );
    }

    /**
     * The AMP document type got the same treatment, including its canonical link.
     *
     * AMP is the easier one to forget — it is a second renderer with the same body of
     * copied code, and `$canonical` exists only here, so it has no counterpart in the
     * HTML tests above.
     *
     * @return void
     */
    public function testAmpEscapesItsHeadAndCanonical(): void
    {
        // Arrange
        $doc = new Amp();
        $doc->title     = 'AMP </title><script>x</script>';
        $doc->canonical = 'https://example.com/" onload="alert(1)';

        // Act
        $output = $doc->render();

        // Assert
        $this->assertStringNotContainsString('<script>x</script>', $output);
        $this->assertStringNotContainsString('onload="alert(1)"', $output);
    }
}
