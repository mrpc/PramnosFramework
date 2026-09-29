<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application;

/**
 * The one rule for a client's Allowed Scopes: what it asked for that its list does not include.
 */
#[CoversClass(Application::class)]
class ApplicationScopesBeyondTest extends TestCase
{
    /**
     * An empty list is no restriction; a list names what is allowed, in any shape the column holds.
     */
    public function testTheRule(): void
    {
        // Act & Assert
        $this->assertSame([], Application::scopesBeyond(null, 'openid email'), 'no list, no restriction');
        $this->assertSame([], Application::scopesBeyond('', 'openid email'));
        $this->assertSame([], Application::scopesBeyond('openid profile email', 'openid email'));
        $this->assertSame(['email'], Application::scopesBeyond('openid profile', 'openid email'));
        $this->assertSame(['email'], Application::scopesBeyond('["openid","profile"]', ['openid', 'email']), 'a JSON column');
        $this->assertSame([], Application::scopesBeyond('openid', ''), 'asking for nothing is within any list');
    }
}
