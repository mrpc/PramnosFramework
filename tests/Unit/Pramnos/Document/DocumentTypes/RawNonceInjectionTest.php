<?php

use Pramnos\Document\DocumentTypes\Raw;
use Pramnos\Application\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CSP nonce auto-injection in the Raw DocumentType.
 *
 * The log-viewer iframe (Logs/raw) is served as a Raw document. It contains its
 * own inline <script>/<style>, which a `script-src 'self' 'nonce-…'` policy
 * blocks unless each tag carries the request nonce. Raw::render() must inject
 * that nonce exactly like the Html document type does.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Document\DocumentTypes\Raw::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class RawNonceInjectionTest extends TestCase
{
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
     * render() must nonce inline <script>/<style> but leave external
     * (src=) scripts untouched — matching the Html behaviour.
     */
    public function testRenderInjectsNonce(): void
    {
        // Arrange — a mock Application carrying a fixed nonce, injected into the
        // static registry so Application::getInstance() returns it.
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->cspNonce = 'raw-nonce-xyz';

        $reflection = new \ReflectionClass(Application::class);
        $reflection->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $reflection->getProperty('lastUsedApplication')->setValue(null, 'default');

        // Reset the shared document output buffer before writing test content.
        $docRef = new \ReflectionClass(\Pramnos\Document\Document::class);
        $docRef->getProperty('buffer')->setValue(null, '');

        $raw = new Raw();
        $raw->addContent('<script>window.parent.postMessage({totalPages: 3}, "*");</script>');
        $raw->addContent('<style>body { margin: 0; }</style>');
        $raw->addContent('<script src="pf-utils.js"></script>');

        // Act
        $output = $raw->render();

        // Assert — inline tags nonced, external script left as-is.
        $this->assertStringContainsString('<script nonce="raw-nonce-xyz">window.parent.postMessage', $output);
        $this->assertStringContainsString('<style nonce="raw-nonce-xyz">body { margin: 0; }</style>', $output);
        $this->assertStringContainsString('<script src="pf-utils.js"></script>', $output);
        $this->assertStringNotContainsString('nonce="raw-nonce-xyz" src="pf-utils.js"', $output);
    }
}
