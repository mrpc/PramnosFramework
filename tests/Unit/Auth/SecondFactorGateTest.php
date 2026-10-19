<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\SecondFactorGate;
use Pramnos\Auth\SecurityPolicy;

/** A login flow whose answer about the second factor a test decides, counting the questions. */
final class GateTestFlow extends LoginFlow
{
    public bool $owes = true;

    public int $asked = 0;

    /** @var (callable(): void)|null Something to do while deciding — ask the gate again, say. */
    public $during = null;

    public function __construct()
    {
    }

    /** The test's answer. */
    public function demandSecondFactor(int $userId): bool
    {
        $this->asked++;
        if ($this->during !== null) {
            ($this->during)();
        }

        return $this->owes;
    }
}

/**
 * The session-wide gate (FW-164): with `auth.security.second_factor_everywhere` on, a session
 * signed in without its second factor is not counted as signed in, wherever it came from.
 */
#[CoversClass(SecondFactorGate::class)]
#[CoversClass(SecurityPolicy::class)]
final class SecondFactorGateTest extends TestCase
{
    private ?array $saved = null;

    protected function setUp(): void
    {
        $_SESSION = ['logged' => true, 'uid' => 7];
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->saved = $ref->getValue() ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->saved);
    }

    /** An application with the gate on or off. */
    private static function gate(bool $on): void
    {
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['auth' => ['security' => ['second_factor_everywhere' => $on]]];
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, ['default' => $app]);
    }

    /**
     * Off, nothing is asked. On: a session settled for its account passes without a question; an
     * unsettled one that owes a factor is no longer signed in; one that owes none passes.
     */
    public function testWhoPasses(): void
    {
        // Arrange
        $flow = new GateTestFlow();

        // Act + Assert
        self::gate(false);
        $this->assertTrue(SecondFactorGate::passes(7, $flow));
        $this->assertSame(0, $flow->asked, 'off: not even asked');

        self::gate(true);
        SecondFactorGate::settle(7);
        $this->assertTrue(SecondFactorGate::passes(7, $flow));
        $this->assertSame(0, $flow->asked, 'settled: not asked again');

        unset($_SESSION[SecondFactorGate::SESSION]);
        $flow->owes = false;
        $this->assertTrue(SecondFactorGate::passes(7, $flow), 'owes nothing');

        $flow->owes = true;
        $this->assertFalse(SecondFactorGate::passes(7, $flow), 'owes its factor');
        $this->assertFalse($_SESSION['logged']);
        $this->assertSame(0, $_SESSION['uid'], 'no longer signed in');
    }

    /** A question the deciding asks about the session does not ask the gate again. */
    public function testItDoesNotAskItselfWhileDeciding(): void
    {
        // Arrange
        self::gate(true);
        $flow  = new GateTestFlow();
        $inner = null;
        $flow->during = static function () use (&$inner, $flow): void {
            $inner = SecondFactorGate::passes(7, $flow);
        };

        // Act
        $outer = SecondFactorGate::passes(7, $flow);

        // Assert
        $this->assertTrue($inner, 'inside the decision: let through, not asked again');
        $this->assertSame(1, $flow->asked);
        $this->assertFalse($outer);
    }
}
