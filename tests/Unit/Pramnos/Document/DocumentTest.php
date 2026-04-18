<?php

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * Unit tests for the Document class rendering features.
 */
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
}
