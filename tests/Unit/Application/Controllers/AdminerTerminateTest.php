<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApplicationClosedException;
use Pramnos\Application\Controller;
use Pramnos\Application\Controllers\Adminer;

/**
 * The `exit` that does not fire under PHPUnit.
 *
 * `terminate()` ends the request after Adminer has served its own page — there is nothing for the
 * framework to render afterwards, and letting the response continue would append a theme to a page
 * Adminer already closed.
 *
 * Under a test run it **throws** instead, because `exit` there takes the runner with it: no
 * summary, no failure count, and exit status 0, which every CI reads as a pass. This test
 * finishing at all is half the assertion — a `terminate()` that lost its guard would not fail, it
 * would end the whole suite at whichever test reached it first, silently.
 *
 * It used to `return` instead, and that is the half this class now pins. Returning lets the code
 * after the call run in a request that had already decided it was finished, which is a quieter bug
 * than the one being avoided. Throwing `ApplicationClosedException` matches `Application::close()`
 * and {@see Controller::terminate()}, where the behaviour now lives once for every controller
 * rather than in eight copies with four behaviours between them.
 */
#[CoversClass(Adminer::class)]
#[CoversClass(Controller::class)]
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
     * Calling it throws, and the test run carries on.
     *
     * The type is the assertion, not merely that something was thrown: a caller that already
     * catches `ApplicationClosedException` around `Application::close()` catches this too, which
     * is the whole reason for reusing it rather than inventing a second exception.
     */
    public function testItThrowsUnderPhpunitRatherThanEndingTheProcess(): void
    {
        // Assert
        $this->expectException(ApplicationClosedException::class);

        // Act
        $this->controller()->exposeTerminate();
    }

    /**
     * The exception names the class that ended the request.
     *
     * With eight call sites collapsed into one base-class method, "terminate() called" on its own
     * no longer says which controller did it. `static::class` does, and a test run that stops
     * somewhere unexpected is exactly when that matters.
     */
    public function testTheExceptionNamesTheControllerThatEndedTheRequest(): void
    {
        // Act
        try {
            $this->controller()->exposeTerminate();
            $this->fail('terminate() did not throw');
        } catch (ApplicationClosedException $e) {
            // Assert — the anonymous subclass reports its parent's name in its own
            $this->assertStringContainsString('Adminer', $e->getMessage());
            $this->assertStringContainsString('terminate() called', $e->getMessage());
        }
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
        $thrown     = 0;

        // Act
        foreach ([1, 2] as $ignored) {
            try {
                $controller->exposeTerminate();
            } catch (ApplicationClosedException) {
                $thrown++;
            }
        }

        // Assert
        $this->assertSame(2, $thrown, 'the second call did not end the request');
    }

    /**
     * The guard reads constants the test runner defines.
     *
     * Rather than an environment variable or a setting somebody could forget to set: the condition
     * is true exactly when a test run is in progress, which is the only situation the
     * accommodation is for. Three of them, because `PRAMNOS_TESTING` covers a project using the
     * framework's own bootstrap and the two PHPUnit constants cover one that is not — and a
     * project running PHPUnit without our bootstrap is the case that got this wrong.
     */
    public function testTheGuardIsAConstantTheTestRunnerDefines(): void
    {
        // Assert
        $this->assertTrue(
            defined('PRAMNOS_TESTING')
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__'),
            'no test-runner constant is defined, so the guard cannot be relying on them'
        );
    }
}
