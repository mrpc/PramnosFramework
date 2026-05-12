<?php

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\View;

/**
 * Unit tests for the template-engine methods added to View.
 *
 * View requires a Controller in its constructor and several framework globals
 * (Factory::getDocument(), etc.). Rather than wiring up the entire framework,
 * we use an anonymous subclass that:
 *   - stubs out __construct so no Controller is needed
 *   - exposes the protected template-engine state for assertion
 *
 * The layout/section/yield/insert flow is tested indirectly through the public
 * API (layout(), section(), endsection(), yield()) rather than through the full
 * getTpl() cycle, which would require real template files on disk.
 *
 * Coverage goals:
 *   - layout() stores the layout name
 *   - section() / endsection() capture OB output into $sections[]
 *   - nested section() / endsection() pairs work correctly (stack)
 *   - yield() returns captured section content
 *   - yield() returns $default when the section was never defined
 *   - escape() / e() delegate to global e() helper
 *   - setTemplateCacheDir / getTemplateCacheDir round-trip
 */
#[\PHPUnit\Framework\Attributes\CoversClass(View::class)]
class ViewTemplateTest extends TestCase
{
    /** @var View anonymous stub instance */
    private View $view;

    protected function setUp(): void
    {
        // Anonymous subclass: bypass the real constructor (which requires a
        // fully-booted framework) and expose internal state for assertions.
        $this->view = new class extends View {
            public function __construct()
            {
                // Deliberately skip parent::__construct() — we only test the
                // template-engine methods, which have no framework dependencies.
                $this->_layout      = null;
                $this->sections     = [];
                $this->sectionStack = [];
            }

            // Expose protected state for assertions
            public function getLayout(): ?string        { return $this->_layout; }
            public function getSections(): array        { return $this->sections; }
            public function getSectionStack(): array    { return $this->sectionStack; }
        };

        // Reset the static cache dir between tests to avoid cross-test bleed.
        View::setTemplateCacheDir('');
    }

    // =========================================================================
    // layout()
    // =========================================================================

    /**
     * layout() stores the given name in $_layout.
     * getTpl() reads this after the child render to decide whether to wrap
     * the output in a parent template.
     */
    public function testLayoutStoresName(): void
    {
        // Act
        $this->view->layout('layouts/main');

        // Assert
        $this->assertSame('layouts/main', $this->view->getLayout());
    }

    /**
     * Calling layout() twice keeps only the last value.
     * Only one parent layout is supported; the last call wins.
     */
    public function testLayoutOverwritesPreviousValue(): void
    {
        // Arrange
        $this->view->layout('layouts/old');

        // Act
        $this->view->layout('layouts/new');

        // Assert
        $this->assertSame('layouts/new', $this->view->getLayout());
    }

    // =========================================================================
    // section() / endsection()
    // =========================================================================

    /**
     * section() / endsection() capture everything echoed between them
     * and store it under the given name.
     * This is the primary mechanism for child templates to fill layout slots.
     */
    public function testSectionEndsectionCapturesOutput(): void
    {
        // Act
        $this->view->section('content');
        echo '<h1>Hello</h1>';
        $this->view->endsection();

        // Assert
        $sections = $this->view->getSections();
        $this->assertArrayHasKey('content', $sections);
        $this->assertSame('<h1>Hello</h1>', $sections['content']);
    }

    /**
     * Multiple separate sections are stored independently.
     * A layout template typically defines several named slots.
     */
    public function testMultipleSectionsStoredSeparately(): void
    {
        // Act
        $this->view->section('title');
        echo 'Page Title';
        $this->view->endsection();

        $this->view->section('body');
        echo '<p>Body</p>';
        $this->view->endsection();

        // Assert
        $sections = $this->view->getSections();
        $this->assertSame('Page Title', $sections['title']);
        $this->assertSame('<p>Body</p>', $sections['body']);
    }

    /**
     * Nested section() / endsection() pairs: inner section is closed first,
     * outer section captures everything remaining.
     * Nesting is unusual in practice but must work correctly.
     */
    public function testNestedSectionsWorkCorrectly(): void
    {
        // Act
        $this->view->section('outer');
        echo 'before';
        $this->view->section('inner');
        echo 'inner content';
        $this->view->endsection(); // closes 'inner'
        echo 'after';
        $this->view->endsection(); // closes 'outer'

        // Assert
        $sections = $this->view->getSections();
        $this->assertSame('inner content', $sections['inner']);
        $this->assertSame('beforeafter', $sections['outer']);
    }

    /**
     * endsection() with an empty stack is a no-op — it must not throw.
     * A mismatched @endsection in a template should not crash the app.
     */
    public function testEndsectionOnEmptyStackDoesNotThrow(): void
    {
        // Act + Assert — no exception
        $this->view->endsection();
        $this->assertEmpty($this->view->getSectionStack());
    }

    /**
     * After endsection(), the section stack must be empty.
     * Leftover stack entries would corrupt subsequent section() calls.
     */
    public function testSectionStackIsEmptyAfterEndsection(): void
    {
        // Act
        $this->view->section('foo');
        echo 'bar';
        $this->view->endsection();

        // Assert
        $this->assertEmpty($this->view->getSectionStack());
    }

    // =========================================================================
    // yield()
    // =========================================================================

    /**
     * yield() returns the captured section content after section/endsection.
     * This is what layouts call to pull child template output into the correct slot.
     */
    public function testYieldReturnsCapturedSection(): void
    {
        // Arrange
        $this->view->section('content');
        echo '<article>Content</article>';
        $this->view->endsection();

        // Act + Assert
        $this->assertSame('<article>Content</article>', $this->view->yield('content'));
    }

    /**
     * yield() returns the $default when the section has not been defined.
     * Layouts use this for optional slots that child templates may omit.
     */
    public function testYieldReturnDefaultForMissingSection(): void
    {
        // Act + Assert — section 'sidebar' was never defined
        $this->assertSame('<aside>default</aside>', $this->view->yield('sidebar', '<aside>default</aside>'));
    }

    /**
     * yield() returns an empty string by default for missing sections.
     * Layouts that don't specify a default get silent empty output,
     * not null or false, which could cause render errors.
     */
    public function testYieldReturnsEmptyStringByDefaultForMissingSection(): void
    {
        // Act + Assert
        $this->assertSame('', $this->view->yield('nonexistent'));
    }

    // =========================================================================
    // escape() / e()
    // =========================================================================

    /**
     * escape() HTML-encodes special characters.
     * This is the safe output method for user-supplied data in templates.
     */
    public function testEscapeEncodesHtmlSpecialChars(): void
    {
        // Act
        $result = $this->view->escape('<script>alert("xss")</script>');

        // Assert — the result must not be executable HTML
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * e() is a short alias for escape() — both must return identical output.
     */
    public function testEAliasMatchesEscape(): void
    {
        // Arrange
        $value = '<b>bold & "quoted"</b>';

        // Act
        $via_escape = $this->view->escape($value);
        $via_e      = $this->view->e($value);

        // Assert
        $this->assertSame($via_escape, $via_e);
    }

    // =========================================================================
    // setTemplateCacheDir / getTemplateCacheDir (static, app-level config)
    // =========================================================================

    /**
     * setTemplateCacheDir() stores the path; getTemplateCacheDir() returns it.
     * Application bootstrap calls this once to configure the compiled-template store.
     */
    public function testSetGetTemplateCacheDir(): void
    {
        // Act
        View::setTemplateCacheDir('/var/cache/templates');

        // Assert
        $this->assertSame('/var/cache/templates', View::getTemplateCacheDir());
    }

    /**
     * After setUp() resets the cache dir to '', getTemplateCacheDir() returns ''.
     * This documents the "empty = use default" contract used by getIncludePath().
     */
    public function testDefaultTemplateCacheDirIsEmpty(): void
    {
        // setUp() already called View::setTemplateCacheDir('')
        $this->assertSame('', View::getTemplateCacheDir());
    }
}
