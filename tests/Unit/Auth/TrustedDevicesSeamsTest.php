<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TrustedDevices;

/**
 * The real seams {@see \Pramnos\Tests\Integration\Auth\TrustedDevicesTest} replaces with test
 * doubles throughout — `now()`, `writeCookie()`, `userAgent()`, `ip()` — which is correct for
 * that file's own tests, but means the production bodies themselves had never run.
 */
#[CoversClass(TrustedDevices::class)]
class TrustedDevicesSeamsTest extends TestCase
{
    private function controller(): object
    {
        return new class extends TrustedDevices {
            public function exposeNow(): int
            {
                return $this->now();
            }

            public function exposeWriteCookie(string $token, int $expires): void
            {
                $this->writeCookie($token, $expires);
            }

            public function exposeUserAgent(): string
            {
                return $this->userAgent();
            }

            public function exposeIp(): string
            {
                return $this->ip();
            }

            public function exposeUsertypeOf(int $userId): int
            {
                return $this->usertypeOf($userId);
            }
        };
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT'], $_COOKIE[TrustedDevices::COOKIE]);
        parent::tearDown();
    }

    public function testNowIsTheRealTime(): void
    {
        // Act
        $before = time();
        $now    = $this->controller()->exposeNow();
        $after  = time();

        // Assert
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function testUserAgentReadsTheRealHeader(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'ExampleAgent/1.0';

        // Act & Assert
        $this->assertSame('ExampleAgent/1.0', $this->controller()->exposeUserAgent());
    }

    public function testUserAgentIsEmptyWithoutOne(): void
    {
        // Arrange
        unset($_SERVER['HTTP_USER_AGENT']);

        // Act & Assert
        $this->assertSame('', $this->controller()->exposeUserAgent());
    }

    /**
     * A usertype that cannot be read (no database, or none reachable) answers 0 — "not an
     * administrator" — rather than throwing, which `allowedFor()` relies on to decide whether
     * to exclude an admin account from trusting a device.
     */
    public function testUsertypeOfAnswersZeroRatherThanThrowingWhenTheLookupFails(): void
    {
        // Arrange — a connection that cannot even attempt the query
        $broken = new class extends \Pramnos\Database\Database {
            public function __construct()
            {
            }

            public function queryBuilder()
            {
                throw new \RuntimeException('the connection is gone');
            }
        };
        $factoryRef = &\Pramnos\Framework\Factory::getDatabase();
        $saved      = $factoryRef;
        $factoryRef = $broken;

        try {
            // Act & Assert
            $this->assertSame(0, $this->controller()->exposeUsertypeOf(7));
        } finally {
            $factoryRef = $saved;
        }
    }

    public function testIpReadsTheRealClientAddress(): void
    {
        // Act & Assert — the same answer the framework's own helper gives
        $this->assertSame(\Pramnos\Http\Request::clientIp(), $this->controller()->exposeIp());
    }

    /** The cookie is written into `$_COOKIE` too, so the rest of this request reads as trusted. */
    public function testWriteCookieSetsTheSuperglobalImmediately(): void
    {
        // Act
        $this->controller()->exposeWriteCookie('a-real-token', time() + 86400);

        // Assert
        $this->assertSame('a-real-token', $_COOKIE[TrustedDevices::COOKIE] ?? null);
    }

    /**
     * `setcookie()` itself runs without throwing or warning — PHP's CLI SAPI accepts the
     * call and simply has no real response to attach it to.
     */
    public function testWriteCookieCallsSetcookieWithoutError(): void
    {
        // Act — the point of the test is that this does not throw or warn
        $this->controller()->exposeWriteCookie('another-token', time() + 86400);

        // Assert
        $this->assertSame('another-token', $_COOKIE[TrustedDevices::COOKIE] ?? null);
    }
}
