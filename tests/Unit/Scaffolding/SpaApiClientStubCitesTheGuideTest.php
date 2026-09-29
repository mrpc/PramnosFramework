<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scaffolding;

use PHPUnit\Framework\TestCase;

/**
 * The scaffolded API client says whose contract it speaks, and where the alternative is.
 *
 * The stub assumes a `Pramnos\Application\Api` project — `apiKey`, `accessToken`, the account
 * endpoints. An attribute-routed, Bearer-authenticated SPA shares none of that, and the guide
 * tables what to replace. The pointer has to be in the file somebody opens, and it has to
 * name a heading that exists, or it points at nothing after the next rename.
 */
class SpaApiClientStubCitesTheGuideTest extends TestCase
{
    /**
     * The stub's header names a heading of the Application Styles Guide that exists.
     */
    public function testTheStubCitesAGuideHeadingThatExists(): void
    {
        // Arrange
        $root    = dirname(__DIR__, 3);
        $stub    = (string) file_get_contents($root . '/scaffolding/templates/spa-api-client.js.stub');
        $guide   = (string) file_get_contents($root . '/docs/Pramnos_Application_Styles_Guide.md');
        $heading = 'What the scaffolded API client assumes — and what to replace';

        // Act
        $header = substr($stub, 0, (int) strpos($stub, '*/'));

        // Assert — cited in the header, and present in the guide under that exact name
        $this->assertStringContainsString($heading, $header, 'the stub does not point at the guide');
        $this->assertStringContainsString('Pramnos_Application_Styles_Guide.md', $header);
        $this->assertStringContainsString('### ' . $heading, $guide, 'the cited heading is not in the guide');
    }
}
