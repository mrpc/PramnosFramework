<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\SecurityPolicy;

/**
 * The account-security switches, and the promise that they are off until asked for.
 *
 * That promise is the reason this class exists rather than the behaviours simply being
 * turned on: this framework is shared by applications that did not ask for any of it, and
 * several of these end sessions, refuse passwords or send mail. A silent change to those on
 * an upgrade is an incident.
 *
 * So the test that matters most here is the boring one — **every switch defaults to what
 * the framework did before it existed** — followed by the ones that stop a half-written
 * configuration from meaning something dangerous.
 */
#[CoversClass(SecurityPolicy::class)]
class SecurityPolicyTest extends TestCase
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

    /** @param array<string,mixed> $security */
    private function withSecurity(array $security): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['security' => $security]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $instances  = $reflection->getValue() ?? [];
        $this->savedInstances = $instances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * Nothing is on until an application says so.
     */
    public function testEverySwitchIsOffByDefault(): void
    {
        // Arrange — an application that has never heard of any of this
        $this->withSecurity([]);

        // Act & Assert
        $this->assertFalse(SecurityPolicy::regeneratesSessionOnLogin());
        $this->assertNull(SecurityPolicy::ipRateLimit());
        $this->assertFalse(SecurityPolicy::notifiesSecurityChanges());
        $this->assertSame(0, SecurityPolicy::sessionIdleTimeout());
        $this->assertSame(0, SecurityPolicy::sessionAbsoluteTimeout());
        $this->assertFalse(SecurityPolicy::revokesSessionsOnPasswordChange());
        $this->assertSame(0, SecurityPolicy::passwordHistory());
        $this->assertFalse(SecurityPolicy::cachesTotpReplays());
        $this->assertSame(0, SecurityPolicy::secondFactorFromUsertype());
    }

    /**
     * And with no application at all — a console command, a worker, a test.
     *
     * The accessors must not require a booted application to answer, or every CLI entry
     * point would have to build one to ask a question whose answer is "no".
     */
    public function testWithNoApplicationTheDefaultsStillAnswer(): void
    {
        // Arrange
        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $reflection->setValue(null, []);

        // Act & Assert
        $this->assertFalse(SecurityPolicy::regeneratesSessionOnLogin());
        $this->assertNull(SecurityPolicy::ipRateLimit());
    }

    /**
     * A switch an application turns on is on.
     */
    public function testDeclaredSwitchesAreHonoured(): void
    {
        // Arrange
        $this->withSecurity([
            'regenerate_session_on_login'        => true,
            'notify_security_changes'            => true,
            'revoke_sessions_on_password_change' => true,
            'totp_replay_cache'                  => true,
            'session_idle_timeout'               => 3600,
            'session_absolute_timeout'           => 2592000,
            'password_history'                   => 5,
            'require_second_factor_from_usertype' => 90,
        ]);

        // Act & Assert
        $this->assertTrue(SecurityPolicy::regeneratesSessionOnLogin());
        $this->assertTrue(SecurityPolicy::notifiesSecurityChanges());
        $this->assertTrue(SecurityPolicy::revokesSessionsOnPasswordChange());
        $this->assertTrue(SecurityPolicy::cachesTotpReplays());
        $this->assertSame(3600, SecurityPolicy::sessionIdleTimeout());
        $this->assertSame(2592000, SecurityPolicy::sessionAbsoluteTimeout());
        $this->assertSame(5, SecurityPolicy::passwordHistory());
        $this->assertSame(90, SecurityPolicy::secondFactorFromUsertype());
    }

    /**
     * `true` is accepted for the rate limit, because a boolean is what somebody writes.
     *
     * A switch whose documented value is an array will be written as `true` by the first
     * person who reaches for it, and refusing that would make the feature silently absent —
     * the reading nobody notices. The defaults it stands for are named in the guide.
     */
    public function testTheRateLimitAcceptsABooleanShorthand(): void
    {
        // Arrange
        $this->withSecurity(['ip_rate_limit' => true]);

        // Act
        $limit = SecurityPolicy::ipRateLimit();

        // Assert
        $this->assertSame(['attempts' => 30, 'window' => 900], $limit);
    }

    /**
     * Explicit numbers win, and each may be given on its own.
     */
    public function testTheRateLimitTakesItsOwnNumbers(): void
    {
        // Arrange
        $this->withSecurity(['ip_rate_limit' => ['attempts' => 5, 'window' => 60]]);

        // Act & Assert
        $this->assertSame(['attempts' => 5, 'window' => 60], SecurityPolicy::ipRateLimit());

        // …and a partial declaration keeps the documented default for the other half
        $this->withSecurity(['ip_rate_limit' => ['attempts' => 5]]);
        $this->assertSame(['attempts' => 5, 'window' => 900], SecurityPolicy::ipRateLimit());
    }

    /**
     * A rate limit configured to nonsense is off, not infinitely strict.
     *
     * `attempts => 0` read literally is "refuse the first attempt", which locks out every
     * user of the site. Off is the only safe reading of a value that cannot have been
     * meant, and it is visible: nobody is locked out and the setting demonstrably does
     * nothing.
     */
    public function testAnImpossibleRateLimitIsOff(): void
    {
        foreach ([['attempts' => 0, 'window' => 900], ['attempts' => 30, 'window' => 0]] as $configured) {
            // Arrange
            $this->withSecurity(['ip_rate_limit' => $configured]);

            // Act & Assert
            $this->assertNull(SecurityPolicy::ipRateLimit());
        }
    }

    /**
     * Negative timeouts and counts are off rather than negative.
     */
    public function testNegativeNumbersAreTreatedAsOff(): void
    {
        // Arrange
        $this->withSecurity([
            'session_idle_timeout' => -1,
            'password_history'     => -5,
            'require_second_factor_from_usertype' => -90,
        ]);

        // Act & Assert
        $this->assertSame(0, SecurityPolicy::sessionIdleTimeout());
        $this->assertSame(0, SecurityPolicy::passwordHistory());
        $this->assertSame(0, SecurityPolicy::secondFactorFromUsertype());
    }
}
