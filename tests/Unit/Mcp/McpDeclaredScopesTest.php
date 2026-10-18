<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Auth\Scopes;
use Pramnos\Mcp\PublicRegistry;

/**
 * MCP scopes declared in app.php, for a process that holds none of the tools (FW-158 §3).
 *
 * An installation whose authorization server and MCP endpoint are two applications: the one that
 * serves consent and the protected-resource metadata registers no MCP tool, so it knew no MCP
 * scope — it refused `mcp:diagnostics` at consent as unknown, and its metadata listed `mcp`
 * alone while the endpoint's `401` asked for five. `'mcp' => ['scopes' => [...]]` is read by both.
 */
#[CoversClass(PublicRegistry::class)]
#[CoversClass(Scopes::class)]
#[CoversClass(Discovery::class)]
class McpDeclaredScopesTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
        $app = new Application();
        $ref->getProperty('initialized')->setValue($app, true);
        $app->applicationInfo['mcp'] = ['scopes' => [
            'mcp:logs',
            'mcp:people' => 'Find and read the accounts of the people here.',
            'system:admin',                     // not an MCP scope: not taken
        ]];
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * With no tool registered, the declared scopes are grantable: known to the scope table with
     * their descriptions — the framework's for its own, the application's for its — and none
     * reported invalid at consent. A name outside `mcp:*` is not taken from this list.
     */
    public function testDeclaredScopesAreGrantableWithoutTheTools(): void
    {
        // Act
        $descriptions = Scopes::getScopeDescriptions();
        $invalid      = Scopes::hasInvalidScopes('mcp mcp:logs mcp:people');

        // Assert
        $this->assertFalse(PublicRegistry::hasTools(), 'this process offers no tool');
        $this->assertSame(['mcp:logs', 'mcp:people'], PublicRegistry::scopesOffered());
        $this->assertStringContainsString('error logs', $descriptions['mcp:logs']);
        $this->assertSame('Find and read the accounts of the people here.', $descriptions['mcp:people']);
        $this->assertArrayHasKey('mcp', $descriptions, 'the scope every MCP scope inherits');
        $this->assertSame([false, []], $invalid, 'none refused as unknown at consent');
    }

    /** The endpoint's metadata, served by this process, lists them as the endpoint's 401 does. */
    public function testTheMetadataListsTheDeclaredScopes(): void
    {
        // Act
        $scopes = Discovery::scopesForResource('api/1.0/mcp');

        // Assert
        $this->assertSame(['mcp', 'mcp:logs', 'mcp:people', 'offline_access'], $scopes);
    }
}
