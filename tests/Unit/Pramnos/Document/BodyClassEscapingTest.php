<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * The `class` attribute on `<body>` cannot be closed by a class name.
 *
 * Every value in `<head>` has been escaped since the pass that followed a consumer report
 * about station names and administrator text ending attributes early. The body class list was
 * **missed in that pass**, because it looked only at head values — and it is the same defect:
 * `addBodyClass()` is reasonably fed a slug, a content type, or a user's chosen theme name.
 *
 * The other half of this class is about a comment. `addBodyClass()` carried a
 * `@todo Use bodyclasses` for years while both renderers printed the list all along, and a
 * consuming project read the note and concluded the feature was half-built. A stale `@todo`
 * is read as a statement about the present, so the tests below assert the behaviour the note
 * denied.
 */
class BodyClassEscapingTest extends TestCase
{
    /**
     * Clears the shared content buffer, which is process-wide.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Document::_setContent('');
    }

    /**
     * And leaves it clear.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Document::_setContent('');
    }

    /**
     * A rendered document of a given type, with no theme in the way.
     *
     * @param  string $type `html` or `amp`
     * @return Document
     */
    private function document(string $type): Document
    {
        $document = Document::getInstance($type);
        $document->themeObject = null;
        $document->bodyclasses = [];
        $document->extraBodyTag = '';

        return $document;
    }

    /**
     * Classes are emitted space-separated.
     *
     * The behaviour the stale `@todo` denied. Also worth pinning because the variable that
     * joined them was named `$comma` while holding a space — enough to make a reader check
     * whether the framework was producing `class="a,b"`, which no browser would honour.
     *
     * @return void
     */
    public function testClassesAreEmittedSpaceSeparated(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->addBodyClass('page-home');
        $document->addBodyClass('user-logged-in');

        // Act
        $rendered = $document->render();

        // Assert
        $this->assertStringContainsString('class="page-home user-logged-in"', $rendered);
    }

    /**
     * A quote in a class name cannot end the attribute.
     *
     * The injection. `theme-" onload="alert(1)` would otherwise close `class` and add an
     * event handler to `<body>` — on every page of the site, since a body class is usually
     * set once for a whole layout.
     *
     * @return void
     */
    public function testAQuoteInAClassNameCannotEndTheAttribute(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->addBodyClass('theme-" onload="alert(1)');

        // Act
        $rendered = $document->render();

        // Assert — the handler never becomes an attribute
        $this->assertStringNotContainsString('onload="alert(1)"', $rendered);
        $this->assertStringContainsString('&quot;', $rendered);

        // And the body tag still has exactly one class attribute
        $this->assertSame(
            1,
            preg_match_all('/<body[^>]*\sclass=/', $rendered),
            'One class attribute, not two.'
        );
    }

    /**
     * The same holds for AMP, which has its own copy of the renderer.
     *
     * Two renderers, two copies of this code. A fix applied to one of them is the shape of
     * the content-resolution bug found in the same week.
     *
     * @return void
     */
    public function testAmpEscapesItsBodyClassesToo(): void
    {
        // Arrange
        $document = $this->document('amp');
        $document->addBodyClass('theme-" onload="alert(1)');

        // Act
        $rendered = $document->render();

        // Assert
        $this->assertStringNotContainsString('onload="alert(1)"', $rendered);
        $this->assertStringContainsString('&quot;', $rendered);
    }

    /**
     * With nothing to add, the tag is `<body>` rather than `<body >`.
     *
     * Cosmetic on its own, and worth an assertion because the previous form concatenated a
     * separator unconditionally: a reader inspecting the output saw `<body >` and reasonably
     * wondered which attribute had gone missing. That is how this was reported.
     *
     * @return void
     */
    public function testWithNoClassesTheTagHasNoStraySpace(): void
    {
        // Arrange
        $document = $this->document('html');

        // Act
        $rendered = $document->render();

        // Assert
        $this->assertStringContainsString('<body>', $rendered);
        $this->assertStringNotContainsString('<body >', $rendered);
    }

    /**
     * `extraBodyTag` is still emitted raw, and still composes with classes.
     *
     * It is documented as carrying markup — event handlers, `data-` attributes — so escaping
     * it would turn every existing use into visible text. That makes it the caller's to make
     * safe, and this test is what stops a well-meaning future change from "fixing" it.
     *
     * @return void
     */
    public function testExtraBodyTagStaysRawAndComposesWithClasses(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->extraBodyTag = 'onload="initPage()"';
        $document->addBodyClass('page-home');

        // Act
        $rendered = $document->render();

        // Assert — the application's markup survives verbatim…
        $this->assertStringContainsString('onload="initPage()"', $rendered);
        // …alongside the escaped class list
        $this->assertStringContainsString('class="page-home"', $rendered);
    }

    /**
     * A null `extraBodyTag` is not a deprecation.
     *
     * The property is public and applications leave it null; AMP's own tests do. The first
     * version of this change called `trim()` on it directly and triggered
     * *"Passing null to parameter #1 of type string is deprecated"* — caught by the suite
     * immediately, which is the only reason it is not in a release.
     *
     * @return void
     */
    public function testANullExtraBodyTagIsHandled(): void
    {
        // Arrange
        $document = $this->document('html');
        $document->extraBodyTag = null;
        $document->addBodyClass('page-home');

        // Act
        $rendered = $document->render();

        // Assert
        $this->assertStringContainsString('class="page-home"', $rendered);
    }
}
