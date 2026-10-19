<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Loginlockout;
use Pramnos\Auth\SecurityPolicy;
use Pramnos\Database\Database;

/**
 * A login lockout whose table cannot be reached (FW-164 §2): by default sign-ins go on without
 * it — it is a second line against guessing, and refusing every sign-in because it is missing is
 * an outage — and with `lockout_fails_closed` they are refused.
 */
#[CoversClass(Loginlockout::class)]
#[CoversClass(SecurityPolicy::class)]
final class LoginlockoutUnavailableTest extends TestCase
{
    private mixed $originalDb = null;

    private ?array $savedApps = null;

    protected function setUp(): void
    {
        $dbRef            = &Database::getInstance();
        $this->originalDb = $dbRef;
        $broken = $this->createMock(Database::class);
        $broken->method('queryBuilder')->willThrowException(new \RuntimeException('Table authserver.loginlockouts does not exist'));
        $dbRef = $broken;
        $this->savedApps = (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->getValue() ?? [];
    }

    protected function tearDown(): void
    {
        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->savedApps);
    }

    /** An application with the given `auth.security` switches. */
    private static function security(array $switches): void
    {
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['auth' => ['security' => $switches]];
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, ['default' => $app]);
    }

    /** By default every call goes on: not locked, nothing recorded, nothing thrown. */
    public function testItFailsOpenByDefault(): void
    {
        // Arrange
        self::security([]);
        $lockout = new Loginlockout();

        // Act
        $status = $lockout->getLockoutStatus('identifier', 'alice');
        $lockout->recordFailedAttempt('identifier', 'alice');
        $lockout->recordFailedAttemptWithin('ip', '10.0.0.1', 60, 5);
        $lockout->clearSuccessfulLoginState('identifier', 'alice');

        // Assert
        $this->assertSame(['locked' => false, 'remaining' => 0], $status);
    }

    /** With `lockout_fails_closed`, the failure reaches the sign-in, which refuses. */
    public function testItCanBeMadeToRefuse(): void
    {
        // Arrange
        self::security(['lockout_fails_closed' => true]);

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        (new Loginlockout())->getLockoutStatus('identifier', 'alice');
    }
}
