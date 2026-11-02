<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Logs\Logger;

/**
 * `Controller::releaseSessionOnRead()` — FW-309.
 *
 * PHP's file session handler holds a write lock for the whole request. An admin UI where
 * one session has 20-30 tabs open serializes all of them behind whichever one is slowest
 * (a video stream, a long poll) — measured in production at up to 150s. A controller that
 * opts in releases the lock before any action outside its declared {@see Controller::addWriteAction()}
 * list runs, so those requests stop queueing behind each other.
 *
 * A write action keeps the lock for its whole run, since it may need to write `$_SESSION`
 * itself — and a controller that never opts in is unaffected, same as before this feature.
 */
#[CoversClass(Controller::class)]
class ControllerReleaseSessionOnReadTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::$requestMethod = 'GET';
        // Every test starts from a real, active session: start() is idempotent once a
        // session id exists, and exec() needs an active session to have anything to release.
        \Pramnos\Http\Session::getInstance()->start();
    }

    protected function tearDown(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::$requestMethod = 'GET';
    }

    /** A controller with a declared write action and the opt-in flag, for every test here. */
    private function controller(bool $optIn): object
    {
        return new class ($optIn) extends Controller {
            public function __construct(bool $optIn)
            {
                $this->addAuthAction(['show', 'save']);
                $this->addWriteAction('save');
                if ($optIn) {
                    $this->releaseSessionOnRead();
                }
            }

            public function show(): string
            {
                return 'shown';
            }

            public function save(): string
            {
                return 'saved';
            }

            public function auth($action)
            {
                return true;
            }
        };
    }

    /**
     * Opted in: a read action (`show`, not in `writeActions`) releases the session lock
     * before it runs, so a slow sibling request no longer blocks behind it.
     */
    public function testOptedInReadActionReleasesTheLock(): void
    {
        // Arrange
        $controller = $this->controller(optIn: true);

        // Act
        $result = $controller->exec('show');

        // Assert
        $this->assertSame('shown', $result);
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'the lock is released, not just untouched');
    }

    /**
     * Opted in: a declared write action keeps the lock for its whole run, since it may
     * still need to write `$_SESSION` itself.
     */
    public function testOptedInWriteActionKeepsTheLock(): void
    {
        // Arrange
        $this->post();
        $session = \Pramnos\Http\Session::getInstance();
        $ref     = new \ReflectionObject($session);
        $name    = (string) $ref->getProperty('_token')->getValue($session);
        $_POST[$name] = $session->getFingerprint();
        $controller = $this->controller(optIn: true);

        // Act
        $result = $controller->exec('save');

        // Assert
        $this->assertSame('saved', $result);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'a write action is not released early');
    }

    /** Not opted in: behaviour is exactly what it was before this feature — lock held throughout. */
    public function testWithoutOptInTheLockIsUnaffected(): void
    {
        // Arrange
        $controller = $this->controller(optIn: false);

        // Act
        $result = $controller->exec('show');

        // Assert
        $this->assertSame('shown', $result);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'default behaviour is unchanged');
    }

    /**
     * The safety net: if a read action writes to `$_SESSION` anyway after the lock was
     * released, that write is lost silently (PHP gives no warning of it) — so the framework
     * logs it instead of letting it vanish unnoticed. Exercised directly via reflection,
     * since the real mechanism only runs at `register_shutdown_function()` time.
     */
    public function testASessionWriteAfterReleaseIsLogged(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);
        $controller = $this->controller(optIn: true);
        $before     = $_SESSION;

        // Act — simulate the action writing to the session after the lock was released
        $_SESSION['late_write'] = 'should not persist';
        $ref    = new \ReflectionObject($controller);
        $method = $ref->getMethod('_warnIfSessionChangedAfterRelease');
        $method->invoke($controller, 'show', $before);

        // Assert
        rewind($stream);
        $logged = stream_get_contents($stream);
        fclose($stream);
        Logger::setOutputMode(Logger::OUTPUT_FILE);
        $this->assertStringContainsString('released the session lock', $logged);
        $this->assertStringContainsString('show', $logged);
        unset($_SESSION['late_write']);
    }

    private function post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST = [];
    }
}
