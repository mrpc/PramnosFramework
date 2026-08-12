<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Http\Middleware\SessionTrackingMiddleware;

/**
 * Exposes the garbage-collection decision, which is protected.
 */
class GarbageCollectionProbe extends SessionTrackingMiddleware
{
    /** No constructor: the decision reads only a setting. */
    public function __construct()
    {
    }

    public function decide(): bool
    {
        return $this->shouldCollectGarbage();
    }

    public function wouldRecord(string $url, bool $isNavigation = true): bool
    {
        return $this->shouldRecordVisit($url, $isNavigation);
    }

    public function looksLikeNavigation(): bool
    {
        return $this->isNavigation(new \Pramnos\Http\Request());
    }
}

/**
 * What session tracking costs a request that is not a page view.
 *
 * Every request through this middleware issued a `DELETE FROM sessions WHERE
 * time < …`, so a page making ten API calls issued ten identical sweeps, each
 * scanning the same rows to find nothing. Rows go stale five minutes after
 * their last request and nothing reads a stale row, so how promptly they are
 * removed does not matter — only that somebody does it eventually.
 */
#[CoversClass(SessionTrackingMiddleware::class)]
class SessionTrackingCostTest extends TestCase
{
    /** @var mixed The divisor as the suite had it */
    private $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Settings::getSetting('session_gc_divisor');
    }

    protected function tearDown(): void
    {
        Settings::setSetting('session_gc_divisor', $this->original, false);
        Settings::setSetting('session_write_interval', false, false);
        unset($_SESSION['_session_written']);
        parent::tearDown();
    }

    /**
     * The same URL within the interval is not written again.
     *
     * A page that loads and then calls its own API wrote the row twice a second
     * apart, with the same values; a page making ten XHR calls wrote it ten
     * times. The row says who is online and what they are looking at, and it is
     * already allowed to be five minutes stale.
     */
    public function testTheSameUrlIsNotRecordedTwiceInAMinute(): void
    {
        // Arrange
        unset($_SESSION['_session_written']);
        $probe = new GarbageCollectionProbe();

        // Act & Assert
        $this->assertTrue($probe->wouldRecord('/users'), 'the first visit is recorded');
        $this->assertFalse($probe->wouldRecord('/users'), 'the second is not');
        $this->assertFalse($probe->wouldRecord('/users'));
    }

    /**
     * A navigation to a new page is recorded at once.
     *
     * Where the visitor is, is the field somebody watches.
     */
    public function testANavigationToANewPageIsRecordedAtOnce(): void
    {
        // Arrange
        unset($_SESSION['_session_written']);
        $probe = new GarbageCollectionProbe();

        // Act & Assert
        $this->assertTrue($probe->wouldRecord('/users', true));
        $this->assertTrue($probe->wouldRecord('/orders', true), 'a new page is recorded');
        $this->assertFalse($probe->wouldRecord('/orders', true), 'the same one is not');
    }

    /**
     * The XHR a page makes does not write a second row a second later.
     *
     * This is the case that made the whole thing visible: loading `/users`
     * writes, and the datatable's call to `/users/data` a second later used to
     * write again — same values but for the URL, and because it ran *after* the
     * page, the row was left naming `/users/data` for a visitor looking at
     * `/users`.
     */
    public function testTheXhrThatFollowsANavigationDoesNotWriteAgain(): void
    {
        // Arrange
        unset($_SESSION['_session_written']);
        $probe = new GarbageCollectionProbe();

        // Act — a page load, then its datatable call
        $this->assertTrue($probe->wouldRecord('/users', true));

        // Assert
        $this->assertFalse(
            $probe->wouldRecord('/users/data', false),
            'the background call had nothing to add'
        );
    }

    /**
     * A background call does still keep the session alive, eventually.
     *
     * A dashboard that polls, or a single-page application, makes nothing but
     * XHR calls. If those never wrote, the visitor would drop off the online
     * list after five minutes while actively using the application — so the
     * timestamp is refreshed once the interval has passed.
     */
    public function testABackgroundCallKeepsTheSessionAliveAfterTheInterval(): void
    {
        // Arrange — the last write was two minutes ago, on a page
        $probe = new GarbageCollectionProbe();
        $_SESSION['_session_written'] = ['at' => time() - 120, 'url' => '/users'];

        // Act
        $recorded = $probe->wouldRecord('/api/notifications', false);

        // Assert
        $this->assertTrue($recorded, 'the visitor is still here');
        $this->assertSame(
            '/users',
            $_SESSION['_session_written']['url'],
            'but the row still names the page they are on, not the API call'
        );
    }

    /**
     * An interval of 0 writes on every request.
     *
     * The old behaviour, for an installation that wants a forced logout to take
     * effect on the very next request rather than within the minute.
     */
    public function testAnIntervalOfZeroRecordsEveryRequest(): void
    {
        // Arrange
        Settings::setSetting('session_write_interval', 0, false);
        unset($_SESSION['_session_written']);
        $probe = new GarbageCollectionProbe();

        // Act & Assert
        $this->assertTrue($probe->wouldRecord('/users'));
        $this->assertTrue($probe->wouldRecord('/users'));
    }

    /**
     * A stale marker lets the next request write again.
     */
    public function testTheIntervalExpires(): void
    {
        // Arrange — a marker from two minutes ago
        $probe = new GarbageCollectionProbe();
        $_SESSION['_session_written'] = ['at' => time() - 120, 'url' => '/users'];

        // Act & Assert
        $this->assertTrue($probe->wouldRecord('/users'));
    }

    /**
     * By default the sweep is occasional, not constant.
     *
     * The assertion is statistical because the choice is random: over a
     * thousand requests at the default divisor of 100, a handful sweep. What
     * would fail here is the old behaviour — every single one.
     */
    public function testTheSweepIsOccasionalByDefault(): void
    {
        // Arrange
        Settings::setSetting('session_gc_divisor', false, false);
        $probe = new GarbageCollectionProbe();

        // Act
        $swept = 0;
        for ($i = 0; $i < 1000; $i++) {
            if ($probe->decide()) {
                $swept++;
            }
        }

        // Assert — around 10 in 1000; the bounds are wide enough that a fair
        // run cannot fail them and the old always-sweep cannot pass them
        $this->assertLessThan(60, $swept, 'the sweep is not running on every request');
        $this->assertGreaterThan(0, $swept, 'but it does still run');
    }

    /**
     * A divisor of 1 restores the old behaviour exactly.
     *
     * For an installation that wants the stale rows gone the moment they are
     * stale, and is willing to pay for it on every request.
     */
    public function testADivisorOfOneSweepsEveryRequest(): void
    {
        // Arrange
        Settings::setSetting('session_gc_divisor', 1, false);
        $probe = new GarbageCollectionProbe();

        // Act & Assert
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($probe->decide());
        }
    }

    /**
     * A divisor of 0 turns the sweep off entirely.
     *
     * The right setting when a scheduled task does the cleanup instead — and
     * the reason it is 0 rather than a separate flag is that an operator
     * reading `session_gc_divisor = 0` can guess what it means.
     */
    public function testADivisorOfZeroNeverSweeps(): void
    {
        // Arrange
        Settings::setSetting('session_gc_divisor', 0, false);
        $probe = new GarbageCollectionProbe();

        // Act & Assert
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($probe->decide());
        }
    }

    /**
     * A nonsensical divisor falls back to the default rather than to zero.
     *
     * A typo in a settings table must not silently switch off cleanup and let
     * the table grow.
     */
    public function testAnUnreadableDivisorFallsBackToTheDefault(): void
    {
        // Arrange
        Settings::setSetting('session_gc_divisor', 'often', false);
        $probe = new GarbageCollectionProbe();

        // Act
        $swept = 0;
        for ($i = 0; $i < 1000; $i++) {
            if ($probe->decide()) {
                $swept++;
            }
        }

        // Assert — it behaves as the default, not as "never"
        $this->assertGreaterThan(0, $swept);
        $this->assertLessThan(60, $swept);
    }
}
