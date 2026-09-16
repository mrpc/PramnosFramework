<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * The session variables `setTrackingInfo()` writes, read back from the server.
 *
 * **Nothing in the suite asserted that this method emits valid SQL**, and the failure mode
 * is why: `@pg_query()` suppresses the warning, the `false` return is not read, and the
 * caller's `try`/`catch` therefore cannot fire. The only record was the PostgreSQL server
 * log.
 *
 * What hid there: the null branch emitted `SET app.userid = NULL`, which is not PostgreSQL
 * grammar — `SET` takes a value, `DEFAULT`, or nothing. `Api::execute()` passes an explicit
 * null for every anonymous request, so **every unauthenticated request raised a server-side
 * syntax error**. On the installation that reported it that was about 430,000 a day, 48% of
 * a 376 MB log, for three and a half days. And `app.userid` — the variable the whole
 * mechanism exists for — was then never set on exactly the anonymous traffic somebody would
 * be reading the log to chase.
 *
 * So these tests read the variables back with `current_setting()`. Asserting on the SQL
 * string would have passed against the broken version, which is the entire point: the
 * server is the only thing that knows whether the statement was grammar.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[CoversClass(Database::class)]
class TrackingInfoPostgreSQLTest extends TestCase
{
    private ?Database $db = null;

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        /*
         * `$_SERVER` is process-wide and the method reads four keys out of it, so what an
         * earlier class left there decides what this one asserts. The first version of
         * this file did not control it and passed alone while failing in a full run: an
         * inherited `REMOTE_ADDR` appends itself to `application_name`.
         */
        $this->server = $_SERVER;
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';

        try {
            $db->connect(true);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('TimescaleDB container not reachable: ' . $exception->getMessage());
        }

        $this->db = $db;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $this->db = null;
        parent::tearDown();
    }

    /** What the server says a session variable currently holds. */
    private function setting(string $name): ?string
    {
        $result = $this->db->query(
            "SELECT current_setting('" . $name . "', true) AS value"
        );

        return $result && $result->numRows > 0 ? $result->fields['value'] : null;
    }

    /**
     * An anonymous request gets `guest`, not a syntax error.
     *
     * This is the exact shape `Api::execute()` produces — `$userdata['userid'] =
     * $currentUser?->userid ?? null` — and the one that emitted the error. The assertion is
     * `'guest'` rather than "no error", because the `?? 'guest'` fallback in the defaults
     * array was correct all along and simply unreachable: the merge loop put the caller's
     * explicit null back over it.
     */
    public function testAnAnonymousRequestRecordsGuestRatherThanFailing(): void
    {
        // Act — an explicit null userid, in both the argument and the data array.
        $this->db->setTrackingInfo(null, 'TestApp', ['userid' => null]);

        // Assert
        $this->assertSame('guest', $this->setting('app.userid'));
    }

    /**
     * A real user id is recorded as itself.
     *
     * The counterpart, and worth having beside the one above: a fix that made the
     * anonymous case work by always writing `'guest'` would pass that test and be useless.
     */
    public function testASignedInRequestRecordsTheUserId(): void
    {
        // Act
        $this->db->setTrackingInfo(4242, 'TestApp', ['userid' => 4242]);

        // Assert
        $this->assertSame('4242', $this->setting('app.userid'));
    }

    /**
     * A null for some other key leaves that variable unset, rather than empty.
     *
     * `current_setting(name, true)` answers null for a variable that was never set and the
     * empty string for one set to it, and those are different answers to "was there a
     * tenant". The old code reached for the second and got neither: the statement was
     * refused, so the variable was never set — which is the behaviour kept here, minus the
     * error.
     */
    public function testANullForAnotherKeyLeavesItUnset(): void
    {
        // Act
        $this->db->setTrackingInfo(1, 'TestApp', ['userid' => 1, 'tenant' => null]);

        // Assert
        $this->assertNull($this->setting('app.tenant'));
        // …and it did not take the rest of the variables down with it.
        $this->assertSame('1', $this->setting('app.userid'));
    }

    /**
     * A value the caller supplies is recorded verbatim, quotes included.
     *
     * The old statement interpolated the value into SQL after `pg_escape_string()`; the
     * name was interpolated with nothing at all. Both are parameters now, so this asserts
     * the thing that would break if somebody put the interpolation back for readability.
     */
    public function testAValueWithQuotesSurvivesIntact(): void
    {
        // Arrange — the shape that ends a single-quoted literal early.
        $awkward = "O'Brien's ';DROP";

        // Act
        $this->db->setTrackingInfo(7, 'TestApp', ['userid' => 7, 'label' => $awkward]);

        // Assert
        $this->assertSame($awkward, $this->setting('app.label'));
    }

    /**
     * `application_name` is set, and is what `pg_stat_activity` will show.
     *
     * It carries a slice of `REMOTE_ADDR`, so it is the other place a value the application
     * does not fully control reaches a statement.
     */
    public function testTheApplicationNameIsRecorded(): void
    {
        // Arrange — an address that is not loopback, so the suffix is exercised rather
        // than skipped. setUp pins REMOTE_ADDR precisely because this depends on it.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        // Act
        $this->db->setTrackingInfo(88, 'Reporting Service', []);

        // Assert — spaces stripped and title-cased, then the user and the caller.
        $this->assertSame('ReportingService_u88_203.0.113.9', $this->setting('application_name'));
    }

    /**
     * Loopback is left off the name.
     *
     * A development machine would otherwise label every connection `_127.0.0.1`, which is
     * noise in the one column that exists to tell connections apart.
     */
    public function testLoopbackIsNotAppendedToTheApplicationName(): void
    {
        // Act — setUp pinned REMOTE_ADDR to loopback.
        $this->db->setTrackingInfo(null, 'Reporting', []);

        // Assert
        $this->assertSame('Reporting', $this->setting('application_name'));
    }
}
