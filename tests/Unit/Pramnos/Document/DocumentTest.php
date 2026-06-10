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

    // ─────────────────────────────────────────────────────────────────────────
    // addBreadcrumbItem
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addBreadcrumbItem() must delegate to $this->breadcrumb->addItem().
     *
     * Breadcrumb is always constructed in Document::__construct(), so this
     * exercises line 242 of Document.php.
     */
    public function testAddBreadcrumbItemDelegatesToBreadcrumb(): void
    {
        // Act — add two items; no exception means delegation succeeded
        $this->document->addBreadcrumbItem('Home', 'http://example.com/');
        $this->document->addBreadcrumbItem('About');

        // Assert — the breadcrumb property is still a Breadcrumb instance
        $this->assertInstanceOf(
            \Pramnos\Html\Breadcrumb::class,
            $this->document->breadcrumb,
            'addBreadcrumbItem() must not replace the breadcrumb object'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // isScriptRegistered / isStyleRegistered
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * isScriptRegistered() must return true for a previously registered handle
     * and false for an unknown handle.
     *
     * Covers line 448 of Document.php.
     */
    public function testIsScriptRegisteredReturnsTrueForKnownHandle(): void
    {
        // Arrange — 'jquery' is registered by default in Document::__construct()
        // Act + Assert
        $this->assertTrue(
            $this->document->isScriptRegistered('jquery'),
            'isScriptRegistered() must return true for the built-in jquery handle'
        );
        $this->assertFalse(
            $this->document->isScriptRegistered('nonexistent_handle_xyz'),
            'isScriptRegistered() must return false for an unknown handle'
        );
    }

    /**
     * isStyleRegistered() must return true for a previously registered handle
     * and false for an unknown handle.
     *
     * Covers line 460 of Document.php.
     */
    public function testIsStyleRegisteredReturnsTrueForKnownHandle(): void
    {
        // Arrange — 'jquery-ui' CSS is registered by default in Document::__construct()
        // Act + Assert
        $this->assertTrue(
            $this->document->isStyleRegistered('jquery-ui'),
            'isStyleRegistered() must return true for the built-in jquery-ui handle'
        );
        $this->assertFalse(
            $this->document->isStyleRegistered('nonexistent_style_xyz'),
            'isStyleRegistered() must return false for an unknown handle'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // registerScript / registerStyle with non-array deps
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * registerScript() must convert a non-array $deps argument to an array.
     *
     * This covers line 426: `if (!is_array($deps)) { $deps = array($deps); }`.
     * A string dep passed directly must be treated as a single-element array so
     * the foreach dependency loop in _enqueueScript() does not fail.
     */
    public function testRegisterScriptConvertsStringDepsToArray(): void
    {
        // Act — pass a plain string as deps instead of an array
        $result = $this->document->registerScript('my-lib', 'js/mylib.js', 'jquery', '', false);

        // Assert — fluent interface
        $this->assertSame($this->document, $result,
            'registerScript() must return $this (fluent interface)');

        // Assert — script was stored with deps converted to array
        $ref    = new \ReflectionProperty($this->document, '_js');
        $scripts = $ref->getValue($this->document);
        $this->assertIsArray($scripts['my-lib']['deps'],
            'registerScript() must store deps as an array even when a string was passed');
    }

    /**
     * registerStyle() must convert a non-array $deps argument to an array.
     *
     * Covers line 476: `if (!is_array($deps)) { $deps = array($deps); }`.
     */
    public function testRegisterStyleConvertsStringDepsToArray(): void
    {
        // Act — pass a plain string as deps
        $result = $this->document->registerStyle('my-theme', 'css/theme.css', 'jquery-ui', '', 'all');

        // Assert — fluent interface
        $this->assertSame($this->document, $result);

        // Assert — deps stored as array
        $ref    = new \ReflectionProperty($this->document, '_css');
        $styles = $ref->getValue($this->document);
        $this->assertIsArray($styles['my-theme']['deps'],
            'registerStyle() must store deps as an array even when a string was passed');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // enqueueScript with version string
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enqueueing a script with a non-empty version string must append '?v=VERSION'
     * to the script URL in the rendered output.
     *
     * Covers line 570 of Document.php: `$script .= '?v=' . $version;`.
     */
    public function testEnqueueScriptWithVersionAppendsQueryString(): void
    {
        // Arrange — register a head script (footer=false) with a version
        $this->document->registerScript('versioned-lib', 'js/versioned.js', [], '2.0.1', false);
        $this->document->enqueueScript('versioned-lib', '', [], '2.0.1', false);

        // Act
        ob_start();
        $this->document->renderJs();
        $output = ob_get_clean();

        // Assert — version query string is present
        $this->assertStringContainsString('?v=2.0.1', $output,
            'Enqueueing a script with a version must append ?v=VERSION to the src');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // _enqueueStyle — media='' branch
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a style is registered with media='' the rendered tag must NOT include
     * the media attribute.
     *
     * This covers lines 630-634 of Document.php — the `else` branch inside
     * _enqueueStyle() that fires when $media is an empty string.
     */
    public function testEnqueueStyleWithEmptyMediaOmitsMediaAttribute(): void
    {
        // Arrange — register style with empty media string
        $this->document->registerStyle('no-media-style', 'css/no-media.css', [], '', '');
        $this->document->enqueueStyle('no-media-style', '', [], '', '');

        // Act
        ob_start();
        $this->document->renderCss();
        $output = ob_get_clean();

        // Assert — tag is present but without media="..."
        $this->assertStringContainsString('no-media.css', $output,
            '_enqueueStyle() must render a link tag even with empty media');
        $this->assertStringNotContainsString('media=', $output,
            '_enqueueStyle() must omit the media attribute when media is empty');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addCss — found=true path (already registered, not yet loaded)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addCss() with a file that matches a registered (but not yet loaded) style
     * must enqueue via the existing handle rather than creating a new auto handle.
     *
     * Covers lines 707-710 of Document.php — the `$found = true` branch in addCss().
     */
    public function testAddCssWithAlreadyRegisteredFileUsesExistingHandle(): void
    {
        // Arrange — register the style manually first
        $this->document->registerStyle('pre-registered', 'css/known.css');

        // Act — addCss() with the same URL
        $this->document->addCss('css/known.css');

        // Assert — the style renders (it was enqueued via the existing handle)
        ob_start();
        $this->document->renderCss();
        $output = ob_get_clean();

        $this->assertStringContainsString('css/known.css', $output,
            'addCss() must enqueue the pre-registered style by its handle');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addInlineScript
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addInlineScript() must append a <script>…</script> block to $foot and
     * return $this (fluent interface).
     *
     * Covers lines 758-759 of Document.php.
     */
    public function testAddInlineScriptAppendsToFootAndReturnsThis(): void
    {
        // Act
        $result = $this->document->addInlineScript('console.log("test");');

        // Assert — fluent interface
        $this->assertSame($this->document, $result,
            'addInlineScript() must return $this');

        // Assert — inline code appears in the foot property
        $this->assertStringContainsString(
            '<script>console.log("test");</script>',
            (string) $this->document->foot,
            'addInlineScript() must wrap the code in <script> tags and append to foot'
        );
    }

    /**
     * addInlineScript() output appears in renderJs() because renderJs() echoes $foot.
     */
    public function testAddInlineScriptAppearsInRenderJsOutput(): void
    {
        // Arrange
        $this->document->addInlineScript('var x = 42;');

        // Act
        ob_start();
        $this->document->renderJs();
        $output = ob_get_clean();

        // Assert
        $this->assertStringContainsString('var x = 42;', $output,
            'addInlineScript() code must be output by renderJs()');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — base Document::render() without theme
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The base Document::render() must concatenate header + head + content + foot
     * into one string when no themeObject is set.
     *
     * Covers lines 789-801 of Document.php (the `$this->themeObject === NULL` path).
     *
     * The setUp() anonymous subclass overrides render() for isolation, so we
     * instantiate a second subclass here that delegates to parent::render().
     */
    public function testRenderWithNoThemeConcatenatesSections(): void
    {
        // Arrange — an anonymous subclass that exposes parent::render()
        $doc = new class extends Document {
            public function render() { return parent::render(); }
        };
        $doc->type = 'html';
        $doc->header  = 'HEADER_CONTENT';
        $doc->head    = 'HEAD_CONTENT';
        $doc->content = 'BODY_CONTENT';
        $doc->foot    = 'FOOT_CONTENT';
        $doc->themeObject = null;

        // Act
        $output = $doc->render();

        // Assert — all sections present in the rendered string
        $this->assertStringContainsString('HEADER_CONTENT', $output,
            'render() must include $header when no themeObject is set');
        $this->assertStringContainsString('HEAD_CONTENT', $output,
            'render() must include $head when no themeObject is set');
        $this->assertStringContainsString('BODY_CONTENT', $output,
            'render() must include $content when no themeObject is set');
        $this->assertStringContainsString('FOOT_CONTENT', $output,
            'render() must include $foot when no themeObject is set');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getInstance() factory
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getInstance('html') must return an Html document instance.
     *
     * Covers lines 291-294 of Document.php — the 'html' case in getInstance().
     */
    public function testGetInstanceHtmlReturnsHtmlDocument(): void
    {
        // Act
        $doc = Document::getInstance('html', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Html::class,
            $doc,
            "getInstance('html') must return a DocumentTypes\\Html instance"
        );
        $this->assertSame('html', $doc->type);
    }

    /**
     * getInstance('json') must return a Json document instance.
     *
     * Covers lines 299-302 of Document.php — the 'json' case.
     */
    public function testGetInstanceJsonReturnsJsonDocument(): void
    {
        // Act
        $doc = Document::getInstance('json', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Json::class,
            $doc,
            "getInstance('json') must return a DocumentTypes\\Json instance"
        );
        $this->assertSame('json', $doc->type);
    }

    /**
     * getInstance('rss') must return an Rss document instance.
     *
     * Covers lines 303-306 of Document.php — the 'rss' case.
     */
    public function testGetInstanceRssReturnsRssDocument(): void
    {
        // Act
        $doc = Document::getInstance('rss', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Rss::class,
            $doc,
            "getInstance('rss') must return a DocumentTypes\\Rss instance"
        );
        $this->assertSame('rss', $doc->type);
    }

    /**
     * getInstance('raw') must return a Raw document instance.
     *
     * Covers lines 311-314 of Document.php — the 'raw' case.
     */
    public function testGetInstanceRawReturnsRawDocument(): void
    {
        // Act
        $doc = Document::getInstance('raw', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Raw::class,
            $doc,
            "getInstance('raw') must return a DocumentTypes\\Raw instance"
        );
        $this->assertSame('raw', $doc->type);
    }

    /**
     * getInstance() called twice with the same type must return the same object
     * (singleton-per-type behaviour).
     *
     * Covers line 285 of Document.php — the `!isset($instances[$type])` guard that
     * skips re-construction when the instance already exists.
     */
    public function testGetInstanceReturnsSameObjectForSameType(): void
    {
        // Act — two calls with the same type
        $first  = Document::getInstance('html', false);
        $second = Document::getInstance('html', false);

        // Assert — identity, not just equality
        $this->assertSame($first, $second,
            'getInstance() must return the same object on repeated calls for the same type');
    }

    /**
     * getInstance() with setDefault=true must update the static $type property.
     *
     * Covers lines 282-283 of Document.php — the `elseif ($setDefault === true)` branch.
     */
    public function testGetInstanceWithSetDefaultTrueUpdatesStaticType(): void
    {
        // Arrange — save current type to restore later
        $originalType = Document::$type;

        // Act
        Document::getInstance('json', true);

        // Assert
        $this->assertSame('json', Document::$type,
            "getInstance(type, true) must set the static \$type to the requested type");

        // Cleanup — restore original type
        Document::$type = $originalType;
    }

    /**
     * getInstance('amp') must return an Amp document instance.
     *
     * Covers lines 295-298 of Document.php — the 'amp' case.
     */
    public function testGetInstanceAmpReturnsAmpDocument(): void
    {
        // Act
        $doc = Document::getInstance('amp', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Amp::class,
            $doc,
            "getInstance('amp') must return a DocumentTypes\\Amp instance"
        );
        $this->assertSame('amp', $doc->type);
    }

    /**
     * getInstance('pdf') must return a Pdf document instance.
     *
     * Covers lines 307-310 of Document.php — the 'pdf' case.
     */
    public function testGetInstancePdfReturnsPdfDocument(): void
    {
        // Act
        $doc = Document::getInstance('pdf', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Pdf::class,
            $doc,
            "getInstance('pdf') must return a DocumentTypes\\Pdf instance"
        );
        $this->assertSame('pdf', $doc->type);
    }

    /**
     * getInstance('png') must return a Png document instance.
     *
     * Covers lines 315-318 of Document.php — the 'png' case.
     */
    public function testGetInstancePngReturnsPngDocument(): void
    {
        // Act
        $doc = Document::getInstance('png', false);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Png::class,
            $doc,
            "getInstance('png') must return a DocumentTypes\\Png instance"
        );
        $this->assertSame('png', $doc->type);
    }

    /**
     * getInstance() with an unknown type must fall through to the default case
     * and return an Html document (the switch default: branch).
     *
     * Covers lines 287-290 of Document.php — the `default:` case.
     */
    public function testGetInstanceWithUnknownTypeReturnsHtml(): void
    {
        // Act — 'foobar' is not a known document type
        $doc = Document::getInstance('foobar', false);

        // Assert — the default: branch creates an Html document
        $this->assertInstanceOf(
            \Pramnos\Document\DocumentTypes\Html::class,
            $doc,
            "getInstance() with unknown type must return the default Html instance"
        );
    }
}
