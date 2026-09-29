<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\OAuth2\TokenLifetimes;

/**
 * How long tokens last: the server's defaults, `app.php`'s, and one client's own.
 *
 * They were fixed in the factory — an hour, ten minutes, a month — for every client. The rule is
 * now: the client's value, else the server's configured value, else the default; always inside
 * bounds, so a typo cannot mint a token that never expires.
 */
#[CoversClass(TokenLifetimes::class)]
class TokenLifetimesTest extends TestCase
{
    private mixed $saved = null;

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->saved);
            $this->saved = null;
        }
    }

    private function withConfig(array $oauth): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['oauth' => $oauth];
        $property = new \ReflectionProperty(Application::class, 'appInstances');
        $this->saved ??= $property->getValue() ?? [];
        $property->setValue(null, ['default' => $stub] + $this->saved);
    }

    /**
     * Nothing configured is the defaults the factory used to hard-code.
     */
    public function testTheDefaults(): void
    {
        // Arrange
        $this->withConfig([]);

        // Act & Assert
        $this->assertSame(3600, TokenLifetimes::access());
        $this->assertSame(2592000, TokenLifetimes::refresh());
        $this->assertSame(600, TokenLifetimes::authCode());
    }

    /**
     * The server's configuration replaces the defaults; a client's own value replaces both.
     */
    public function testConfigurationAndThenTheClient(): void
    {
        // Arrange
        $this->withConfig(['access_token_ttl' => 900, 'refresh_token_ttl' => 86400, 'auth_code_ttl' => 300]);

        // Act & Assert
        $this->assertSame(900, TokenLifetimes::access());
        $this->assertSame(86400, TokenLifetimes::refresh());
        $this->assertSame(300, TokenLifetimes::authCode());
        $this->assertSame(120, TokenLifetimes::access(120), 'the client wins');
        $this->assertSame(900, TokenLifetimes::access(0), 'an empty client value is the server default');
    }

    /**
     * Out-of-bounds values are clamped rather than refused.
     */
    public function testValuesAreClamped(): void
    {
        // Arrange
        $this->withConfig(['auth_code_ttl' => 99999]);

        // Act & Assert
        $this->assertSame(60, TokenLifetimes::access(5), 'at least a minute');
        $this->assertSame(86400, TokenLifetimes::access(10 ** 9), 'an access token lasts a day at most');
        $this->assertSame(31536000, TokenLifetimes::refresh(10 ** 9), 'a refresh token a year');
        $this->assertSame(600, TokenLifetimes::authCode(), 'a code ten minutes');
        $this->assertSame(120, (new \DateTimeImmutable('@0'))->add(TokenLifetimes::interval(120))->getTimestamp());
    }
}
