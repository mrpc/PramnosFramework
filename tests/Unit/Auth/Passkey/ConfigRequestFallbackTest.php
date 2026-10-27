<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\Passkey\Config;

/**
 * The request-based fallback: an installation with no site URL and no dedicated `passkey_*`
 * settings still gets a usable RP id and origin — "localhost" without having to configure
 * one first — read from the request that is making the passkey ceremony.
 *
 * {@see \Pramnos\Tests\Unit\Auth\Passkey\PasskeyDtoTest::testConfigWithUnparseableUrl} covers
 * the case with *no* request either (a CLI context): everything empty but still usable.
 */
#[CoversClass(Config::class)]
class ConfigRequestFallbackTest extends TestCase
{
    /** @var array<string, string> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        foreach (['HTTP_HOST', 'SERVER_NAME', 'HTTPS', 'SERVER_PORT'] as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->savedServer[$key] = $_SERVER[$key];
                unset($_SERVER[$key]);
            }
        }
        Settings::setSetting('passkey_rp_id', '', false);
        Settings::setSetting('passkey_origins', '', false);
    }

    protected function tearDown(): void
    {
        foreach (['HTTP_HOST', 'SERVER_NAME', 'HTTPS', 'SERVER_PORT'] as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($this->savedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        parent::tearDown();
    }

    /** With nothing configured, the RP id falls back to the plain request host. */
    public function testRpIdFallsBackToTheRequestHost(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame('localhost', $config->rpId);
    }

    /** A port on the request host is stripped — the RP id must be a bare effective domain. */
    public function testRpIdStripsAPortFromTheRequestHost(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame('localhost', $config->rpId);
    }

    /** With no origin configured, the allowed origin falls back to the request's own, over http. */
    public function testOriginFallsBackToTheInsecureRequestOrigin(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame(['http://localhost:8080'], $config->allowedOrigins);
    }

    /** An HTTPS request falls back to an https:// origin. */
    public function testOriginFallsBackToTheSecureRequestOriginOverHttps(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTPS']     = 'on';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame(['https://example.test'], $config->allowedOrigins);
    }

    /** Port 443 is read as secure even without an explicit HTTPS flag. */
    public function testPort443IsReadAsSecure(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST']   = 'example.test';
        $_SERVER['SERVER_PORT'] = '443';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame(['https://example.test'], $config->allowedOrigins);
    }

    /** `SERVER_NAME` is used when there is no `HTTP_HOST` at all. */
    public function testFallsBackToServerNameWithNoHttpHost(): void
    {
        // Arrange
        $_SERVER['SERVER_NAME'] = 'internal.test';

        // Act
        $config = Config::fromSettings('');

        // Assert
        $this->assertSame('internal.test', $config->rpId);
    }
}
