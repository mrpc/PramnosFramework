<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApplicationClosedException;
use Pramnos\DevPanel\DevPanelController;

/**
 * The dev panel must not end a PHPUnit run.
 *
 * `terminate()` was a bare `exit`, called from fifteen places in the panel. A test that
 * requested any devpanel URL therefore stopped the process: **no summary, no failure
 * count, and exit status 0** — which every CI reads as a pass. It was found by a sweep
 * over a scaffolded project's screens that simply stopped mid-list, and only because the
 * sweep was appending to a file as it went.
 *
 * A truncated suite that reports success is worse than a failing one, which is why the
 * framework already has the seam: `TestEnvironment::setup()` defines `PRAMNOS_TESTING` so
 * `Application::close()` throws instead of exiting — its own comment says "without this a
 * single database fault silently truncates the whole suite". This path never reached
 * `close()`.
 *
 * `PRAMNOS_TESTING` is defined for this suite by its bootstrap, so these tests exercise
 * the branch a project's suite takes.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelTerminateTest extends TestCase
{
    /** The panel with its protected terminator reachable. */
    private function panel(): object
    {
        return new class extends DevPanelController {
            public function __construct()
            {
                // terminate() reads a constant and the response code, nothing else.
            }

            public function end(): void
            {
                $this->terminate();
            }
        };
    }

    /**
     * It throws rather than exiting, so the run continues and reports.
     *
     * If this regresses, the failure mode is not a red test — it is this file being the
     * last thing PHPUnit ever prints.
     */
    public function testItThrowsInsteadOfEndingTheProcess(): void
    {
        // Arrange
        $this->assertTrue(defined('PRAMNOS_TESTING'), 'the suite bootstrap defines it');

        // Act & Assert
        $this->expectException(ApplicationClosedException::class);
        $this->panel()->end();
    }

    /**
     * The same type the rest of the framework throws when it ends a request.
     *
     * A caller already catching `ApplicationClosedException` around `close()` catches this
     * too; a bespoke exception here would have meant every test harness learning a second
     * name for the same event.
     */
    public function testItCarriesTheStatusTheCallerHadSet(): void
    {
        // Arrange — the panel's own error path sets the code before terminating.
        http_response_code(403);

        try {
            // Act
            $this->panel()->end();
            $this->fail('terminate() must not return');
        } catch (ApplicationClosedException $exception) {
            // Assert
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            http_response_code(200);
        }
    }
}
