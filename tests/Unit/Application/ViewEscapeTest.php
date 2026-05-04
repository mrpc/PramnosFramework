<?php

namespace Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\View;

/**
 * Tests for the global e() helper and View::escape() / View::e().
 *
 * The helpers cover the global function defined in helpers.php (loaded via
 * composer autoload files) and the View instance methods that proxy it.
 */
#[CoversClass(View::class)]
class ViewEscapeTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeView(): View
    {
        // View requires a Controller in its constructor; create a minimal stub.
        $ctrl = $this->createStub(\Pramnos\Application\Controller::class);
        return new View($ctrl, '', 'stub', 'html');
    }

    // =========================================================================
    // Global e() helper — basic escaping
    // =========================================================================

    /**
     * The most important invariant: characters with special HTML meaning
     * (<, >, &, ", ') must be replaced by their HTML entity equivalents.
     * Without this, any user-supplied string echoed into a template is an
     * XSS vector.
     */
    public function testEscapesHtmlSpecialCharacters(): void
    {
        // Arrange
        $input = '<script>alert("XSS")</script>';

        // Act
        $output = e($input);

        // Assert — no raw angle brackets or unescaped quotes
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringContainsString('&quot;', $output);
    }

    /**
     * Single quotes must also be escaped (ENT_QUOTES).
     * Single-quoted HTML attributes are common and must be protected.
     */
    public function testEscapesSingleQuotes(): void
    {
        // Arrange / Act
        $output = e("it's a trap");

        // Assert
        $this->assertStringNotContainsString("'", $output);
        $this->assertStringContainsString('&#039;', $output);
    }

    /**
     * Ampersands must be escaped to &amp;.
     * Unescaped ampersands break HTML attributes and create entity injection.
     */
    public function testEscapesAmpersands(): void
    {
        // Arrange / Act
        $output = e('AT&T');

        // Assert
        $this->assertStringNotContainsString('&T', $output);  // raw & gone
        $this->assertStringContainsString('&amp;', $output);
    }

    /**
     * A plain alphanumeric string with no special characters must pass
     * through unchanged — over-escaping breaks non-special output.
     */
    public function testPlainStringPassesThroughUnchanged(): void
    {
        // Arrange / Act
        $output = e('Hello World 123');

        // Assert
        $this->assertSame('Hello World 123', $output);
    }

    // =========================================================================
    // Global e() — edge-case input types
    // =========================================================================

    /**
     * null must produce an empty string, not "NULL" or a PHP notice.
     * Templates frequently pass nullable model properties to e().
     */
    public function testNullReturnsEmptyString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('', e(null));
    }

    /**
     * false must produce an empty string.  false is used as a "no value"
     * sentinel in many framework methods.
     */
    public function testFalseReturnsEmptyString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('', e(false));
    }

    /**
     * true must produce the string '1' (PHP's cast of true → (string)).
     * This is consistent with how PHP itself would echo the value.
     */
    public function testTrueReturnsOne(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('1', e(true));
    }

    /**
     * Integers must be returned as-is (no HTML entities in integers).
     * Numbers are the most common non-string type echoed in templates.
     */
    public function testIntegerReturnedAsString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('42', e(42));
        $this->assertSame('-7', e(-7));
        $this->assertSame('0', e(0));
    }

    /**
     * Floats must be returned as their string representation.
     */
    public function testFloatReturnedAsString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('3.14', e(3.14));
    }

    /**
     * An empty string must return an empty string — not null, not false.
     */
    public function testEmptyStringReturnsEmptyString(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('', e(''));
    }

    // =========================================================================
    // XSS vector coverage
    // =========================================================================

    /**
     * Common attribute-injection XSS vector: onerror=" payload ".
     * The double-quote around the payload must be escaped.
     */
    public function testAttributeInjectionVectorIsNeutralised(): void
    {
        // Arrange
        $input = '" onerror="alert(1)';

        // Act
        $output = e($input);

        // Assert — the raw quote that would break the attribute is gone
        $this->assertStringNotContainsString('"', $output);
        $this->assertStringContainsString('&quot;', $output);
    }

    /**
     * JavaScript URI in an href: javascript:alert(1).
     * Note: e() does NOT filter javascript: URIs — that is a policy decision
     * that belongs at the attribute-assignment level, not in a general-purpose
     * escaper.  This test documents the expected (non-filtering) behaviour.
     */
    public function testJavascriptUriIsNotFiltered(): void
    {
        // Arrange — the escaper escapes HTML chars but NOT URI schemes
        $input = 'javascript:alert(1)';

        // Act
        $output = e($input);

        // Assert — no HTML chars in this particular string, so it passes through
        $this->assertSame('javascript:alert(1)', $output);
        // Developer must not use e() for href values that accept arbitrary URLs;
        // use a URL whitelist or CSP instead.
    }

    // =========================================================================
    // View::escape() and View::e()
    // =========================================================================

    /**
     * View::escape() must produce the same result as the global e() helper.
     * It exists so templates that use $this-> can call it without importing
     * the global function.
     */
    public function testViewEscapeMatchesGlobalHelper(): void
    {
        // Arrange
        $view  = $this->makeView();
        $input = '<b>Hello & "World"</b>';

        // Act
        $viaView   = $view->escape($input);
        $viaGlobal = e($input);

        // Assert
        $this->assertSame($viaGlobal, $viaView);
    }

    /**
     * View::e() is a shorter alias for View::escape() — must produce
     * identical output to confirm the delegation is wired correctly.
     */
    public function testViewEAliasMatchesEscape(): void
    {
        // Arrange
        $view  = $this->makeView();
        $input = "it's <b>bold</b> & \"important\"";

        // Act / Assert
        $this->assertSame($view->escape($input), $view->e($input));
    }

    /**
     * View::escape() with a non-default encoding parameter must forward
     * the encoding to htmlspecialchars() via the global e().
     */
    public function testViewEscapeForwardsEncoding(): void
    {
        // Arrange
        $view  = $this->makeView();
        $input = '<tag>';

        // Act — ISO-8859-1 encoding (rarely used but supported)
        $output = $view->escape($input, 'ISO-8859-1');

        // Assert — angle brackets are still escaped regardless of encoding
        $this->assertStringNotContainsString('<tag>', $output);
        $this->assertStringContainsString('&lt;tag&gt;', $output);
    }
}
