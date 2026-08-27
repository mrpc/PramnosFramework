<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\EmailSecondFactor;

/**
 * Which second-factor methods an application allows.
 *
 * The half of the email factor that has no database in it: whether the method exists at
 * all for this installation. It is a security decision expressed as configuration, so the
 * failure modes worth pinning are the ones where a typo silently changes what a login
 * asks for.
 */
#[CoversClass(EmailSecondFactor::class)]
class EmailSecondFactorConfigTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            $reflection = new \ReflectionProperty(Application::class, 'appInstances');
            $reflection->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /**
     * Install an applicationInfo for the class to read.
     *
     * @param array<string,mixed> $info
     */
    private function withApplicationInfo(array $info): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = $info;

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $instances  = $reflection->getValue() ?? [];
        $this->savedInstances = $instances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * An application that has never heard of the key gets what it always had.
     *
     * The default is the whole compatibility story: adding this feature must not change
     * what a single existing installation asks its users for.
     */
    public function testTheDefaultIsTotpOnly(): void
    {
        // Arrange
        $this->withApplicationInfo([]);

        // Act & Assert
        $this->assertSame(['totp'], EmailSecondFactor::allowedMethods());
        $this->assertFalse(EmailSecondFactor::isAvailable());
    }

    /**
     * Declaring the method makes it available.
     */
    public function testDeclaringEmailMakesItAvailable(): void
    {
        // Arrange
        $this->withApplicationInfo(['auth' => ['twofactor_methods' => ['totp', 'email']]]);

        // Act & Assert
        $this->assertTrue(EmailSecondFactor::isAvailable());
        $this->assertSame(['totp', 'email'], EmailSecondFactor::allowedMethods());
    }

    /**
     * Case and duplicates do not decide whether a factor exists.
     *
     * `EMAIL` in a config file is a person writing what they mean, not a different
     * method, and a list that repeats itself must not make the method twice as available
     * — the list is rendered by a screen.
     */
    public function testTheListIsNormalised(): void
    {
        // Arrange
        $this->withApplicationInfo(['auth' => ['twofactor_methods' => ['EMAIL', 'email', 'Totp']]]);

        // Act
        $methods = EmailSecondFactor::allowedMethods();

        // Assert
        $this->assertTrue(EmailSecondFactor::isAvailable());
        $this->assertSame(['email', 'totp'], $methods);
    }

    /**
     * `totp` is in the list whether or not it was written there.
     *
     * An application cannot switch off the method its existing accounts are already
     * enrolled in by adding a configuration key — which is what omitting it from the list
     * would otherwise mean, and it would lock every enrolled account out of its own
     * login on deploy.
     */
    public function testTotpCannotBeConfiguredAway(): void
    {
        // Arrange
        $this->withApplicationInfo(['auth' => ['twofactor_methods' => ['email']]]);

        // Act & Assert
        $this->assertContains('totp', EmailSecondFactor::allowedMethods());
    }

    /**
     * A value that is not a list of names is ignored rather than half-read.
     *
     * `'twofactor_methods' => 'email'` is the shape somebody writes on the first try.
     * Read loosely it would enable the method; ignored, it fails safe and the setting
     * visibly does nothing, which is the version somebody notices and fixes.
     */
    public function testAMalformedDeclarationIsIgnored(): void
    {
        // Arrange
        $this->withApplicationInfo(['auth' => ['twofactor_methods' => 'email']]);

        // Act & Assert
        $this->assertSame(['totp'], EmailSecondFactor::allowedMethods());
        $this->assertFalse(EmailSecondFactor::isAvailable());
    }

    /**
     * Entries that cannot name a method are dropped, and the rest still count.
     */
    public function testUnusableEntriesAreDropped(): void
    {
        // Arrange
        $this->withApplicationInfo(['auth' => ['twofactor_methods' => ['', 42, 'email', null]]]);

        // Act
        $methods = EmailSecondFactor::allowedMethods();

        // Assert
        $this->assertSame(['email', 'totp'], $methods);
        $this->assertTrue(EmailSecondFactor::isAvailable());
    }
}
