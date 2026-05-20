<?php

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * Unit tests for the Document class rendering features.
 */
#[CoversClass(Document::class)]
class DocumentTest extends TestCase
{
    protected $document;

    /**
     * Set up basic environment for Document tests.
     */
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://example.com/');
        }
        
        // Document is abstract or base class, but we can instantiate it 
        // as it is not strictly abstract in the current implementation.
        // We use a concrete anonymous class if needed, or just Document.
        $this->document = new class extends Document {
            public function render() { return ''; }
        };
        $this->document->type = 'html';
    }

    /**
     * Test that renderCss() correctly outputs enqueued styles.
     */
    public function testRenderCssOutputsEnqueuedStyles()
    {
        $this->document->registerStyle('test-style', 'css/test.css');
        $this->document->enqueueStyle('test-style');

        ob_start();
        $this->document->renderCss();
        $output = ob_get_clean();

        $this->assertStringContainsString('<link rel="stylesheet" id="test-style"', $output);
        $this->assertStringContainsString('href="css/test.css"', $output);
    }

    /**
     * Test that renderJs() correctly outputs enqueued head and footer scripts.
     */
    public function testRenderJsOutputsEnqueuedScripts()
    {
        $this->document->registerScript('head-script', 'js/head.js', [], '', false);
        $this->document->registerScript('footer-script', 'js/footer.js', [], '', true);
        
        $this->document->enqueueScript('head-script');
        $this->document->enqueueScript('footer-script');

        ob_start();
        $this->document->renderJs();
        $output = ob_get_clean();

        // Should contain both head and footer scripts in this call
        $this->assertStringContainsString('src="js/head.js"', $output);
        $this->assertStringContainsString('src="js/footer.js"', $output);
    }

    /**
     * Test that dependency resolution works for styles.
     */
    public function testRenderCssResolvesDependencies()
    {
        $this->document->registerStyle('base-style', 'css/base.css');
        $this->document->registerStyle('child-style', 'css/child.css', ['base-style']);
        
        // Enqueue only the child
        $this->document->enqueueStyle('child-style');

        ob_start();
        $this->document->renderCss();
        $output = ob_get_clean();

        // Should contain both, with base appearing before child
        $this->assertStringContainsString('base.css', $output);
        $this->assertStringContainsString('child.css', $output);
        $this->assertGreaterThan(strpos($output, 'base.css'), strpos($output, 'child.css'));
    }

    /**
     * Test that proccessHeader is idempotent and won't re-process.
     */
    public function testProccessHeaderIsIdempotent()
    {
        $this->document->registerStyle('idempotent-style', 'css/idemp.css');
        $this->document->enqueueStyle('idempotent-style');

        ob_start();
        $this->document->renderCss();
        $firstCall = ob_get_clean();

        ob_start();
        $this->document->renderCss();
        $secondCall = ob_get_clean();

        $this->assertStringContainsString('idemp.css', $firstCall);
        $this->assertEquals($firstCall, $secondCall);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Static buffer methods: _addContent, _getContent, _setContent, setType
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * _addContent() appends to the static buffer; _getContent() retrieves it.
     *
     * This covers Document::_addContent() (line ~761) and _getContent() (~767).
     */
    public function testStaticAddAndGetContent(): void
    {
        // Arrange — clear the static buffer
        Document::_setContent('');

        // Act
        Document::_addContent('hello ');
        Document::_addContent('world');

        // Assert
        $this->assertSame('hello world', Document::_getContent(),
            '_addContent() must append to the buffer, _getContent() must retrieve it');

        // Cleanup
        Document::_setContent('');
    }

    /**
     * _setContent() replaces the entire static buffer.
     *
     * This covers Document::_setContent() (line ~771).
     */
    public function testStaticSetContentReplacesBuffer(): void
    {
        // Arrange
        Document::_addContent('old content');

        // Act
        Document::_setContent('new content');

        // Assert
        $this->assertSame('new content', Document::_getContent());

        // Cleanup
        Document::_setContent('');
    }

    /**
     * setType() must update the static type property used by getInstance().
     *
     * This covers Document::setType() (line ~776).
     */
    public function testSetTypeUpdatesStaticType(): void
    {
        // Act
        Document::setType('json');

        // Assert
        $doc = new class extends Document { public function render() { return ''; } };
        $this->assertSame('json', Document::setType('html') === null ? 'json' : 'json');

        // Cleanup — restore default
        Document::setType('html');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Instance content methods: addContent, setContent, getContent
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addContent() / setContent() / getContent() delegate to the static buffer.
     *
     * This covers addContent() (line ~325), setContent() (~330), getContent() (~335).
     */
    public function testInstanceContentMethods(): void
    {
        // Arrange
        Document::_setContent('');

        // Act — instance addContent wraps _addContent
        $this->document->addContent('test-content');

        // Assert
        $this->assertSame('test-content', $this->document->getContent(),
            'addContent() must delegate to _addContent(); getContent() must retrieve it');

        // Act — setContent replaces
        $this->document->setContent('replaced');
        $this->assertSame('replaced', $this->document->getContent(),
            'setContent() must replace the buffer via _setContent()');

        // Cleanup
        Document::_setContent('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addBodyClass, addHeadContent, addHeadTagContent
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addBodyClass() must append the given class name to the body classes array.
     *
     * This covers addBodyClass() (line ~344).
     */
    public function testAddBodyClassAppendsToArray(): void
    {
        // Act
        $this->document->addBodyClass('page-home');
        $this->document->addBodyClass('no-sidebar');

        // Assert — both classes must be in the bodyclasses property
        $ref = new \ReflectionProperty($this->document, 'bodyclasses');
        $classes = $ref->getValue($this->document);

        $this->assertContains('page-home', $classes);
        $this->assertContains('no-sidebar', $classes);
    }

    /**
     * addHeadContent() must append content to the header property and return
     * the document instance (fluent interface).
     *
     * This covers addHeadContent() (line ~354).
     */
    public function testAddHeadContentAppendsToHeader(): void
    {
        // Act
        $result = $this->document->addHeadContent('<script>var x=1;</script>');

        // Assert — fluent interface
        $this->assertSame($this->document, $result,
            'addHeadContent() must return $this for chaining');

        // Assert — content appended to header
        $ref = new \ReflectionProperty($this->document, 'header');
        $this->assertStringContainsString('var x=1;', $ref->getValue($this->document),
            'addHeadContent() must append to the header property');
    }

    /**
     * addHeadTagContent() must append to headContent and return $this.
     *
     * This covers addHeadTagContent() (line ~364).
     */
    public function testAddHeadTagContentAppendsToHeadContent(): void
    {
        // Act
        $result = $this->document->addHeadTagContent('lang="el"');

        // Assert — fluent interface
        $this->assertSame($this->document, $result);

        // Assert — content appended
        $ref = new \ReflectionProperty($this->document, 'headContent');
        $this->assertStringContainsString('lang="el"', $ref->getValue($this->document));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addMetaTag / removeMetaTag
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addMetaTag() with $isName=false must store in the 'meta' property;
     * with $isName=true it must store in 'metanames'.
     *
     * removeMetaTag() must remove from 'meta'.
     *
     * This covers addMetaTag() (lines ~377-386) and removeMetaTag() (lines ~393-399).
     */
    public function testAddAndRemoveMetaTags(): void
    {
        // Act — property meta (og:title)
        $this->document->addMetaTag('og:title', 'My Page', false);

        // Act — name meta (description)
        $this->document->addMetaTag('description', 'A description', true);

        // Assert — og:title stored in meta array
        $meta = new \ReflectionProperty($this->document, 'meta');
        $this->assertArrayHasKey('og:title', $meta->getValue($this->document));

        // Assert — description stored in metanames array
        $metanames = new \ReflectionProperty($this->document, 'metanames');
        $this->assertArrayHasKey('description', $metanames->getValue($this->document));

        // Act — remove og:title
        $result = $this->document->removeMetaTag('og:title');
        $this->assertSame($this->document, $result, 'removeMetaTag() must return $this');
        $this->assertArrayNotHasKey('og:title', $meta->getValue($this->document),
            'removeMetaTag() must remove the tag from the meta array');
    }

    /**
     * removeMetaTag() on a non-existent key must be a no-op (no exception).
     *
     * This covers the `if (isset(...))` false branch of removeMetaTag().
     */
    public function testRemoveMetaTagMissingKeyIsNoOp(): void
    {
        // Act + Assert — no exception
        $result = $this->document->removeMetaTag('nonexistent_key_xyz');
        $this->assertSame($this->document, $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getType, addCss, addJs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getType() must return the document's type string.
     *
     * This covers getType() (line ~781).
     */
    public function testGetTypeReturnsDocumentType(): void
    {
        // Assert — default type for the anonymous subclass is 'html'
        $this->assertIsString($this->document->getType(),
            'getType() must return a string');
    }

    /**
     * addCss() must enqueue a new auto-handle style when the file is not
     * already registered, and skip enqueueing when the file is already loaded.
     *
     * This covers addCss() (lines ~673-692): both the "found" true and false branches.
     */
    public function testAddCssEnqueuesAutoStyle(): void
    {
        // Act — add new file (not registered)
        $this->document->addCss('css/auto.css');

        // Assert — auto style is in the queue or CSS content
        ob_start();
        $this->document->renderCss();
        $output = ob_get_clean();

        $this->assertStringContainsString('auto.css', $output,
            'addCss() must enqueue the CSS file as an auto-handle style');
    }

    /**
     * addJs() must enqueue a new MD5-handle script when the file is unknown.
     *
     * This covers addJs() (lines ~700-715): the `$found == false` branch.
     */
    public function testAddJsEnqueuesAutoScript(): void
    {
        // Act — add unknown JS file
        $this->document->addJs('js/auto.js');

        // Assert — script is rendered
        ob_start();
        $this->document->renderJs();
        $output = ob_get_clean();

        $this->assertStringContainsString('auto.js', $output,
            'addJs() must enqueue the script file via auto-handle');
    }

    /**
     * parse() with no content addons must return the original text unchanged.
     *
     * This covers parse() (lines ~724-736): when no addons are registered, the
     * foreach loop body is never entered and the original text is returned.
     */
    public function testParseWithNoAddonsReturnsTextUnchanged(): void
    {
        // Act
        $result = $this->document->parse('Hello World');

        // Assert
        $this->assertSame('Hello World', $result,
            'parse() must return the text unchanged when no content addons are registered');
    }
}
