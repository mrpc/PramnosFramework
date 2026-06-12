<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\ServiceProvider;
use Pramnos\Auth\AuthServerServiceProvider;
use Pramnos\Cache\CacheServiceProvider;
use Pramnos\DevPanel\DevPanelServiceProvider;
use Pramnos\Messaging\MessagingServiceProvider;
use Pramnos\Queue\QueueServiceProvider;

/**
 * Unit tests for service providers.
 *
 * ServiceProviders follow a register()/boot() lifecycle. These tests verify
 * that each provider can be instantiated and its lifecycle methods called
 * without error, which is the minimum contract every provider must satisfy.
 *
 * WHY these tests matter: a broken register()/boot() crashes the application
 * bootstrap; even an empty implementation must not throw.
 */
#[CoversClass(AuthServerServiceProvider::class)]
#[CoversClass(CacheServiceProvider::class)]
#[CoversClass(DevPanelServiceProvider::class)]
#[CoversClass(MessagingServiceProvider::class)]
#[CoversClass(QueueServiceProvider::class)]
class ServiceProvidersTest extends TestCase
{
    /**
     * Creates a mock Application that satisfies ServiceProvider's constructor
     * type-hint without booting a real application.
     */
    private function makeApp(): Application
    {
        return $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    // ── AuthServerServiceProvider ─────────────────────────────────────────────

    /**
     * AuthServerServiceProvider::register() must be callable without error.
     * The method body is intentionally empty at framework level.
     */
    public function testAuthServerServiceProviderRegister(): void
    {
        // Arrange
        $provider = new AuthServerServiceProvider($this->makeApp());

        // Act + Assert — must not throw
        $provider->register();
        $this->addToAssertionCount(1);
    }

    /**
     * AuthServerServiceProvider::boot() must be callable without error.
     */
    public function testAuthServerServiceProviderBoot(): void
    {
        // Arrange
        $provider = new AuthServerServiceProvider($this->makeApp());

        // Act + Assert — must not throw
        $provider->boot();
        $this->addToAssertionCount(1);
    }

    // ── CacheServiceProvider ─────────────────────────────────────────────────

    /**
     * CacheServiceProvider::register() warms the Cache singleton.
     * Must not throw even when settings are at defaults.
     */
    public function testCacheServiceProviderRegister(): void
    {
        // Arrange
        $provider = new CacheServiceProvider($this->makeApp());

        // Act + Assert — warms Cache::getInstance(); must not throw
        $provider->register();
        $this->addToAssertionCount(1);
    }

    /**
     * CacheServiceProvider::boot() is a hook point for application overrides.
     * The base implementation is empty and must not throw.
     */
    public function testCacheServiceProviderBoot(): void
    {
        // Arrange
        $provider = new CacheServiceProvider($this->makeApp());

        // Act + Assert
        $provider->boot();
        $this->addToAssertionCount(1);
    }

    // ── DevPanelServiceProvider ───────────────────────────────────────────────

    /**
     * DevPanelServiceProvider::register() must be callable without error.
     */
    public function testDevPanelServiceProviderRegister(): void
    {
        // Arrange
        $provider = new DevPanelServiceProvider($this->makeApp());

        // Act + Assert
        $provider->register();
        $this->addToAssertionCount(1);
    }

    /**
     * DevPanelServiceProvider::boot() must skip HTTP-only logic when running
     * in CLI context (PHP_SAPI === 'cli'). Must not throw.
     */
    public function testDevPanelServiceProviderBootInCli(): void
    {
        // Arrange — tests always run under CLI, so the if (PHP_SAPI === 'cli') branch
        // is taken and boot() returns early without accessing Settings.
        $provider = new DevPanelServiceProvider($this->makeApp());

        // Act + Assert
        $provider->boot();
        $this->addToAssertionCount(1);
    }

    // ── MessagingServiceProvider ──────────────────────────────────────────────

    /**
     * MessagingServiceProvider::register() must not throw.
     */
    public function testMessagingServiceProviderRegister(): void
    {
        // Arrange
        $provider = new MessagingServiceProvider($this->makeApp());

        // Act + Assert
        $provider->register();
        $this->addToAssertionCount(1);
    }

    /**
     * MessagingServiceProvider::boot() must not throw.
     */
    public function testMessagingServiceProviderBoot(): void
    {
        // Arrange
        $provider = new MessagingServiceProvider($this->makeApp());

        // Act + Assert
        $provider->boot();
        $this->addToAssertionCount(1);
    }

    // ── QueueServiceProvider ──────────────────────────────────────────────────

    /**
     * QueueServiceProvider::register() must not throw.
     */
    public function testQueueServiceProviderRegister(): void
    {
        // Arrange
        $provider = new QueueServiceProvider($this->makeApp());

        // Act + Assert
        $provider->register();
        $this->addToAssertionCount(1);
    }

    /**
     * QueueServiceProvider::boot() must not throw.
     */
    public function testQueueServiceProviderBoot(): void
    {
        // Arrange
        $provider = new QueueServiceProvider($this->makeApp());

        // Act + Assert
        $provider->boot();
        $this->addToAssertionCount(1);
    }
}
