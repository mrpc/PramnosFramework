<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * The `exit` that does not fire under PHPUnit.
 *
 * `terminate()` ends the request after Adminer has served its own page — there is nothing for the
 * framework to render afterwards, and letting the response continue would append a theme to a page
 * Adminer already closed.
 *
 * Under a test run it returns instead, because `exit` there takes the runner with it. Four
 * statements, never executed, and the thing worth asserting is exactly that: **this test existing
 * and finishing is the assertion.** A `terminate()` that lost its guard would not fail — it would
 * end the whole suite at whichever test reached it first, with no failure and no report.
 */
#[CoversClass(Adminer::class)]
class AdminerTerminateTest extends TestCase
{
    /** Exposes the one method under test. */
    private function controller(): object
    {
        return new class extends Adminer {
            public function __construct() {}

            public function exposeTerminate(): void
            {
                $this->terminate();
            }
        };
    }

    /**
     * Calling it returns, and the test run carries on.
     *
     * The assertion after the call is what proves it: reaching it at all means `exit` did not
     * fire. Without the guard this file would kill the suite here.
     */
    public function testItReturnsUnderPhpunitRatherThanEndingTheProcess(): void
    {
        // Act
        $this->controller()->exposeTerminate();

        // Assert — reached, which is the whole point
        $this->assertTrue(true, 'terminate() returned instead of exiting');
    }

    /**
     * And twice, because a guard that only worked once would still be a guard that worked.
     *
     * Cheap, and it rules out an implementation that sets a flag and exits on the second call —
     * which is the shape somebody reaches for when "only exit in production" is added later.
     */
    public function testItCanBeCalledMoreThanOnce(): void
    {
        // Arrange
        $controller = $this->controller();

        // Act
        $controller->exposeTerminate();
        $controller->exposeTerminate();

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * The guard reads a constant PHPUnit itself defines.
     *
     * Rather than an environment variable or a setting somebody could forget to set: the condition
     * is true exactly when a test run is in progress, which is the only situation the
     * accommodation is for.
     */
    public function testTheGuardIsAConstantTheTestRunnerDefines(): void
    {
        // Assert
        $this->assertTrue(
            defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__'),
            'neither PHPUnit constant is defined, so the guard cannot be relying on them'
        );
    }
}
