<?php

use Pramnos\Document\DocumentTypes\Html;
use Pramnos\Application\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CSP nonce auto-injection in Html DocumentType.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Document\DocumentTypes\Html::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class HtmlNonceInjectionTest extends TestCase
{
    /**
     * Set up basic environment defines
     */
    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../../'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
    }

    /**
     * Test that render() injects the nonce into inline script and style tags.
     */
    public function testRenderInjectsNonce()
    {
        // Mock Application to set a nonce
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->cspNonce = 'test-nonce-123';
        
        // Manually inject the mock instance into the static registry
        $reflection = new \ReflectionClass(Application::class);
        $instancesProp = $reflection->getProperty('appInstances');
        $instancesProp->setValue(null, ['default' => $app]);
        
        $lastUsedProp = $reflection->getProperty('lastUsedApplication');
        $lastUsedProp->setValue(null, 'default');

        $htmlDoc = new Html();
        $htmlDoc->title = 'Test';
        
        // Add some content with inline tags
        // Note: Using a fresh buffer for the test
        $reflectionDoc = new \ReflectionClass(\Pramnos\Document\Document::class);
        $bufferProp = $reflectionDoc->getProperty('buffer');
        $bufferProp->setValue(null, '');

        $htmlDoc->addContent('<script>var x = 1;</script>');
        $htmlDoc->addContent('<style>body { color: red; }</style>');
        // Add external script (should NOT get a nonce through the inline regex)
        $htmlDoc->addContent('<script src="external.js"></script>');

        // A script that already carries a nonce keeps the one it has. Two produced
        // `<script nonce="X" nonce="X">` — a duplicate attribute, which is a parse error — and
        // the tags that emit their own nonce are the ones written outside a document render:
        // `humanCheckField()` above all, whose script is the proof-of-work the login depends
        // on. It was the most security-sensitive inline script on the page and the only one
        // with malformed markup around it.
        $htmlDoc->addContent('<script nonce="its-own-nonce">var y = 2;</script>');

        $output = $htmlDoc->render();

        $this->assertStringContainsString('<script nonce="test-nonce-123">var x = 1;</script>', $output);
        $this->assertStringContainsString('<script nonce="its-own-nonce">var y = 2;</script>', $output);
        // The attribute, not the word: the nonce in this test contains "nonce" in its own
        // value, and a looser pattern matches that instead and always fails.
        $this->assertSame(
            0,
            preg_match('/<script[^>]*\snonce\s*=[^>]*\snonce\s*=/i', $output),
            'no script may carry two nonce attributes'
        );
        $this->assertStringContainsString('<style nonce="test-nonce-123">body { color: red; }</style>', $output);
        $this->assertStringContainsString('<script src="external.js"></script>', $output);
        $this->assertStringNotContainsString('nonce="test-nonce-123" src="external.js"', $output);
    }
}
