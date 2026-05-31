<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\TestCase;
use Pramnos\Document\DocumentTypes\Amp;
use Pramnos\Document\DocumentTypes\Json;
use Pramnos\Document\DocumentTypes\Png;
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
}
