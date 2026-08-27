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

    /**
     * A new web-session token retires the one it replaces.
     *
     * One sign-in mints one token, and nothing used to end the previous one: a browser
     * that signed in twice left two rows marked **Active**, from the same address, for the
     * thirty days of their lifetime.
     *
     * Two problems in one row. A list of "active sessions" stops meaning anything — three
     * rows could be three devices or one browser that re-authenticated three times, which
     * is the question that list exists to answer. And a token no session cookie can reach
     * is still a valid credential, because `loadByToken()` takes the raw value: a copy in
     * a log or an old client keeps working for a month.
     *
     * Asserted on the source, because the alternative is a full login against a live
     * `usertokens` table, and what has to hold is that the replacement happens **before**
     * the insert — a token retired afterwards would take the new one with it when the
     * device fingerprint matches, which is exactly the same-browser case.
     */
    public function testANewWebSessionTokenRetiresTheOneItReplaces(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/User/User.php'
        );

        // Act
        $create  = strpos($source, 'public function createWebSessionToken');
        $retire  = strpos($source, '$this->retireSupersededWebSessionTokens();', (int) $create);
        $insert  = strpos($source, '$this->addToken(', (int) $create);

        // Assert
        $this->assertIsInt($retire, 'creating a token must retire what it supersedes');
        $this->assertLessThan(
            $insert,
            $retire,
            'the old token is retired before the new one is written, or the same '
            . 'fingerprint match would retire the new one too'
        );
    }

    /**
     * It retires this session's token and this device's, and nothing else.
     *
     * Signing in on a laptop must not sign you out on a phone — that is the whole point of
     * having more than one session. So the match is the session's own token id, and
     * failing that the device fingerprint; never "every token this user has".
     */
    public function testItRetiresOnlyThisSessionAndThisDevice(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/User/User.php'
        );
        $start  = (int) strpos($source, 'private function retireSupersededWebSessionTokens');
        $body   = substr($source, $start, 2600);

        // Assert — scoped to the session's token and to this device
        $this->assertStringContainsString("where('tokenid'", $body);
        $this->assertStringContainsString("where('deviceinfo', $device)", $body);
        // …and to web sessions only: an access token is not superseded by a login
        $this->assertStringContainsString('Token::TYPE_WEB_SESSION', $body);
        // …and it marks them inactive rather than deleting the history
        $this->assertStringContainsString("'status' => 0", $body);
        $this->assertStringNotContainsString('->delete()', $body);
    }
}
