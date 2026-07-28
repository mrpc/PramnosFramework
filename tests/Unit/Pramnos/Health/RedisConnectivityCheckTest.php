<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\Checks\RedisConnectivityCheck;
use Pramnos\Health\HealthStatus;
use Pramnos\Redis\ConnectionManager;

/**
 * Unit tests for the framework Redis health check.
 *
 * A ConnectionManager with an injected fake connection stands in for phpredis so
 * the PING → up/down mapping is verified without a live Redis server.
 */
#[CoversClass(RedisConnectivityCheck::class)]
class RedisConnectivityCheckTest extends TestCase
{
    private function managerReturning(callable $pingImpl): ConnectionManager
    {
        return new ConnectionManager([], fn () => new class ($pingImpl) {
            /** @var callable */
            private $pingImpl;
            public function __construct(callable $pingImpl)
            {
                $this->pingImpl = $pingImpl;
            }
            public function ping()
            {
                return ($this->pingImpl)();
            }
        });
    }

    /** A successful PING reports the check as up. */
    public function testPongReportsUp(): void
    {
        $check  = new RedisConnectivityCheck($this->managerReturning(fn () => true));
        $result = $check->run();

        $this->assertSame('redis', $result->name);
        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    /** The '+PONG' string reply (some phpredis versions) is also treated as up. */
    public function testPlusPongReportsUp(): void
    {
        $result = (new RedisConnectivityCheck($this->managerReturning(fn () => '+PONG')))->run();
        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    /** An unexpected reply reports the check as down. */
    public function testUnexpectedReplyReportsDown(): void
    {
        $result = (new RedisConnectivityCheck($this->managerReturning(fn () => 'nope')))->run();
        $this->assertSame(HealthStatus::Down, $result->status);
    }

    /** A thrown connection error reports the check as down (not fatal). */
    public function testConnectionErrorReportsDown(): void
    {
        $result = (new RedisConnectivityCheck($this->managerReturning(
            fn () => throw new \RuntimeException('connection refused')
        )))->run();

        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('connection refused', $result->message);
    }
}
