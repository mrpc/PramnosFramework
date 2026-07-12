<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\DocumentTypes\Amp;
use Pramnos\Document\DocumentTypes\Json;
use Pramnos\Document\DocumentTypes\Png;
use Pramnos\Document\DocumentTypes\Raw;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;

/**
 * Unit tests for DocumentTypes: Amp, Json, Png.
 */
class DocumentTypesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('pramnos_request')) {
            class_alias(Request::class, 'pramnos_request');
        }
        
        // Ensure language factory has basic keys
        Factory::getLanguage()->addlang([
            'LangShort' => 'el',
            'CHARSET' => 'UTF-8',
        ]);
        
        // Reset request settings
        Request::$originalRequestNoChange = '/some-uri';
    }

    public function testAmpRenderWithDefaults(): void
    {
        $amp = new Amp();
        $amp->title = 'Test Title';
        $amp->description = 'Test Description';
        
        // Populate static buffer (which Amp render accesses via self::_getContent())
        \Pramnos\Document\Document::_setContent('<p>Hello AMP</p>');

        $output = $amp->render();
        
        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('<html amp', $output);
        $this->assertStringContainsString('lang="el"', $output);
        $this->assertStringContainsString('<title>Test Title</title>', $output);
        $this->assertStringContainsString('content="Test Description"', $output);
        $this->assertStringContainsString('<link rel="canonical" href="' . sURL . '/some-uri">', $output);
        $this->assertStringContainsString('<p>Hello AMP</p>', $output);
        
        // Cleanup buffer
        \Pramnos\Document\Document::_setContent('');
    }

    public function testAmpRenderWithOptions(): void
    {
        $amp = new Amp();
        $amp->title = 'Test Title';
        $amp->canonical = 'http://canonical.com';
        $amp->og_title = 'OG Title';
        $amp->og_site_name = 'OG Site';
        $amp->og_url = 'OG URL';
        $amp->og_description = 'OG Description';
        $amp->og_image = 'http://image.png';
        $amp->addBodyClass('custom-body-class');
        $amp->addMetaTag('custom-property', 'custom-value', false);
        $amp->addMetaTag('custom-name', 'name-value', true);
        
        \Pramnos\Document\Document::_setContent('<p>Hello AMP</p>');

        $output = $amp->render();
        
        $this->assertStringContainsString('<link rel="canonical" href="http://canonical.com">', $output);
        $this->assertStringContainsString('property="og:title" content="OG Title"', $output);
        $this->assertStringContainsString('property="og:site_name" content="OG Site"', $output);
        $this->assertStringContainsString('property="og:url" content="OG URL"', $output);
        $this->assertStringContainsString('property="og:description" content="OG Description"', $output);
        $this->assertStringContainsString('property="og:image" content="http://image.png"', $output);
        $this->assertStringContainsString('class="custom-body-class"', $output);
        $this->assertStringContainsString('property="custom-property" content="custom-value"', $output);
        $this->assertStringContainsString('name="custom-name" content="name-value"', $output);
        
        \Pramnos\Document\Document::_setContent('');
    }

    /**
     * Html::render() must include all meta-property tags, meta-name tags,
     * og:image, and the body class when those properties are populated.
     *
     * Covers: meta foreach (lines 88-93), metanames foreach (96-101),
     * og_image branch (104-105), bodyclasses foreach (117-118), and the
     * else branch that emits class="..." (lines 125-127).
     */
    public function testHtmlRenderWithMetaTagsAndBodyClass(): void
    {
        // Arrange
        $html = new \Pramnos\Document\DocumentTypes\Html();
        $html->title       = 'Meta Test';
        $html->og_image    = 'http://example.com/og.jpg'; // triggers lines 104-105

        $html->addMetaTag('custom:property', 'prop-value');        // fills $this->meta
        $html->addMetaTag('keywords', 'php, framework', true);      // fills $this->metanames
        $html->addBodyClass('main-body');                           // fills $this->bodyclasses

        \Pramnos\Document\Document::_setContent('<p>html body</p>');

        // Act
        $output = $html->render();

        // Cleanup
        \Pramnos\Document\Document::_setContent('');

        // Assert — each populated branch must appear in the output
        $this->assertStringContainsString(
            'property="og:image" content="http://example.com/og.jpg"',
            $output,
            'og:image meta tag must be emitted when og_image is non-empty'
        );
        $this->assertStringContainsString(
            'property="custom:property" content="prop-value"',
            $output,
            'Custom property meta tag must appear in the meta foreach'
        );
        $this->assertStringContainsString(
            'name="keywords" content="php, framework"',
            $output,
            'Meta-name tag must appear in the metanames foreach'
        );
        $this->assertStringContainsString(
            'class="main-body"',
            $output,
            'Body element must include the registered class'
        );
    }

    /**
     * Html::render() must invoke loadTheme(), getheader(), gethead(), getfoot()
     * when themeObject is non-null (lines 32-35).
     */
    public function testHtmlRenderCallsThemeObjectMethods(): void
    {
        // Arrange — anonymous class satisfies the duck-typed themeObject contract
        $theme = new class {
            public function loadTheme(): void {}
            public function getheader(): string { return '<!-- th-header -->'; }
            public function gethead(): string   { return '<!-- th-head -->';   }
            public function getfoot(): string   { return '<!-- th-foot -->';   }
        };

        $html = new \Pramnos\Document\DocumentTypes\Html();
        $html->title       = 'Themed Doc';
        $html->themeObject = $theme;

        \Pramnos\Document\Document::_setContent('');

        // Act
        $output = $html->render();

        // Cleanup
        \Pramnos\Document\Document::_setContent('');

        // Assert — theme contributions appear in the rendered output
        $this->assertStringContainsString('<!-- th-header -->', $output,
            'getheader() contribution must appear in the rendered Html');
        $this->assertStringContainsString('<!-- th-head -->', $output,
            'gethead() contribution must appear in the rendered Html');
        $this->assertStringContainsString('<!-- th-foot -->', $output,
            'getfoot() contribution must appear in the rendered Html');
    }

    public function testJsonRender(): void
    {
        $json = new Json();
        \Pramnos\Document\Document::_setContent('{"status":"ok"}');
        
        ob_start();
        $output = $json->render();
        ob_end_clean();
        
        $this->assertSame('{"status":"ok"}', $output);
        \Pramnos\Document\Document::_setContent('');
    }

    public function testPngRender(): void
    {
        $png = new Png();
        \Pramnos\Document\Document::_setContent('png-binary-data');

        ob_start();
        $output = $png->render();
        ob_end_clean();

        $this->assertSame('png-binary-data', $output);
        \Pramnos\Document\Document::_setContent('');
    }

    /**
     * Raw::render() must return whatever is in the document static buffer
     * unchanged — no HTML wrapping, no headers, no encoding.
     */
    public function testRawRenderReturnsBufferContent(): void
    {
        // Arrange
        $raw     = new Raw();
        $content = 'raw-output: no wrapping';
        \Pramnos\Document\Document::_setContent($content);

        // Act
        $output = $raw->render();

        // Assert — identical to what was placed in the buffer
        $this->assertSame($content, $output);

        // Cleanup
        \Pramnos\Document\Document::_setContent('');
    }
}
