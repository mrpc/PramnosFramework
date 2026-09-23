<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\Middleware\SessionTrackingMiddleware;
use Pramnos\Http\Request;

/**
 * An Application that can be made the current one without booting anything.
 *
 * `currentInstance()` reads two protected statics, which is the whole of what the
 * middleware needs to ask a question — the alternative is a constructor that reaches
 * a database to test an if-statement.
 */
class TrackingOptOutApp extends Application
{
    public function __construct()
    {
    }

    /** @param array<string, mixed> $info */
    public static function makeCurrent(array $info): self
    {
        $app = new self();
        $app->applicationInfo = $info;

        self::$appInstances['default']  = $app;
        self::$lastUsedApplication      = 'default';

        return $app;
    }

    public static function clear(): void
    {
        self::$appInstances        = [];
        self::$lastUsedApplication = null;
    }
}

/** Records whether the tracking ran, instead of writing cookies and rows. */
class RecordingTrackingMiddleware extends SessionTrackingMiddleware
{
    public int $tracked = 0;

    public function track(Request $request): void
    {
        $this->tracked++;
    }
}

/**
 * `session_tracking => false` must stop the middleware, not only the auto-registration.
 *
 * The Page Cache Guide gives two settings together as the fix for a page that will not
 * cache:
 *
 * ```php
 * 'session'          => 'lazy',
 * 'session_tracking' => false,
 * ```
 *
 * The first worked. The second was read by `Application::bootSessionTracking()` alone —
 * the path that registers the tracker when nothing else has — so an application that
 * listed `SessionTrackingMiddleware` in `middleware` ran it through the pipeline, and the
 * pipeline asked nobody. The setting did nothing for exactly the installations that had
 * wired the tracker on purpose and then wanted it off.
 *
 * **And the symptom of the failure is the symptom it was meant to cure**: `Set-Cookie` on
 * every response, nothing stored in the page cache, no error anywhere. Somebody following
 * that page had no way to tell which of the two halves had failed.
 */
class SessionTrackingOptOutTest extends TestCase
{
    protected function tearDown(): void
    {
        TrackingOptOutApp::clear();
    }

    /** Runs the middleware through its pipeline contract and reports whether it tracked. */
    private function ranTrackingWith(array $info): bool
    {
        TrackingOptOutApp::makeCurrent($info);

        $middleware = new RecordingTrackingMiddleware();
        $next       = static fn(Request $request): string => 'downstream';

        // Act — the pipeline calls handle(), never track()
        $result = $middleware->handle(Request::getInstance(), $next);

        // The request must reach the rest of the pipeline either way: declining to
        // track is not declining to serve the page.
        $this->assertSame('downstream', $result);

        return $middleware->tracked > 0;
    }

    /**
     * The finding: the key switches the middleware off.
     */
    public function testTheKeyStopsTheMiddleware(): void
    {
        // Arrange + Act + Assert
        $this->assertFalse(
            $this->ranTrackingWith(['session_tracking' => false]),
            'session_tracking => false was read by nothing on this path'
        );
    }

    /**
     * An application that never mentions the key is unchanged.
     *
     * The only acceptable default for a middleware somebody registered on purpose, and
     * the one this could most easily have got wrong: `getSetting()` answers `false` for
     * an absent key, so reading it without an explicit `null` would have switched
     * tracking off for every installation on upgrade.
     */
    public function testAnApplicationThatSaysNothingStillTracks(): void
    {
        // Arrange + Act + Assert
        $this->assertTrue($this->ranTrackingWith([]));
    }

    /**
     * Asking for it explicitly keeps it on.
     */
    public function testAskingForTrackingKeepsIt(): void
    {
        // Arrange + Act + Assert
        $this->assertTrue($this->ranTrackingWith(['session_tracking' => true]));
    }

    /**
     * The falsey spellings a configuration file actually contains.
     *
     * `app.php` is PHP, but the value may have come from an env var or a settings row,
     * where everything is a string — and `'0'` is truthy in none of the places a person
     * expects and truthy in PHP's `if`.
     */
    public function testTheFalseySpellingsAreHonoured(): void
    {
        foreach ([false, 0, '0', 'false', 'no', 'off', ''] as $value) {
            // Act + Assert
            $this->assertFalse(
                $this->ranTrackingWith(['session_tracking' => $value]),
                'tracking continued for ' . var_export($value, true)
            );
        }
    }

    /**
     * With no application to ask, the middleware tracks.
     *
     * A unit test or a console process has no current Application. The middleware only
     * runs where somebody registered it, and a registered middleware that quietly
     * declines to work is the harder of the two things to debug.
     */
    public function testWithNoApplicationItTracks(): void
    {
        // Arrange — nothing is current
        TrackingOptOutApp::clear();
        $middleware = new RecordingTrackingMiddleware();

        // Act
        $middleware->handle(Request::getInstance(), static fn(Request $r): string => 'x');

        // Assert
        $this->assertSame(1, $middleware->tracked);
    }
}
