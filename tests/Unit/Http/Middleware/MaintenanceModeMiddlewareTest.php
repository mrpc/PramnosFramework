<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\MaintenanceModeMiddleware;
use Pramnos\Http\Request;

/**
 * The middleware watches the flag the framework actually raises.
 *
 * This class defaulted to `<ROOT>/maintenance.flag`, while
 * `Application::startMaintenance()` and `MigrationRunner` both raise
 * `<ROOT>/var/MAINTENANCE`. The two never met.
 *
 * The consequence is the one worth writing a test class for: an application that
 * registered this middleware exactly as the guide showed, and then ran a migration,
 * served the entire migration from the live site. Nothing appeared wrong — the
 * middleware was in the pipeline, the runner had "enabled maintenance mode", and the
 * two facts were about different files.
 *
 * So the assertions here are about **which paths are watched**, not merely that some
 * flag stops a request. A test of the latter would have passed for the whole time the
 * defect existed, which is precisely the failure mode being guarded against.
 */
class MaintenanceModeMiddlewareTest extends TestCase
{
    /** @var array<int, string> Files created by a test, removed in tearDown */
    private array $created = [];

    /**
     * Removes any flag a test raised.
     *
     * These are real files at the repository root; one left behind would put every
     * later test — and the developer's next request — into maintenance mode.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->created = [];
    }

    /**
     * Creates a flag file and registers it for cleanup.
     *
     * @param  string $path Absolute path
     * @return void
     */
    private function raiseFlag(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, 'maintenance');
        $this->created[] = $path;
    }

    /**
     * Runs the middleware and reports whether it stopped the request.
     *
     * @param  MaintenanceModeMiddleware $middleware The middleware
     * @return int|null The exception code, or null when the request passed through
     */
    private function dispatch(MaintenanceModeMiddleware $middleware): ?int
    {
        try {
            $middleware->handle(new Request(), fn(): string => 'passed through');
        } catch (\Exception $e) {
            return $e->getCode();
        }

        return null;
    }

    /**
     * By default both framework flag paths are watched.
     *
     * The order matters less than the membership, but `var/MAINTENANCE` is listed
     * first because it is the one the framework itself raises — the case that was
     * broken.
     *
     * @return void
     */
    public function testDefaultWatchesBothFrameworkFlags(): void
    {
        // Act
        $watched = (new MaintenanceModeMiddleware())->flagFiles();

        // Assert
        $this->assertCount(2, $watched);
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'MAINTENANCE',
            $watched[0],
            'The flag startMaintenance() and MigrationRunner raise must be watched.'
        );
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'maintenance.flag',
            $watched[1],
            'The original default must remain, or existing deployments break.'
        );
    }

    /**
     * The flag the framework raises stops the request.
     *
     * This is the regression: before the fix, `var/MAINTENANCE` existing changed
     * nothing about a routed request.
     *
     * @return void
     */
    public function testFrameworkFlagStopsTheRequest(): void
    {
        // Arrange
        $this->raiseFlag(ROOT . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'MAINTENANCE');

        // Act & Assert
        $this->assertSame(503, $this->dispatch(new MaintenanceModeMiddleware()));
    }

    /**
     * The original flag still stops the request.
     *
     * The guard against fixing one deployment by breaking another.
     *
     * @return void
     */
    public function testOriginalFlagStillStopsTheRequest(): void
    {
        // Arrange
        $this->raiseFlag(ROOT . DIRECTORY_SEPARATOR . 'maintenance.flag');

        // Act & Assert
        $this->assertSame(503, $this->dispatch(new MaintenanceModeMiddleware()));
    }

    /**
     * With no flag at all the request passes through untouched.
     *
     * @return void
     */
    public function testWithoutAFlagTheRequestPassesThrough(): void
    {
        // Act & Assert
        $this->assertNull($this->dispatch(new MaintenanceModeMiddleware()));
    }

    /**
     * An explicit path means that path and nothing else.
     *
     * An application that named its own file has said which file it means; silently
     * adding two more would take a deployment out of the operator's hands — and it
     * would be the framework deciding the site is down.
     *
     * @return void
     */
    public function testAnExplicitPathIsTheOnlyOneWatched(): void
    {
        // Arrange — a framework flag is up, but the middleware was told a different file
        $this->raiseFlag(ROOT . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'MAINTENANCE');
        $custom = sys_get_temp_dir() . '/pramnos_custom_' . bin2hex(random_bytes(4)) . '.flag';

        // Act
        $middleware = new MaintenanceModeMiddleware($custom);

        // Assert — only the named path, and it does not stop the request
        $this->assertSame([$custom], $middleware->flagFiles());
        $this->assertNull($this->dispatch($middleware));
    }

    /**
     * An explicit path that exists does stop the request.
     *
     * Pairs with the test above: together they prove the explicit path is honoured
     * rather than ignored.
     *
     * @return void
     */
    public function testAnExplicitPathStopsTheRequestWhenItExists(): void
    {
        // Arrange
        $custom = sys_get_temp_dir() . '/pramnos_custom_' . bin2hex(random_bytes(4)) . '.flag';
        $this->raiseFlag($custom);

        // Act & Assert
        $this->assertSame(503, $this->dispatch(new MaintenanceModeMiddleware($custom)));
    }
}
