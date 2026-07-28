<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Redis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Redis\ConnectionManager;

/**
 * Unit tests for the central Redis connection manager.
 *
 * An injected factory stands in for the phpredis connection so pooling
 * (shared vs. dedicated), prefix/config exposure and the default-instance seam
 * are verified without a live Redis server.
 */
#[CoversClass(ConnectionManager::class)]
class ConnectionManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionManager::setInstance(null);
    }

    /**
     * connection() opens once and is reused; newConnection() always returns a
     * fresh instance (as a blocking subscribe requires).
     */
    public function testSharedVsDedicatedConnections(): void
    {
        $made = 0;
        $manager = new ConnectionManager([], function () use (&$made) {
            $made++;
            return new \stdClass();
        });

        $a = $manager->connection();
        $b = $manager->connection();
        $this->assertSame($a, $b, 'connection() is pooled');
        $this->assertSame(1, $made, 'shared connection opened once');

        $c = $manager->newConnection();
        $d = $manager->newConnection();
        $this->assertNotSame($c, $d, 'newConnection() is always fresh');
        $this->assertSame(3, $made, 'two dedicated connections opened on top of the shared one');
    }

    /**
     * Config values (prefix/host/port/database/password) are exposed for drivers
     * that need the raw settings.
     */
    public function testConfigExposure(): void
    {
        $manager = new ConnectionManager([
            'host'     => 'redis.internal',
            'port'     => 6380,
            'database' => 3,
            'password' => 'secret',
            'prefix'   => 'app_',
        ], fn () => new \stdClass());

        $this->assertSame('app_', $manager->prefix());
        $this->assertSame('redis.internal', $manager->host());
        $this->assertSame(6380, $manager->port());
        $this->assertSame(3, $manager->database());
        $this->assertSame('secret', $manager->password());
    }

    /**
     * An empty password is normalised to null (no AUTH), and defaults apply when
     * the config omits keys.
     */
    public function testDefaultsAndEmptyPassword(): void
    {
        $manager = new ConnectionManager(['password' => ''], fn () => new \stdClass());
        $this->assertSame('127.0.0.1', $manager->host());
        $this->assertSame(6379, $manager->port());
        $this->assertSame(0, $manager->database());
        $this->assertNull($manager->password());
        $this->assertSame('', $manager->prefix());
    }

    /**
     * setInstance() overrides the default manager (the application bootstrap
     * seam) and getInstance() returns it.
     */
    public function testDefaultInstanceSeam(): void
    {
        $custom = new ConnectionManager(['prefix' => 'custom_'], fn () => new \stdClass());
        ConnectionManager::setInstance($custom);

        $this->assertSame($custom, ConnectionManager::getInstance());
        $this->assertSame('custom_', ConnectionManager::getInstance()->prefix());
    }
}
