<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\AuthTokenCleanup;
use Pramnos\Scheduling\FrameworkSchedule;
use Pramnos\User\User;

/**
 * A web-session token stops being valid, and something eventually retires it.
 *
 * `createWebSessionToken()` inserts one row per login and gave it no expiry;
 * `loadByToken()` reads 0 and NULL as "never expires"; and `cleanupAllAuthTokens()` —
 * which existed, and had no caller anywhere in the framework — covered only `auth` and
 * `access_token`. So the table grew and nothing in it ever aged.
 *
 * Measured on a two-day-old development installation with a single user: **7,255 rows,
 * all `web_session`, all with no expiry**, arriving at about 230 an hour. That is also
 * the table `tokenactions` points a foreign key at, which is how a buffered write ends up
 * outliving the row it references.
 *
 * Three things had to be true, and none of them was:
 *
 *  1. a new token knows when it stops being valid;
 *  2. the cleanup covers the type that is actually accumulating;
 *  3. something runs the cleanup.
 */
#[CoversClass(User::class)]
class WebSessionTokenLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Settings::clearSettings();
        FrameworkSchedule::reset();
        parent::tearDown();
    }

    /**
     * A web-session token expires a month from now by default.
     *
     * Generous next to the PHP session it belongs to — `session.gc_maxlifetime` is 24
     * minutes out of the box — and short enough that the table stops being append-only.
     *
     * @return void
     */
    public function testTheDefaultLifetimeIsThirtyDays(): void
    {
        // Act + Assert
        $this->assertSame(2592000, User::webSessionLifetime());
    }

    /**
     * An installation can choose its own, including "never".
     *
     * `0` restores tokens that never expire, for an installation that has a reason to
     * want them — but it has to say so, which is the difference from before.
     *
     * @return void
     */
    public function testTheLifetimeIsConfigurable(): void
    {
        // Arrange + Act + Assert
        Settings::setSetting('web_session_lifetime', 3600);
        $this->assertSame(3600, User::webSessionLifetime());

        Settings::setSetting('web_session_lifetime', 0);
        $this->assertSame(0, User::webSessionLifetime(), '0 means no expiry');

        Settings::setSetting('web_session_lifetime', -5);
        $this->assertSame(0, User::webSessionLifetime(), 'a negative lifetime is not a date');
    }

    /**
     * The cleanup covers the type that actually accumulates.
     *
     * Read from the compiled query rather than from a live table: the invariant is which
     * types the statement names, and the integration suites cover the write.
     *
     * @return void
     */
    public function testTheCleanupCoversWebSessions(): void
    {
        // Arrange — a database that records what it is asked to run
        $db = new RecordingCleanupDatabase();
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $original  = $singleton;
        $singleton = $db;

        try {
            // Act
            User::cleanupAllAuthTokens(30);

            // Assert — all three session-bearing types, bound to the statement
            $bound = array_merge(...array_values($db->bindings));
            $this->assertContains('web_session', $bound, 'the type that accumulates');
            $this->assertContains('auth', $bound);
            $this->assertContains('access_token', $bound);
        } finally {
            $singleton = $original;
        }
    }

    /**
     * A caller can still ask for a subset.
     *
     * The parameter exists so that widening the default does not take away the ability
     * to retire only API tokens — an application that manages its own sessions.
     *
     * @return void
     */
    public function testTheTypesCanBeNarrowed(): void
    {
        // Arrange
        $db = new RecordingCleanupDatabase();
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $original  = $singleton;
        $singleton = $db;

        try {
            // Act
            User::cleanupAllAuthTokens(30, ['auth']);

            // Assert
            $bound = array_merge(...array_values($db->bindings));
            $this->assertContains('auth', $bound);
            $this->assertNotContains('web_session', $bound);
        } finally {
            $singleton = $original;
        }
    }

    /**
     * The framework schedules the cleanup, so an installation does not have to.
     *
     * Point 3, and the one the other two are worth nothing without: the method existed
     * before today and had no caller anywhere in the framework.
     *
     * @return void
     */
    public function testTheCleanupIsScheduled(): void
    {
        // Act + Assert
        $this->assertContains('auth:token-cleanup', FrameworkSchedule::commands());
    }

    /**
     * An application with no token table is not a failure.
     *
     * The schedule runs on every installation, including ones that never enabled auth.
     * A daily red line in `schedule.log` for a table that was never meant to exist is
     * how a log stops being read.
     *
     * @return void
     */
    public function testAMissingTableIsNotReportedAsAFailure(): void
    {
        // Arrange
        $command = new AuthTokenCleanup();
        $check   = new \ReflectionMethod(AuthTokenCleanup::class, 'looksLikeMissingTable');

        // Act + Assert — both drivers' wording
        $this->assertTrue($check->invoke(
            $command,
            new \RuntimeException("Table 'app.usertokens' doesn't exist")
        ));
        $this->assertTrue($check->invoke(
            $command,
            new \RuntimeException('ERROR: relation "usertokens" does not exist')
        ));
        // ...and a real failure still is one
        $this->assertFalse($check->invoke(
            $command,
            new \RuntimeException('deadlock detected')
        ));
    }
}

/**
 * A database that compiles the query builder normally and records what it would run.
 */
class RecordingCleanupDatabase extends \Pramnos\Database\Database
{
    public $type      = 'mysql';
    public $connected = true;
    public $prefix    = '';

    /**
     * Not `$statements` — `Database` uses that name for its prepared-statement
     * registry, and its destructor walks it expecting arrays.
     *
     * @var array<int, string>
     */
    public array $statementsRun = [];

    /** @var array<int, array<int, mixed>> */
    public array $bindings = [];

    public function __construct() {}

    public function execute($sql, &...$arguments)
    {
        $this->statementsRun[] = (string) $sql;
        $this->bindings[]   = $arguments;

        return true;
    }

    public function cacheflush($category = "")
    {
        return true;
    }
}
